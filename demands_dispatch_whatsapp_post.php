<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// ============================================================================
// DISPARO DE CAPTAÇÃO — DOIS MODOS DE EXECUÇÃO
// ----------------------------------------------------------------------------
// Este arquivo é ao mesmo tempo:
//   1) ENFILEIRADOR (modo navegador): quando o atendente clica em "Realizar
//      Captação", faz apenas as validações rápidas, cria um "run" de progresso,
//      enfileira o trabalho pesado em background (integration_jobs) e responde
//      JSON {ok, run_id} imediatamente — sem travar a tela nem estourar timeout.
//   2) EXECUTOR (modo worker): o cron (cron/integration_jobs_run.php) inclui
//      este arquivo definindo $GLOBALS['__dispatch_worker']=true e
//      $GLOBALS['__dispatch_run_id']=<id>. Nesse modo, TODO o corpo pesado
//      abaixo roda de fato (resolve instância, cria/acha grupo, sincroniza
//      membros, envia a mensagem), reportando progresso no run.
//
// O corpo pesado é IDÊNTICO nos dois casos; só muda quem o aciona e como as
// saídas de erro/sucesso são comunicadas (JSON/flash x atualização do run).
// ============================================================================

$DISPATCH_WORKER_MODE = !empty($GLOBALS['__dispatch_worker']);
$DISPATCH_RUN_ID = (int)($GLOBALS['__dispatch_run_id'] ?? 0);

/**
 * Exceção de CONTROLE usada apenas no MODO WORKER para encerrar o disparo sem
 * chamar exit(). Como este arquivo é incluído pelo cron (que processa vários
 * jobs em sequência), um exit() mataria o loop do cron. O worker captura esta
 * exceção e segue para o próximo job. NÃO indica erro por si só: o run já foi
 * finalizado (completed/failed) antes de lançá-la.
 */
if (!class_exists('DispatchWorkerDone')) {
    class DispatchWorkerDone extends RuntimeException {}
}

// As funções abaixo são protegidas com function_exists porque o cron pode
// incluir este arquivo mais de uma vez na mesma execução (um require por job
// demand_dispatch_run). As declarações só podem ocorrer uma vez; o CORPO
// procedural (mais abaixo) reexecuta a cada include, como desejado.
if (!function_exists('dispatch_stop')) {
    /**
     * Encerra o fluxo do disparo de forma apropriada ao modo:
     * - Worker: lança DispatchWorkerDone (não mata o cron).
     * - Navegador: exit() normal.
     */
    function dispatch_stop(): void
    {
        if (!empty($GLOBALS['__dispatch_worker'])) {
            throw new DispatchWorkerDone('dispatch worker done');
        }
        exit;
    }
}

if (!function_exists('dispatch_wants_json')) {
    /**
     * Detecta se a requisição do navegador espera JSON (chamada via fetch).
     */
    function dispatch_wants_json(): bool
    {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest' || $xrw === 'fetch') {
            return true;
        }
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return strpos($accept, 'application/json') !== false;
    }
}

if (!function_exists('dispatch_evolution_error_text')) {
    /**
     * Extrai a mensagem de erro REAL devolvida pela Evolution API.
     * A Evolution embrulha erros do Baileys (ex.: instância não é membro do
     * grupo, "forbidden", "item-not-found", "exists:false") em HTTP 400 — sem
     * isto o log do disparo mostrava só "HTTP 400", sem o motivo.
     */
    function dispatch_evolution_error_text(array $res): string
    {
        $status = (int)($res['status'] ?? 0);
        $json = $res['json'] ?? null;
        $detail = '';
        if (is_array($json)) {
            $m = $json['response']['message'] ?? ($json['message'] ?? ($json['error'] ?? null));
            if (is_array($m)) {
                $parts = [];
                foreach ($m as $item) {
                    $parts[] = is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_UNICODE);
                }
                $detail = implode('; ', $parts);
            } elseif (is_scalar($m)) {
                $detail = (string)$m;
            }
            if ($detail === '') {
                $detail = (string)json_encode($json, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $detail = trim((string)($res['body_raw'] ?? ''));
        }
        return 'HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '');
    }
}

if (!function_exists('dispatch_instance_state')) {
    /** Estado normalizado da instância na Evolution ('open', 'close', 'connecting'...). */
    function dispatch_instance_state(EvolutionApiV1 $api): string
    {
        // Cache curto por instância: o disparo verificava o estado da MESMA instância
        // várias vezes (escolha do remetente, cada envio, cada adição) — com a
        // Evolution lenta, isso somava minutos.
        static $cache = [];
        $instKey = method_exists($api, 'getInstance') ? (string)$api->getInstance() : '';
        if ($instKey !== '' && isset($cache[$instKey]) && (time() - $cache[$instKey][1]) < 20) {
            return $cache[$instKey][0];
        }
        try {
            $r = $api->connectionState();
        } catch (Throwable $e) {
            // Sem resposta no tempo limite = trata como indisponível (não trava o disparo).
            $r = ['json' => ['state' => 'timeout']];
            if (function_exists('whatsapp_health_record') && $instKey !== '') {
                whatsapp_health_record($instKey, 'send_error', null, 'connectionState sem resposta: ' . $e->getMessage());
            }
        }
        $j = is_array($r['json'] ?? null) ? $r['json'] : [];
        $state = strtolower(trim((string)($j['instance']['state'] ?? ($j['state'] ?? ''))));
        if ($instKey !== '') {
            $cache[$instKey] = [$state, time()];
        }
        // Histórico de estados (detecção de conexão em loop).
        if (function_exists('whatsapp_health_record') && method_exists($api, 'getInstance')) {
            whatsapp_health_record((string)$api->getInstance(), 'probe', whatsapp_health_normalize_state($state));
        }
        return $state;
    }
}

if (!function_exists('dispatch_pick_sender_for_group')) {
    /**
     * Escolhe uma instância que REALMENTE consegue enviar para o grupo:
     * estado 'open' + enxerga o grupo via findGroupInfos (que consulta o
     * WhatsApp ao vivo, não o cache).
     *
     * Por que isso é necessário: o cache de metadados de grupo da Evolution é
     * global (chave = JID do grupo, compartilhado entre instâncias). Uma
     * instância desconectada/não autenticada passa pela checagem "Group not
     * found" usando o cache populado por OUTRA instância e quebra dentro do
     * Baileys em `authState.creds.me.id` -> "TypeError: Cannot read properties
     * of undefined (reading 'id')" (HTTP 400).
     *
     * @return array{0: EvolutionApiV1, 1: string}|null
     */
    function dispatch_pick_sender_for_group(string $groupJid, ?string $preferred, array $instances, string $baseUrl, string $apiKey, array &$diag = []): ?array
    {
        // A mesma escolha é pedida várias vezes no disparo (remetente, adição de
        // membros, cada envio): reaproveita por 60 s em vez de consultar tudo de novo.
        static $picked = [];
        if (isset($picked[$groupJid]) && (time() - $picked[$groupJid][1]) < 60) {
            $cachedName = $picked[$groupJid][0];
            return [new EvolutionApiV1($baseUrl, $apiKey, $cachedName), $cachedName];
        }
        $names = [];
        if ($preferred !== null && $preferred !== '') {
            $names[] = $preferred;
        }
        foreach ($instances as $i) {
            $n = (string)($i['instance_name'] ?? '');
            if ($n !== '') {
                $names[] = $n;
            }
        }
        $def = (string)admin_setting_get('evolution.instance', '');
        if ($def !== '') {
            $names[] = $def;
        }
        $names = array_values(array_unique($names));

        foreach ($names as $name) {
            try {
                // Instância em LOOP / conflito / deslogada: mesmo dizendo "open", não envia.
                if (function_exists('whatsapp_health_status')) {
                    $health = whatsapp_health_status($name);
                    if (whatsapp_health_is_blocking($health['level'])) {
                        $diag[] = "$name: " . $health['title'] . ' — ' . $health['message'] . ' O que fazer: ' . implode(' ', $health['actions']);
                        continue;
                    }
                }
                $candidate = new EvolutionApiV1($baseUrl, $apiKey, $name);
                $state = dispatch_instance_state($candidate);
                if (!in_array($state, ['open', 'connected'], true)) {
                    $diag[] = "$name: estado '" . ($state !== '' ? $state : '?') . "'";
                    continue;
                }
                $info = $candidate->findGroupByJid($groupJid);
                $code = (int)($info['status'] ?? 0);
                if ($code < 200 || $code >= 300) {
                    $diag[] = "$name: conectada mas não está no grupo (HTTP $code)";
                    continue;
                }
                $picked[$groupJid] = [$name, time()];
                return [$candidate, $name];
            } catch (Throwable $e) {
                $diag[] = "$name: " . $e->getMessage();
            }
        }
        return null;
    }
}

// dispatch_member_key() e dispatch_group_member_keys() ficam em
// app/demand_dispatch_batch.php (usadas também pelo cron de adição em lote).

if (!function_exists('dispatch_instance_number')) {
    /** Número (só dígitos) do WhatsApp conectado na instância, se cadastrado. */
    function dispatch_instance_number(?string $instanceName): string
    {
        if ($instanceName === null || $instanceName === '') return '';
        try {
            $st = db()->prepare('SELECT owner_number, owner_phone_formatted FROM whatsapp_instances WHERE instance_name = ? LIMIT 1');
            $st->execute([$instanceName]);
            $row = $st->fetch();
            if (!$row) return '';
            $n = trim((string)($row['owner_phone_formatted'] ?? ''));
            if ($n === '') $n = (string)($row['owner_number'] ?? '');
            return preg_replace('/\D+/', '', $n);
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('dispatch_missing_members')) {
    /**
     * Filtra $jids deixando só quem AINDA NÃO está no grupo (e nunca o próprio
     * número da instância). Re-adicionar membros existentes / o próprio número
     * derruba a sessão do WhatsApp na Evolution — por isso, se não der para ler
     * a lista de membros, retorna [] (não adiciona ninguém).
     */
    function dispatch_missing_members(EvolutionApiV1 $api, string $groupJid, array $jids, string $selfNumber = ''): array
    {
        $present = dispatch_group_member_keys($api, $groupJid);
        if ($present === null) {
            error_log("[DISPATCH] Não foi possível ler membros de $groupJid — adição ignorada por segurança.");
            return [];
        }
        $self = $selfNumber !== '' ? dispatch_member_key($selfNumber) : '';
        $out = [];
        foreach ($jids as $j) {
            $k = dispatch_member_key((string)$j);
            if ($k === '' || $k === $self || isset($present[$k])) continue;
            $out[$k] = (string)$j;
        }
        return array_values($out);
    }
}

if (!function_exists('dispatch_find_sent_by_token')) {
    /**
     * Procura no histórico do grupo (Evolution) a mensagem com o token da
     * captação (#CAP...). Usado quando o envio deu timeout do nosso lado: a
     * Evolution continua processando e a mensagem pode ter saído normalmente.
     * Retorna o id da mensagem (key.id) se encontrada, '' se encontrada sem id,
     * ou null se não encontrada.
     */
    function dispatch_find_sent_by_token(EvolutionApiV1 $api, string $groupJid, string $token): ?string
    {
        if ($token === '') return null;
        $r = $api->findMessages($groupJid);
        $j = $r['json'] ?? null;
        if (!is_array($j)) return null;
        $records = $j['messages']['records'] ?? ($j['records'] ?? (isset($j[0]) ? $j : []));
        if (!is_array($records)) return null;
        foreach ($records as $m) {
            if (!is_array($m)) continue;
            $msg = $m['message'] ?? [];
            $txt = (string)($msg['conversation'] ?? ($msg['extendedTextMessage']['text'] ?? ''));
            if ($txt !== '' && strpos($txt, $token) !== false) {
                return (string)($m['key']['id'] ?? '');
            }
        }
        return null;
    }
}

if (!function_exists('dispatch_abort')) {
    /**
     * Encerra o disparo com ERRO.
     * - Worker: marca o run como 'failed' com a mensagem e encerra (sem exit).
     * - Navegador (fetch): responde JSON {ok:false,error}.
     * - Navegador (form legado sem JS): flash + redirect (comportamento antigo).
     */
    function dispatch_abort(string $message): void
    {
        global $DISPATCH_WORKER_MODE, $DISPATCH_RUN_ID, $id;

        if ($DISPATCH_WORKER_MODE) {
            demand_dispatch_run_finish($DISPATCH_RUN_ID, 'failed', $message);
            dispatch_stop();
        }

        if (dispatch_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $message]);
            exit;
        }

        flash_set('error', $message);
        header('Location: /demands_view.php?id=' . (int)($id ?? 0));
        exit;
    }
}

if ($DISPATCH_WORKER_MODE) {
    // ---- MODO WORKER (chamado pelo cron) ----
    // O disparo é longo (chamadas HTTP à Evolution + eventuais reconexões).
    // Sem isto, o PHP é morto por max_execution_time e o cron fetch-url do Plesk
    // derruba o processo ao desconectar — deixando o job preso em 'running' e o
    // run preso em 'sending'. Garante tempo e continuidade mesmo sem o cliente.
    @set_time_limit(0);
    @ignore_user_abort(true);

    // Sem sessão HTTP: injeta o usuário do run para que auth_user_id() funcione
    // nas gravações de autoria (assumed_by, status logs, dispatch logs).
    $runRow = db()->prepare('SELECT demand_id, sub_request_id, created_by_user_id FROM demand_dispatch_runs WHERE id = :id');
    $runRow->execute(['id' => $DISPATCH_RUN_ID]);
    $runData = $runRow->fetch();
    if (!$runData) {
        // Nada a fazer: run inexistente.
        return;
    }
    $id = (int)$runData['demand_id'];
    $subRequestId = (int)($runData['sub_request_id'] ?? 0);
    if (!empty($runData['created_by_user_id'])) {
        $_SESSION['auth_user_id'] = (int)$runData['created_by_user_id'];
    }
} else {
    // ---- MODO NAVEGADOR (clique do atendente) ----
    auth_require_login();
    rbac_require_permission('demands.manage');

    $id = (int)($_POST['id'] ?? 0);
    $subRequestId = (int)($_POST['sub_request_id'] ?? 0);
}

// MODO DE TESTE DA CAPTAÇÃO: quando ativo, só adiciona profissionais marcados como teste.
// Garante a coluna (fallback) e define o filtro SQL adicional aplicado às queries de profissionais.
try { db()->exec("ALTER TABLE users ADD COLUMN is_test_professional TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
// Fallback: garante a coluna que vincula o grupo à instância que o criou.
try { db()->exec("ALTER TABLE whatsapp_groups ADD COLUMN instance_name VARCHAR(100) NULL"); } catch (Throwable $e) {}
$captacaoTestMode = ((string)admin_setting_get('feature.captacao_test_mode', '0') === '1');
$testModeSqlFilter = $captacaoTestMode ? ' AND u.is_test_professional = 1' : '';

// Instância do captador (usuário que está realizando a captação). O grupo deve
// existir no WhatsApp de quem prospecta, então priorizamos a instância dele.
require_once __DIR__ . '/app/whatsapp_instances.php';
$captadorInstance = whatsapp_get_user_instance(auth_user_id());
$captadorInstanceName = $captadorInstance['instance_name'] ?? '';

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    dispatch_abort('Demanda não encontrada.');
}

// SIGILO (Ajuste 8): se a demanda está em recaptação sigilosa, NÃO disparar em grupo
// (o profissional atual poderia estar no grupo). A recaptação sigilosa usa mensagem
// direta via demands_recaptar_sigiloso_post.php.
if (array_key_exists('is_confidential', $d) && (int)$d['is_confidential'] === 1) {
    dispatch_abort('Esta captação é SIGILOSA e não pode ser disparada em grupo. Use "Recaptar sigiloso" (mensagem direta) na tela de substituição.');
}

// Se foi selecionada uma sub-solicitação, usar os dados dela
$subRequest = null;
if ($subRequestId > 0) {
    $stmtSub = db()->prepare('SELECT * FROM demand_sub_requests WHERE id = :id AND demand_id = :did');
    $stmtSub->execute(['id' => $subRequestId, 'did' => $id]);
    $subRequest = $stmtSub->fetch();
    if (!$subRequest) {
        dispatch_abort('Sub-solicitação não encontrada.');
    }
    if ($subRequest['status'] === 'em_captacao' || $subRequest['status'] === 'concluido') {
        dispatch_abort('Esta sub-solicitação já está em captação ou concluída.');
    }
}

// ============================================================================
// BIFURCAÇÃO: no MODO NAVEGADOR, as validações rápidas acima já passaram.
// Agora apenas ENFILEIRAMOS o trabalho pesado em background e respondemos na
// hora. O corpo pesado (abaixo) só roda no MODO WORKER.
// ============================================================================
if (!$DISPATCH_WORKER_MODE) {
    // Validação RÁPIDA antes de enfileirar: sem especialidade/cidade/UF (e zona em
    // SP/RJ) não há como escolher o grupo certo. Avisa na hora, sem esperar a fila.
    $checkData = $d;
    if ($subRequest) {
        foreach (['specialty', 'location_city', 'location_state', 'location_street', 'location_neighborhood'] as $k) {
            if (trim((string)($subRequest[$k] ?? '')) !== '') {
                $checkData[$k] = $subRequest[$k];
            }
        }
    }
    $missingCheck = demand_missing_fields($checkData);
    if ($missingCheck['blocking']) {
        dispatch_abort('Complete o card antes de captar. Falta: ' . implode(', ', $missingCheck['blocking']) . '.');
    }

    // Evita dois disparos simultâneos do mesmo card (duplo clique / duas abas).
    try {
        demand_dispatch_ensure_run_columns();
        $dupStmt = db()->prepare("SELECT id FROM demand_dispatch_runs WHERE demand_id = :d AND COALESCE(sub_request_id, 0) = :s
            AND group_jid = '' AND status IN ('queued','processing') AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY id DESC LIMIT 1");
        $dupStmt->execute(['d' => (int)$id, 's' => (int)$subRequestId]);
        $runningId = (int)($dupStmt->fetchColumn() ?: 0);
        if ($runningId > 0 && dispatch_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'run_id' => $runningId, 'message' => 'Esta captação já está em andamento.']);
            exit;
        }
    } catch (Throwable $e) { /* segue */ }

    try {
        $newRunId = demand_dispatch_enqueue_full((int)$id, (int)$subRequestId, auth_user_id());
    } catch (Throwable $e) {
        dispatch_abort('Falha ao enfileirar a captação: ' . $e->getMessage());
    }

    if (dispatch_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'run_id' => $newRunId,
            'message' => 'Captação iniciada em segundo plano.',
        ]);
        exit;
    }

    // Fallback sem JS: volta para a tela com aviso.
    flash_set('success', 'Captação iniciada em segundo plano. Acompanhe o progresso na tela.');
    header('Location: /demands_view.php?id=' . (int)$id);
    exit;
}

// ============================================================================
// A PARTIR DAQUI: MODO WORKER — execução do trabalho pesado em background.
// ============================================================================
demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'starting', 'Iniciando disparo da captação...');

// Colunas de zona/localização (fora de transação: DDL faz commit implícito).
location_users_ensure_schema();

// Usar dados da sub-solicitação se disponível, senão usar dados da demanda.
// (trim + fallback quando a coluna da sub-solicitação está vazia)
$pickLoc = static function (string $col) use ($subRequest, $d): string {
    $sv = $subRequest ? trim((string)($subRequest[$col] ?? '')) : '';
    return $sv !== '' ? $sv : trim((string)($d[$col] ?? ''));
};
$city = $pickLoc('location_city');
$state = strtoupper($pickLoc('location_state'));
$specialty = $subRequest ? trim((string)($subRequest['specialty'] ?? '')) : trim((string)($d['specialty'] ?? ''));
$neighborhoodForZone = $pickLoc('location_neighborhood');

// ZONA: em cidades divididas por zona (São Paulo, Rio de Janeiro) o grupo é por zona.
$zone = null;
if (location_city_has_zones($city, $state)) {
    $zone = location_normalize_zone((string)($d['location_zone'] ?? ''), $city, $state)
        ?? location_zone_for($city, $state, $neighborhoodForZone);
}

// Sem especialidade/cidade/UF (ou zona em SP/RJ) não dá para escolher o grupo certo.
// Antes o sistema caía no primeiro grupo da especialidade (ex.: "São Paulo") para
// qualquer cidade; agora interrompe com o motivo.
$missingWorker = [];
if ($specialty === '') $missingWorker[] = 'especialidade';
if ($city === '') $missingWorker[] = 'cidade';
if (!preg_match('/^[A-Z]{2}$/', $state)) $missingWorker[] = 'UF';
if (location_city_has_zones($city, $state) && $zone === null) $missingWorker[] = 'zona (informe o bairro ou escolha a zona)';
if ($missingWorker) {
    dispatch_abort('Card incompleto para captação. Falta: ' . implode(', ', $missingWorker) . '.');
}

$locationLabel = dispatch_group_location_label($city, $state, $zone);

// Membros que devem estar no grupo ANTES do envio (jid do grupo => lista de JIDs).
// O envio só acontece depois de adicionar quem falta, para que os profissionais
// novos também recebam a mensagem da captação.
$memberTargets = [];
$memberStats = ['users' => 0, 'contacts' => 0, 'skipped_location' => 0, 'unknown_location' => 0];

// Buscar grupo compatível: especialidade + cidade + UF (+ zona). Nunca reaproveita
// grupo de outra cidade.
$groups = [];
$foundGroup = dispatch_find_group($specialty, $city, $state, $zone, $captadorInstanceName);
if ($foundGroup !== null) {
    $groups = [$foundGroup];
    error_log("[DISPATCH] Grupo encontrado para '$specialty' em '$locationLabel': " . $foundGroup['name'] . ' (' . $foundGroup['evolution_group_jid'] . ')');
}

if (count($groups) === 0) {
    // NENHUM GRUPO ENCONTRADO — Criar automaticamente
    demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'creating_group', 'Nenhum grupo encontrado — resolvendo instância e criando grupo...');
    error_log("[DISPATCH] Nenhum grupo encontrado para spec='$specialty' city='$city' state='$state'. Criando automaticamente...");
    
    try {
        // MULTI-INSTÂNCIA: Encontrar uma instância conectada para criar o grupo
        $baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
        $apiKey = (string)admin_setting_get('evolution.api_key', '');
        $instanceName = '';
        
        // Buscar todas as instâncias ativas, priorizando as conectadas.
        // A instância do CAPTADOR (quem prospecta) vem primeiro, para que o grupo
        // seja criado no WhatsApp dele.
        $allInstForGroup = db()->prepare("
            SELECT instance_name, connection_status FROM whatsapp_instances 
            WHERE status = 'active' 
            ORDER BY 
                CASE WHEN instance_name = :captador THEN 0 ELSE 1 END ASC,
                CASE WHEN connection_status = 'connected' THEN 0 ELSE 1 END ASC,
                is_default DESC, 
                id ASC
        ");
        $allInstForGroup->execute(['captador' => $captadorInstanceName]);
        $instRows = $allInstForGroup->fetchAll(PDO::FETCH_ASSOC);
        $skippedUnhealthy = [];
        
        foreach ($instRows as $instRow) {
            $instCandidate = (string)($instRow['instance_name'] ?? '');
            if ($instCandidate === '') continue;
            // Instância em loop/conflito/deslogada: nem tenta (era onde o disparo travava).
            $candHealth = whatsapp_health_status($instCandidate);
            if (whatsapp_health_is_blocking($candHealth['level'])) {
                error_log("[DISPATCH] Instância '$instCandidate' ignorada: " . $candHealth['title']);
                $skippedUnhealthy[] = "$instCandidate: " . $candHealth['title'];
                continue;
            }
            demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'creating_group', "Nenhum grupo para $locationLabel — verificando o WhatsApp '$instCandidate'...");
            try {
                $tryApi = new EvolutionApiV1($baseUrl, $apiKey, $instCandidate);
                try {
                    $connStateRes = $tryApi->connectionState();
                } catch (Throwable $csErr) {
                    error_log("[DISPATCH] Instância '$instCandidate' não respondeu ao connectionState: " . $csErr->getMessage());
                    whatsapp_health_record($instCandidate, 'send_error', null, 'connectionState sem resposta: ' . $csErr->getMessage());
                    $skippedUnhealthy[] = "$instCandidate: não respondeu (Evolution travada?)";
                    continue;
                }
                $connJson = $connStateRes['json'] ?? [];
                $connHttpCode = (int)($connStateRes['status'] ?? 0);
                
                // Extrair estado da conexão - múltiplos formatos possíveis da Evolution API
                $connInstanceState = '';
                if (isset($connJson['instance']['state'])) {
                    $connInstanceState = (string)$connJson['instance']['state'];
                } elseif (isset($connJson['state'])) {
                    $connInstanceState = (string)$connJson['state'];
                } elseif (isset($connJson['instance']['status'])) {
                    $connInstanceState = (string)$connJson['instance']['status'];
                } elseif (isset($connJson['status']) && is_string($connJson['status'])) {
                    $connInstanceState = (string)$connJson['status'];
                }
                
                $connInstanceStateLower = strtolower(trim($connInstanceState));
                error_log("[DISPATCH] Instância '$instCandidate': connectionState HTTP=$connHttpCode, state='$connInstanceState'");
                
                if (in_array($connInstanceStateLower, ['open', 'connected'], true)) {
                    $instanceName = $instCandidate;
                    error_log("[DISPATCH] Instância conectada para criação de grupo: $instanceName");
                    break;
                }
                
                // Se estado é 'close', tentar reconectar via /instance/connect (sem QR, apenas restart de sessão)
                // No MODO WORKER evitamos a reconexão com sleep (até 10s POR instância):
                // com várias instâncias desconectadas isso estoura o tempo e trava a
                // fase 'creating_group'. Apenas pulamos instâncias não conectadas.
                if (!$DISPATCH_WORKER_MODE && ($connInstanceStateLower === 'close' || $connInstanceStateLower === 'closed')) {
                    error_log("[DISPATCH] Instância '$instCandidate' com estado '$connInstanceState' — tentando reconectar...");
                    try {
                        // Tentar connect (reconecta sessão existente se ainda válida)
                        $connectRes = $tryApi->connectInstance($instCandidate);
                        $connectCode = (int)($connectRes['status'] ?? 0);
                        $connectJson = $connectRes['json'] ?? [];
                        error_log("[DISPATCH] Connect da instância '$instCandidate': HTTP $connectCode");
                        
                        // Se retornou QR code, a sessão expirou e precisa escanear novamente
                        $hasQr = isset($connectJson['base64']) || isset($connectJson['code']) || isset($connectJson['qrcode']);
                        if ($hasQr) {
                            error_log("[DISPATCH] Instância '$instCandidate' requer QR Code - sessão expirada");
                            // Atualizar status no banco para refletir realidade
                            whatsapp_update_connection_status($instCandidate, 'disconnected');
                            continue;
                        }
                        
                        // Aguardar reconexão (até 10 segundos)
                        $reconnected = false;
                        for ($attempt = 0; $attempt < 5; $attempt++) {
                            sleep(2);
                            $recheckRes = $tryApi->connectionState();
                            $recheckJson = $recheckRes['json'] ?? [];
                            $recheckState = strtolower(trim((string)($recheckJson['instance']['state'] ?? ($recheckJson['state'] ?? ''))));
                            error_log("[DISPATCH] Tentativa " . ($attempt + 1) . " de reconexão '$instCandidate': state='$recheckState'");
                            
                            if (in_array($recheckState, ['open', 'connected'], true)) {
                                $reconnected = true;
                                break;
                            }
                            // Se estado mudou para connecting, aguardar mais
                            if ($recheckState !== 'connecting' && $recheckState !== 'close') {
                                break;
                            }
                        }
                        
                        if ($reconnected) {
                            $instanceName = $instCandidate;
                            whatsapp_update_connection_status($instCandidate, 'connected');
                            error_log("[DISPATCH] ✅ Instância '$instCandidate' reconectada com sucesso!");
                            break;
                        } else {
                            error_log("[DISPATCH] Instância '$instCandidate' não reconectou.");
                            whatsapp_update_connection_status($instCandidate, 'disconnected');
                        }
                    } catch (Throwable $reconnErr) {
                        error_log("[DISPATCH] Erro ao tentar reconectar '$instCandidate': " . $reconnErr->getMessage());
                    }
                } else {
                    error_log("[DISPATCH] Instância '$instCandidate' não conectada (state='$connInstanceState')");
                }
            } catch (Throwable $e) {
                error_log("[DISPATCH] Instância '$instCandidate' erro ao verificar: " . $e->getMessage());
                continue;
            }
        }
        
        // Se nenhuma instância está conectada, mostrar erro com orientação clara
        if ($instanceName === '') {
            dispatch_abort('Nenhuma instância WhatsApp disponível para criar o grupo'
                . ($skippedUnhealthy ? ' (' . implode('; ', $skippedUnhealthy) . ')' : '')
                . '. Vá em Configurações → WhatsApp: se aparecer "Conexão em loop", desconecte os aparelhos no celular, faça logout da instância e leia um QR novo; senão, reconecte escaneando o QR Code.');
        }
        
        if ($baseUrl === '' || $apiKey === '') {
            dispatch_abort('Evolution API não configurada (base_url/api_key). Configure em: Configurações → WhatsApp Conexão → aba Credenciais.');
        }
        
        $api = new EvolutionApiV1($baseUrl, $apiKey, $instanceName);
        
        // Gerar nome do grupo: Especialidade - Cidade/UF[ - Zona] - N
        $location = $locationLabel;

        // Número sequencial entre os grupos da MESMA especialidade e cidade.
        $countStmt = db()->prepare('SELECT COUNT(*) FROM whatsapp_groups WHERE specialty = ? AND city = ? AND state = ?');
        $countStmt->execute([$specialty, $city, $state]);
        $groupNumber = (int)$countStmt->fetchColumn() + 1;

        $groupName = $specialty . ' - ' . $location . ' - ' . $groupNumber;
        
        // Profissionais da especialidade NA CIDADE/ZONA do card (cadastro + contatos)
        // + equipe MultiLife. Ver app/dispatch_targets.php.
        $collected = dispatch_collect_professionals($specialty, $city, $state, $zone, $captacaoTestMode);
        $memberStats = $collected['stats'];
        $teamPhones = dispatch_team_phones($captacaoTestMode, auth_user_id());
        $allParticipants = array_values(array_unique(array_merge($collected['phones'], $teamPhones)));
        // O grupo é CRIADO com poucos participantes (equipe + até 20 profissionais):
        // criar com centenas de números deixa a Evolution/WhatsApp muito lento e pode
        // falhar. O restante entra em lotes logo depois, ANTES do envio da mensagem.
        $participants = array_values(array_unique(array_merge(
            $teamPhones,
            array_slice($collected['phones'], 0, 20)
        )));
        error_log("[DISPATCH] Participantes para '$specialty' em '$locationLabel': " . json_encode($memberStats) . ' — total ' . count($allParticipants) . ', na criação ' . count($participants));
        if (empty($participants)) {
            // No modo de teste, NÃO usar o número da equipe/admin como fallback.
            // Se não há profissional de teste marcado, aborta a captação com aviso claro.
            if ($captacaoTestMode) {
                dispatch_abort('Modo de teste da captacao ligado, mas nenhum "profissional de teste" foi encontrado para esta especialidade. Marque ao menos um profissional como teste (ou desligue o modo de teste em Configuracoes > Funcionalidades).');
            }
            $participants[] = preg_replace('/\D+/', '', (string)admin_setting_get('evolution.admin_phone', '5517991253062'));
        }
        
        error_log("[DISPATCH] Participantes para grupo: " . implode(', ', $participants));
        
        // Buscar credenciais base (NÃO sobrescrever $instanceName: manter a
        // instância conectada já escolhida acima, que prioriza a do captador).
        $baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
        $apiKey = (string)admin_setting_get('evolution.api_key', '');
        if ($instanceName === '') {
            $instanceName = (string)admin_setting_get('evolution.instance', '');
        }

        // MULTI-INSTÂNCIA: ordem de tentativa — instância do captador primeiro,
        // depois a já escolhida, depois as demais ativas.
        // A primeira é a instância JÁ VERIFICADA como conectada acima; as demais só
        // entram se estiverem conectadas e sem problema de saúde (antes tentava todas,
        // inclusive desconectadas, com até 30 s de espera em cada).
        $instancesToTry = [$instanceName];
        $stmtAllInst = db()->prepare("SELECT instance_name FROM whatsapp_instances WHERE status = 'active' ORDER BY CASE WHEN instance_name = ? THEN 0 ELSE 1 END, is_default DESC, id ASC");
        $stmtAllInst->execute([$captadorInstanceName]);
        foreach ($stmtAllInst->fetchAll(PDO::FETCH_COLUMN) as $otherInst) {
            if ($otherInst === '' || in_array($otherInst, $instancesToTry, true)) {
                continue;
            }
            if (whatsapp_health_is_blocking(whatsapp_health_status((string)$otherInst)['level'])) {
                continue;
            }
            if (!in_array(dispatch_instance_state(new EvolutionApiV1($baseUrl, $apiKey, (string)$otherInst)), ['open', 'connected'], true)) {
                continue;
            }
            $instancesToTry[] = $otherInst;
        }
        demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'creating_group', "Procurando grupo de $locationLabel já existente no WhatsApp '$instanceName'...");
        
        $createHttpCode = 0;
        $createResponse = '';
        $newGroupJid = '';
        $usedInstanceName = $instanceName;
        
        // Primeiro, tentar buscar grupo existente com cada instância
        foreach ($instancesToTry as $tryInst) {
            try {
                $tryApi = new EvolutionApiV1($baseUrl, $apiKey, $tryInst);
                $fetchRes = $tryApi->fetchAllGroups(false);
                $fetchCode = (int)($fetchRes['status'] ?? 0);
                if ($fetchCode >= 200 && $fetchCode < 300) {
                    $allGroups = $fetchRes['json'] ?? [];
                    foreach ($allGroups as $grp) {
                        $grpSubject = $grp['subject'] ?? ($grp['name'] ?? '');
                        $grpId = $grp['id'] ?? ($grp['jid'] ?? '');
                        if (stripos($grpSubject, $specialty) !== false && stripos($grpSubject, $location) !== false && $grpId !== '') {
                            $newGroupJid = $grpId;
                            $groupName = $grpSubject;
                            $api = $tryApi;
                            $usedInstanceName = $tryInst;
                            error_log("[DISPATCH] Grupo existente encontrado via instância '$tryInst': '$grpSubject' (JID: $grpId)");
                            break 2; // Sair de ambos loops
                        }
                    }
                    // Esta instância está conectada, usar para criar
                    $api = $tryApi;
                    $usedInstanceName = $tryInst;
                    error_log("[DISPATCH] Instância '$tryInst' conectada (fetchAllGroups OK). Usando para criar grupo.");
                    break;
                }
            } catch (Exception $e) {
                error_log("[DISPATCH] Instância '$tryInst' falhou fetchAllGroups: " . $e->getMessage());
                continue;
            }
        }
        
        // Se encontrou grupo existente, pular criação
        if ($newGroupJid !== '') {
            $createHttpCode = 200;
        } else {
            // Criar grupo novo usando a instância conectada encontrada
            demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'creating_group', "Criando o grupo \"$groupName\" pelo WhatsApp '$usedInstanceName' (" . count($participants) . " participantes)...");
            $createUrl = $baseUrl . '/group/create/' . urlencode($usedInstanceName);
            $createPayload = json_encode([
                'subject' => $groupName,
                'description' => 'Grupo MultiLife - ' . $specialty,
                'participants' => $participants,
            ]);
            
            error_log("[DISPATCH] Criando grupo via instância '$usedInstanceName'. URL: $createUrl");
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $createUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => $createPayload,
                CURLOPT_HTTPHEADER => ["Content-Type: application/json", "apikey: " . $apiKey],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            
            $createResponse = curl_exec($ch);
            $createHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            error_log("[DISPATCH] Resposta criação (instância '$usedInstanceName'): HTTP $createHttpCode - " . substr($createResponse, 0, 500));
            
            // Se falhou com esta instância, tentar as outras
            if ($createHttpCode >= 400) {
                foreach ($instancesToTry as $retryInst) {
                    if ($retryInst === $usedInstanceName) continue;
                    
                    $retryUrl = $baseUrl . '/group/create/' . urlencode($retryInst);
                    error_log("[DISPATCH] Tentando criar com instância alternativa: '$retryInst'");
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $retryUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_CUSTOMREQUEST => "POST",
                        CURLOPT_POSTFIELDS => $createPayload,
                        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "apikey: " . $apiKey],
                        CURLOPT_SSL_VERIFYPEER => false,
                    ]);
                    
                    $createResponse = curl_exec($ch);
                    $createHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    error_log("[DISPATCH] Resposta (instância '$retryInst'): HTTP $createHttpCode - " . substr($createResponse, 0, 300));
                    
                    if ($createHttpCode >= 200 && $createHttpCode < 300) {
                        $usedInstanceName = $retryInst;
                        $api = new EvolutionApiV1($baseUrl, $apiKey, $retryInst);
                        error_log("[DISPATCH] ✅ Grupo criado com instância '$retryInst'!");
                        break;
                    }
                }
            }
        }
        
        // Preparar lista com sufixo para updateGroupMembers
        $participantsFormatted = array_map(function($num) {
            return strpos($num, '@') === false ? $num . '@s.whatsapp.net' : $num;
        }, $participants);
        
        if ($createHttpCode === 200 || $createHttpCode === 201) {
            // Se $newGroupJid ainda não foi definido (grupo novo criado), extrair da resposta
            if ($newGroupJid === '') {
                $createData = json_decode($createResponse, true);
                $newGroupJid = $createData['id'] ?? ($createData['groupJid'] ?? ($createData['jid'] ?? ($createData['group']['id'] ?? '')));
                error_log("[DISPATCH] JID do novo grupo: $newGroupJid");
            }
            
            // Garantir que o JID termine com @g.us
            if (!empty($newGroupJid) && strpos($newGroupJid, '@') === false) {
                $newGroupJid = $newGroupJid . '@g.us';
            }
            
            // Se o JID não parece um grupo válido (formato esperado: NUMBERS-NUMBERS@g.us ou NUMBERS@g.us),
            // tentar buscar o grupo recém-criado via fetchAllGroups pelo nome
            if (!empty($newGroupJid) && strpos($newGroupJid, '-') === false) {
                error_log("[DISPATCH] JID sem hífen detectado ($newGroupJid), tentando buscar JID real via fetchAllGroups...");
                try {
                    $fetchApi = $api;
                    $allGroupsRes = $fetchApi->fetchAllGroups(false);
                    $allGroupsCode = (int)($allGroupsRes['status'] ?? 0);
                    if ($allGroupsCode >= 200 && $allGroupsCode < 300) {
                        $groupsList = $allGroupsRes['json'] ?? [];
                        // Procurar pelo nome do grupo recém-criado
                        foreach ($groupsList as $grp) {
                            $grpSubject = $grp['subject'] ?? ($grp['name'] ?? '');
                            $grpId = $grp['id'] ?? ($grp['jid'] ?? '');
                            if ($grpSubject === $groupName && strpos($grpId, '-') !== false) {
                                error_log("[DISPATCH] JID real encontrado via fetchAllGroups: $grpId (antigo: $newGroupJid)");
                                $newGroupJid = $grpId;
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("[DISPATCH] Erro ao buscar JID real: " . $e->getMessage());
                }
            }
            
            if (!empty($newGroupJid)) {
                // Salvar grupo no banco
                $stmtNewGroup = db()->prepare(
                    'INSERT INTO whatsapp_groups (name, evolution_group_jid, instance_name, contacts_count, specialty, city, state, zone, status) VALUES (:n, :jid, :inst, :cnt, :sp, :city, :st, :zone, \'active\') ON DUPLICATE KEY UPDATE name = VALUES(name), instance_name = VALUES(instance_name), city = VALUES(city), state = VALUES(state), zone = VALUES(zone)'
                );
                $stmtNewGroup->execute([
                    'n' => $groupName,
                    'jid' => $newGroupJid,
                    'inst' => $usedInstanceName ?: $captadorInstanceName,
                    'cnt' => count($participants),
                    'sp' => $specialty,
                    'city' => $city,
                    'st' => $state,
                    'zone' => $zone,
                ]);
                $newGroupDbId = (int)db()->lastInsertId();

                // Com ON DUPLICATE KEY UPDATE o lastInsertId() pode voltar 0 quando o
                // grupo já existia (foi só atualizado). Nesse caso, recupera o id pelo JID.
                if ($newGroupDbId <= 0) {
                    $stmtFindGroup = db()->prepare('SELECT id FROM whatsapp_groups WHERE evolution_group_jid = ? LIMIT 1');
                    $stmtFindGroup->execute([$newGroupJid]);
                    $newGroupDbId = (int)$stmtFindGroup->fetchColumn();
                }

                // CORREÇÃO: registrar o grupo recém-criado em $groups para que o fluxo
                // de envio (inserção em demand_dispatch_logs + sendTextToGroup) rode.
                // Sem isto, quando o grupo era criado automaticamente, $groups ficava
                // vazio e a MENSAGEM DA CAPTAÇÃO NUNCA ERA ENVIADA (grupo criado sem msg).
                if ($newGroupDbId > 0) {
                    $alreadyInGroups = false;
                    foreach ($groups as $gExisting) {
                        if ((int)($gExisting['id'] ?? 0) === $newGroupDbId) {
                            $alreadyInGroups = true;
                            break;
                        }
                    }
                    if (!$alreadyInGroups) {
                        $groups[] = [
                            'id' => $newGroupDbId,
                            'name' => $groupName,
                            'evolution_group_jid' => $newGroupJid,
                        ];
                    }
                }

                // Também salvar na chat_groups
                try {
                    db()->prepare("INSERT IGNORE INTO chat_groups (group_jid, group_name, specialty, region, created_at) VALUES (?, ?, ?, ?, NOW())")
                        ->execute([$newGroupJid, $groupName, $specialty, $location]);
                } catch (Exception $e) {}
                
                // Usar o grupo recém-criado para o dispatch
                $groups = [['id' => $newGroupDbId, 'name' => $groupName, 'evolution_group_jid' => $newGroupJid]];
                
                error_log("[DISPATCH] ✅ Grupo criado automaticamente: '$groupName' (JID: $newGroupJid) com " . count($participants) . " participantes");
                
                // GARANTIA: algumas versões da Evolution ignoram 'participants' na
                // criação. Quem faltar é adicionado no bloco "ADICIONAR MEMBROS ANTES
                // DO ENVIO" (mais abaixo), antes de a mensagem sair.
                // Lista COMPLETA (a criação levou só uma parte).
                $memberTargets[$newGroupJid] = array_map(static fn($num) => strpos($num, '@') === false ? $num . '@s.whatsapp.net' : $num, $allParticipants ?: $participants);
                
                // Configurar grupo para apenas admins enviarem mensagens
                demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'creating_group', "Grupo \"$groupName\" criado — configurando como 'somente admins enviam'...");
                try {
                    $api2 = $api;
                    $settingsResult = $api2->updateGroupSetting($newGroupJid, 'announcement');
                    $settingsCode = $settingsResult['status'] ?? 0;
                    $settingsJson = $settingsResult['json'] ?? $settingsResult['body_raw'] ?? '';
                    error_log("[DISPATCH] Grupo configurado como 'somente admins': HTTP $settingsCode | Response: " . json_encode($settingsJson));
                    
                    if ($settingsCode < 200 || $settingsCode >= 300) {
                        error_log("[DISPATCH] ⚠️ FALHA ao configurar grupo como announcement! HTTP $settingsCode");
                        error_log("[DISPATCH] groupJid usado: $newGroupJid");
                    }
                } catch (Exception $e) {
                    error_log("[DISPATCH] Erro ao configurar grupo: " . $e->getMessage());
                }
                
                // MULTI-INSTÂNCIA: Promover todos os números de instâncias conectadas a admin do grupo
                // Isso garante que todas as instâncias possam enviar mensagens no grupo (announcement mode)
                try {
                    $instanceNumbers = whatsapp_get_all_connected_numbers();
                    $promoteList = [];
                    foreach ($instanceNumbers as $num) {
                        $promoteList[] = $num . '@s.whatsapp.net';
                    }
                    // Também promover o usuário logado e admins de captação
                    foreach ($participants as $pNum) {
                        $pJid = strpos($pNum, '@') === false ? $pNum . '@s.whatsapp.net' : $pNum;
                        if (!in_array($pJid, $promoteList, true)) {
                            $promoteList[] = $pJid;
                        }
                    }
                    $promoteList = array_values(array_unique($promoteList));

                    // Só promove quem JÁ é membro e ainda não é admin, nunca o
                    // próprio número (o criador já é admin). Operações de grupo
                    // com números fora do grupo / o próprio número derrubam a sessão.
                    $currentMembers = dispatch_group_member_keys($api, $newGroupJid);
                    $selfKeyNew = dispatch_member_key(dispatch_instance_number($usedInstanceName ?? $instanceName));
                    $promoteList = $currentMembers === null ? [] : array_values(array_filter($promoteList, function ($pj) use ($currentMembers, $selfKeyNew) {
                        $k = dispatch_member_key($pj);
                        return $k !== '' && $k !== $selfKeyNew && isset($currentMembers[$k]) && empty($currentMembers[$k]);
                    }));
                    
                    if (!empty($promoteList)) {
                        demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'creating_group', "Grupo \"$groupName\" criado — tornando a equipe administradora...");
                        $api3 = $api;
                        $promoteResult = $api3->updateGroupMembers($newGroupJid, 'promote', $promoteList);
                        $promoteCode = $promoteResult['status'] ?? 0;
                        error_log("[DISPATCH] Participantes promovidos a admin: HTTP $promoteCode (" . count($promoteList) . " números)");
                    }
                } catch (Exception $e) {
                    error_log("[DISPATCH] Erro ao promover participantes a admin: " . $e->getMessage());
                }
                
                // Pequeno delay para o grupo estabilizar antes de enviar mensagem
                sleep(3);
            } else {
                dispatch_abort('Erro ao criar grupo automaticamente: API não retornou JID.');
            }
        } else {
            $errorMsg = substr($createResponse, 0, 500);
            error_log("[DISPATCH] Erro ao criar grupo: HTTP $createHttpCode - $errorMsg");
            error_log("[DISPATCH] Grupo tentado: '$groupName' | Instância: '$instanceName'");
            dispatch_abort('Erro ao criar grupo automaticamente (HTTP ' . $createHttpCode . '). Crie o grupo manualmente e tente novamente.');
        }
    } catch (Exception $e) {
        dispatch_abort('Erro ao criar grupo: ' . $e->getMessage());
    }
}

$tpl = trim((string)admin_setting_get(
    'demands.whatsapp_template',
    ''
));

// Se template vazio ou não configurado, usar padrão com rua + bairro + cidade
if ($tpl === '') {
    $tpl = "[CAPTAÇÃO #{id}]\n{title}\n\n📍 *Local:*\n{street_neighborhood_city}\n\n🏥 *Especialidade:* {specialty}\n📅 *Frequência:* {frequency}\n\n{ai_summary_block}👆 *Tem interesse e disponibilidade?*\nReaja a esta mensagem com qualquer emoji para demonstrar interesse. Entraremos em contato no privado para alinhar os detalhes.";
}

// Montar endereço completo (rua, número, bairro)
$street = (string)($subRequest ? ($subRequest['location_street'] ?? $d['location_street'] ?? '') : ($d['location_street'] ?? ''));
$neighborhood = (string)($subRequest ? ($subRequest['location_neighborhood'] ?? $d['location_neighborhood'] ?? '') : ($d['location_neighborhood'] ?? ''));
$number = (string)($subRequest ? ($subRequest['location_number'] ?? $d['location_number'] ?? '') : ($d['location_number'] ?? ''));
$complement = (string)($subRequest ? ($subRequest['location_complement'] ?? $d['location_complement'] ?? '') : ($d['location_complement'] ?? ''));

$addressParts = [];
if (trim($street) !== '') {
    $addr = trim($street);
    if (trim($number) !== '') $addr .= ', ' . trim($number);
    $addressParts[] = $addr;
}
if (trim($complement) !== '') $addressParts[] = trim($complement);
if (trim($neighborhood) !== '') $addressParts[] = trim($neighborhood);
$fullAddress = count($addressParts) > 0 ? implode(' - ', $addressParts) : '';

// Frequência: usar helper se disponível
$freqRaw = $subRequest && trim((string)($subRequest['frequency'] ?? '')) !== '' 
    ? (string)$subRequest['frequency'] 
    : (trim((string)($d['frequency'] ?? '')) !== '' ? (string)$d['frequency'] : '');
$freqDisplay = $freqRaw;
if ($freqRaw !== '' && function_exists('frequency_get_label')) {
    $normalized = frequency_normalize($freqRaw);
    if ($normalized !== '') {
        $freqDisplay = frequency_get_label($normalized) . ' (' . frequency_get_weekday_description($normalized) . ')';
    }
}

// ====================================================================
// SANITIZAÇÃO DA MENSAGEM: Sigilo do paciente, filtro de especialidade, remoção de valores
// ====================================================================

// 1. Remover nome do paciente do título e substituir por texto genérico
$titleForMsg = (string)$d['title'];
$patientName = trim((string)($d['patient_name'] ?? ''));

// Inicial do paciente (privacidade): "Roberto" -> "R.", "Roberto Teste" -> "R.".
// Usada para substituir o nome do paciente por uma referência anônima.
$patientInitial = '';
if ($patientName !== '') {
    $firstNameToken = trim(preg_split('/\s+/', trim($patientName))[0] ?? '');
    if ($firstNameToken !== '') {
        $patientInitial = mb_strtoupper(mb_substr($firstNameToken, 0, 1)) . '.';
    }
}

// Abordagem robusta: substituir o nome do paciente pela inicial no título.
// Ex.: "Atendimento multidisciplinar para Roberto Teste" -> "... para R."
if ($patientName !== '') {
    $titleForMsg = str_ireplace($patientName, $patientInitial, $titleForMsg);
}
// Substituir "para <Nome...>" no fim do título pela inicial (cobre nome não salvo em patient_name)
if ($patientInitial !== '') {
    $titleForMsg = preg_replace('/\s+para\s+\S.*$/iu', ' para ' . $patientInitial, $titleForMsg);
} else {
    // Sem nome conhecido: remover o trecho "para ..." por segurança
    $titleForMsg = preg_replace('/\s+para\s+\S.*$/iu', '', $titleForMsg);
}
// Remover qualquer texto após " - " que pareça nome (2+ palavras capitalizadas) — fallback
$titleForMsg = preg_replace('/\s*[-–]\s+[A-ZÀ-ÚÇ][a-zà-úç]+(\s+[A-ZÀ-ÚÇa-zà-úç]+)+\s*$/u', '', $titleForMsg);
// Limpar espaços e pontuação residual
$titleForMsg = rtrim(trim($titleForMsg), ' -–');
$titleForMsg = trim(preg_replace('/\s{2,}/', ' ', $titleForMsg));

// Substituir "multidisciplinar" já que estamos filtrando para 1 especialidade
$titleForMsg = preg_replace('/\s*multidisciplinar\s*/iu', ' ', $titleForMsg);
$titleForMsg = trim(preg_replace('/\s{2,}/', ' ', $titleForMsg));

// Montar título genérico com a especialidade: "Atendimento" → "Atendimento de Fisioterapia Domiciliar"
// Se o título ficou muito curto ou genérico, complementar com a especialidade
if (mb_strlen($titleForMsg) < 15 && $specialty !== '') {
    $titleForMsg = $titleForMsg . ' de ' . $specialty;
}

// 2. Sanitizar ai_summary: remover nome do paciente, filtrar por especialidade, remover valores
$aiSummaryRaw = trim((string)($d['ai_summary'] ?? ''));
$aiSummarySanitized = $aiSummaryRaw;

if ($aiSummarySanitized !== '') {
    // 2a. Substituir nome do paciente pela inicial (privacidade). Ex.: "Roberto" -> "R."
    if ($patientName !== '') {
        $aiSummarySanitized = str_ireplace($patientName, $patientInitial, $aiSummarySanitized);
    }
    // Limpar padrões residuais como "O paciente, , 72 anos" → "O paciente, 72 anos"
    $aiSummarySanitized = preg_replace('/,\s*,/', ',', $aiSummarySanitized);
    
    // Reformatar início: "O paciente, 72 anos" → "O paciente de, 72 anos"
    // Detectar padrão: "O paciente, XX anos" ou "Paciente, XX anos" ou ", XX anos,"
    $aiSummarySanitized = preg_replace('/^(?:O\s+)?(?:paciente|Paciente)\s*(?:D\s*)?,?\s*(\d+\s*anos)/iu', 'O paciente de, $1', $aiSummarySanitized);
    // Se não começa com "O paciente de" ainda (ex: texto começava com nome direto), prefixar
    if (!preg_match('/^O paciente de\b/iu', $aiSummarySanitized)) {
        // Tentar extrair idade do texto
        if (preg_match('/(\d{1,3})\s*anos/iu', $aiSummarySanitized, $ageMatch)) {
            $age = $ageMatch[1];
            // Remover a idade duplicada se já estiver no meio do texto
            $aiSummarySanitized = preg_replace('/^[^.]*?\d{1,3}\s*anos\s*,?\s*/iu', '', $aiSummarySanitized);
            $aiSummarySanitized = 'O paciente de, ' . $age . ' anos, ' . ltrim($aiSummarySanitized, ', ');
        } else {
            $aiSummarySanitized = 'O paciente de, ' . ltrim($aiSummarySanitized, ', ');
        }
    }
    
    $aiSummarySanitized = preg_replace('/\s{2,}/', ' ', $aiSummarySanitized);
    
    // 2b. Remover TODOS os valores financeiros
    // Remove frases inteiras sobre valor autorizado
    $aiSummarySanitized = preg_replace('/[^.\n]*valor\s+autorizado[^.\n]*\.?/iu', '', $aiSummarySanitized);
    // Remove frases sobre valor do plano, custo, preço
    $aiSummarySanitized = preg_replace('/[^.\n]*(?:valor|custo|preço)\s+(?:é|será|de|do\s+plano|mensal|total)[^.\n]*\.?/iu', '', $aiSummarySanitized);
    // Remove R$ com valor
    $aiSummarySanitized = preg_replace('/,?\s*(?:no valor de|de|é de|será de|valor de)?\s*R\$\s*[\d.,]+(?:\s*(?:por|\/)\s*(?:mês|sessão|dia|semana|hora|atendimento))?/iu', '', $aiSummarySanitized);
    // Remove valores numéricos em formato monetário sem R$ (ex: "500,00 por mês", "11.500,00 por mês")
    $aiSummarySanitized = preg_replace('/\.?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:por|\/)\s*(?:mês|sessão|dia|semana|hora|atendimento)/iu', '', $aiSummarySanitized);
    // Remove "valor" seguido de número com vírgula decimal
    $aiSummarySanitized = preg_replace('/,?\s*(?:valor|custo)\s*(?:de|:)?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:reais|por\s+\w+)?/iu', '', $aiSummarySanitized);
    // Remove R$ sozinho que pode ter ficado
    $aiSummarySanitized = preg_replace('/R\$\s*[\d.,]+/iu', '', $aiSummarySanitized);
    // Limpar pontuação solta
    $aiSummarySanitized = preg_replace('/\.\s*\./', '.', $aiSummarySanitized);
    $aiSummarySanitized = preg_replace('/,\s*\./', '.', $aiSummarySanitized);
    $aiSummarySanitized = preg_replace('/,\s*,/', ',', $aiSummarySanitized);
    
    // 2c. Filtrar por especialidade: manter apenas informações da especialidade da captação
    // Separar por frases/sentenças e filtrar as que mencionam outras especialidades
    $specialtiesAll = ['fisioterapia', 'fonoaudiologia', 'enfermagem', 'psicologia', 'terapia ocupacional', 
                       'nutrição', 'nutri', 'medicina', 'médico', 'técnico de enfermagem'];
    // Identificar a especialidade da captação atual (normalizar para match)
    $currentSpecNormalized = mb_strtolower(trim(explode(' ', $specialty)[0])); // Ex: "fisioterapia" de "Fisioterapia Domiciliar"
    
    // Outras especialidades (todas exceto a atual)
    $otherSpecs = array_filter($specialtiesAll, function($s) use ($currentSpecNormalized) {
        return mb_strpos($s, $currentSpecNormalized) === false && mb_strpos($currentSpecNormalized, $s) === false;
    });
    
    if (!empty($otherSpecs)) {
        // Tratar o texto por sentenças (separadas por . ou ,)
        // Abordagem: remover trechos de listas multidisciplinares que mencionam outras especialidades
        // Ex: "incluindo fisioterapia (3x/semana), fonoaudiologia (2x/semana), enfermagem (diária) e psicologia (1x/semana)"
        // Se captação é de fisioterapia, remover "fonoaudiologia (2x/semana), enfermagem (diária) e psicologia (1x/semana)"
        
        // Padrão: remover itens de lista separados por vírgula ou "e" que contenham outras especialidades
        foreach ($otherSpecs as $otherSpec) {
            // Remove "especialidade (frequência)," ou ", especialidade (frequência)" ou "e especialidade (frequência)"
            $aiSummarySanitized = preg_replace('/,?\s*(?:e\s+)?' . preg_quote($otherSpec, '/') . '\s*\([^)]*\)/iu', '', $aiSummarySanitized);
            // Remove menções simples como "especialidade X vezes/semana"
            $aiSummarySanitized = preg_replace('/,?\s*(?:e\s+)?' . preg_quote($otherSpec, '/') . '\s+\d+[x×]\s*[\/\\\\]\s*\w+/iu', '', $aiSummarySanitized);
        }
        
        // Ajustar "incluindo fisioterapia (3x/semana)" → limpar "incluindo" se sobrou só uma especialidade
        $aiSummarySanitized = preg_replace('/incluindo\s+(' . preg_quote(explode(' ', $specialty)[0], '/') . ')/iu', '$1', $aiSummarySanitized);
        
        // Substituir "atendimento multidisciplinar" por "atendimento" quando filtramos para 1 especialidade
        $aiSummarySanitized = preg_replace('/atendimento\s+multidisciplinar\s+domiciliar/iu', 'atendimento domiciliar', $aiSummarySanitized);
        $aiSummarySanitized = preg_replace('/atendimento\s+multidisciplinar/iu', 'atendimento', $aiSummarySanitized);
        
        // Remover "solicita-se atendimento domiciliar, " ficando com vírgula solta
        $aiSummarySanitized = preg_replace('/,\s*,/', ',', $aiSummarySanitized);
        $aiSummarySanitized = preg_replace('/,\s*\./', '.', $aiSummarySanitized);
        $aiSummarySanitized = preg_replace('/\.\s*,/', '.', $aiSummarySanitized);
    }
    
    // Limpar espaços e linhas extras
    $aiSummarySanitized = preg_replace('/\n{3,}/', "\n\n", $aiSummarySanitized);
    $aiSummarySanitized = preg_replace('/\s{2,}/', ' ', $aiSummarySanitized);
    $aiSummarySanitized = trim($aiSummarySanitized);
}

$repl = [
    '{id}' => (string)$d['id'],
    '{title}' => $titleForMsg,
    '{city}' => $city !== '' ? $city : '-',
    '{state}' => $state !== '' ? $state : '-',
    '{address}' => $fullAddress !== '' ? $fullAddress : '',
    '{street}' => $street !== '' ? $street : '-',
    '{neighborhood}' => $neighborhood !== '' ? $neighborhood : '-',
    '{neighborhood_city}' => trim(($neighborhood !== '' ? $neighborhood . ' - ' : '') . ($city !== '' ? $city : '') . ($state !== '' ? '/' . $state : '')),
    // Rua + bairro + cidade/UF (sem número, por privacidade). Usado no bloco de Local.
    '{street_neighborhood_city}' => trim(
        ($street !== '' ? $street . ' - ' : '')
        . ($neighborhood !== '' ? $neighborhood . ' - ' : '')
        . ($city !== '' ? $city : '')
        . ($state !== '' ? '/' . $state : '')
    ),
    '{specialty}' => $specialty !== '' ? $specialty : '-',
    '{frequency}' => $freqDisplay !== '' ? $freqDisplay : '-',
    '{description}' => mb_strimwidth(
        ($subRequest ? (string)($subRequest['description'] ?? $d['description'] ?? '') : (string)($d['description'] ?? '')),
        0, 500, '...'
    ),
    '{ai_summary}' => mb_strimwidth($aiSummarySanitized, 0, 800, '...'),
    '{ai_summary_block}' => $aiSummarySanitized !== ''
        ? "📋 *Quadro Clínico:*\n" . mb_strimwidth($aiSummarySanitized, 0, 800, '...') . "\n\n"
        : '',
    '{captation_note}' => trim((string)($d['captation_note'] ?? '')),
    '{captation_note_block}' => trim((string)($d['captation_note'] ?? '')) !== ''
        ? "📝 *Observação:*\n" . trim((string)($d['captation_note'] ?? '')) . "\n\n"
        : '',
    '{origin}' => (string)($d['origin_email'] ?? ''),
];

$msg = strtr($tpl, $repl);

// Se o template customizado não contém placeholder de quadro clínico mas existe conteúdo,
// anexar automaticamente antes do CTA (👆 Tem interesse)
if ($aiSummarySanitized !== '' && strpos($tpl, '{ai_summary') === false) {
    $clinicalBlock = "\n\n📋 *Quadro Clínico:*\n" . mb_strimwidth($aiSummarySanitized, 0, 800, '...');
    // Inserir antes do CTA se existir, senão no final
    $ctaPos = mb_strpos($msg, '👆');
    if ($ctaPos === false) {
        $ctaPos = mb_strpos($msg, '*Tem interesse');
    }
    if ($ctaPos !== false) {
        $msg = mb_substr($msg, 0, $ctaPos) . $clinicalBlock . "\n\n" . mb_substr($msg, $ctaPos);
    } else {
        $msg .= $clinicalBlock;
    }
}

// Se o template não contém placeholder de observação mas existe conteúdo, anexar automaticamente
$captationNote = trim((string)($d['captation_note'] ?? ''));
if ($captationNote !== '' && strpos($tpl, '{captation_note') === false) {
    $noteBlock = "\n\n📝 *Observação:*\n" . $captationNote;
    $ctaPos = mb_strpos($msg, '👆');
    if ($ctaPos === false) {
        $ctaPos = mb_strpos($msg, '*Tem interesse');
    }
    if ($ctaPos !== false) {
        $msg = mb_substr($msg, 0, $ctaPos) . $noteBlock . "\n\n" . mb_substr($msg, $ctaPos);
    } else {
        $msg .= $noteBlock;
    }
}

// Proteção contra envio duplo: verificar se já existe dispatch recente (< 60s) para esta demanda + sub-request
$recentSql = "SELECT id FROM demand_dispatch_logs WHERE demand_id = :did AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)";
$recentParams = ['did' => $id];
if ($subRequestId > 0) {
    // Se tiver sub_request, verificar se a mensagem contém a especialidade da sub_request
    $recentSql .= " AND message LIKE :splike";
    $recentParams['splike'] = '%' . ($subRequest['specialty'] ?? '') . '%';
}
$recentSql .= " LIMIT 1";
$recentDispatch = db()->prepare($recentSql);
$recentDispatch->execute($recentParams);
if ($recentDispatch->fetch()) {
    // Já houve um disparo há menos de 1 minuto. No background isto normalmente
    // significa reprocessamento/retry do mesmo run — não é erro, apenas evita
    // enviar a captação em duplicidade. Encerra marcando o run como concluído.
    demand_dispatch_run_finish($DISPATCH_RUN_ID, 'completed', 'Captação já havia sido disparada há instantes (envio duplicado evitado).');
    dispatch_stop();
}

// ====================================================================
// SINCRONIZAR MEMBROS DO GRUPO: Antes de enviar a captação, verificar se há profissionais
// compatíveis que ainda não estão no grupo e adicioná-los.
// Isso garante que profissionais novos recebam a captação.
// ====================================================================
if (count($groups) > 0) {
    demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'syncing_members', 'Verificando os profissionais de ' . $locationLabel . '...');
    $targetJid = (string)($groups[0]['evolution_group_jid'] ?? '');
    if ($targetJid !== '' && !isset($memberTargets[$targetJid])) {
        // Grupo JÁ EXISTENTE: quem deve estar nele = profissionais da especialidade
        // na cidade/zona do card (cadastro + contatos) + equipe. Quem faltar é
        // adicionado ANTES do envio (bloco "ADICIONAR MEMBROS ANTES DO ENVIO").
        try {
            $collected = dispatch_collect_professionals($specialty, $city, $state, $zone, $captacaoTestMode);
            $memberStats = $collected['stats'];
            $all = array_values(array_unique(array_merge(
                $collected['phones'],
                dispatch_team_phones($captacaoTestMode, auth_user_id())
            )));
            $memberTargets[$targetJid] = array_map(static fn($p) => $p . '@s.whatsapp.net', $all);
            error_log("[DISPATCH] Membros esperados em $targetJid: " . count($all) . ' ' . json_encode($memberStats));
        } catch (Throwable $e) {
            error_log("[DISPATCH] Erro ao montar lista de membros: " . $e->getMessage());
        }
    }
}

$db = db();
$db->beginTransaction();
try {
    $ins = $db->prepare('INSERT INTO demand_dispatch_logs (demand_id, group_id, dispatched_by_user_id, message, capture_token, dispatch_status) VALUES (:did, :gid, :uid, :msg, :token, :st)');
    foreach ($groups as $g) {
        $token = '#CAP' . (string)$id . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $msgWithToken = $msg . "\n\n" . $token;
        $ins->execute([
            'did' => $id,
            'gid' => (int)$g['id'],
            'uid' => auth_user_id(),
            'msg' => $msgWithToken,
            'token' => $token,
            'st' => 'queued',
        ]);
    }

    // Atualiza status para em_captacao e assume para o usuário que disparou
    if ((string)$d['status'] === 'aguardando_captacao') {
        $upd = $db->prepare('UPDATE demands SET status = \'em_captacao\', assumed_by_user_id = :uid, assumed_at = NOW() WHERE id = :id');
        $upd->execute(['id' => $id, 'uid' => auth_user_id()]);

        $log = $db->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, :os, :ns, :uid, :note)');
        $log->execute([
            'did' => $id,
            'os' => 'aguardando_captacao',
            'ns' => 'em_captacao',
            'uid' => auth_user_id(),
            'note' => 'realizar captacao',
        ]);
    }

    audit_log('create', 'demand_dispatch', (string)$id, null, ['groups' => array_map(fn($x) => (int)$x['id'], $groups)]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

// Envio via Evolution (fora da transação)
// MULTI-INSTÂNCIA: Tentar todas as instâncias conectadas, não apenas a padrão
$api = null;
$apiInstanceName = null;
try {
    // Descobrir a instância DONA do grupo alvo. Ela é quem realmente está dentro
    // do grupo no WhatsApp (e normalmente admin), então deve ter PRIORIDADE no
    // envio — senão outra instância, que nem é membro, "envia" para um grupo em
    // modo announcement e a mensagem é descartada silenciosamente.
    $ownerInstanceName = '';
    try {
        $firstJid = '';
        foreach ($groups as $g0) {
            $firstJid = (string)($g0['evolution_group_jid'] ?? '');
            if ($firstJid !== '') break;
        }
        if ($firstJid !== '') {
            $stmtOwner = db()->prepare("SELECT instance_name FROM whatsapp_groups WHERE evolution_group_jid = ? AND instance_name IS NOT NULL AND instance_name <> '' LIMIT 1");
            $stmtOwner->execute([$firstJid]);
            $ownerInstanceName = (string)($stmtOwner->fetchColumn() ?: '');
        }
    } catch (Throwable $ownErr) { $ownerInstanceName = ''; }

    // Buscar todas as instâncias ativas. Prioriza: (1) a DONA do grupo,
    // (2) a instância do captador, (3) conectadas, (4) padrão.
    $allInstances = db()->prepare("
        SELECT instance_name, token, is_default, connection_status 
        FROM whatsapp_instances 
        WHERE status = 'active' 
        ORDER BY 
            CASE WHEN instance_name = :owner THEN 0 ELSE 1 END ASC,
            CASE WHEN instance_name = :captador THEN 0 ELSE 1 END ASC,
            CASE WHEN connection_status = 'connected' THEN 0 ELSE 1 END ASC,
            is_default DESC, 
            id ASC
    ");
    $allInstances->execute(['owner' => $ownerInstanceName, 'captador' => $captadorInstanceName]);
    $instances = $allInstances->fetchAll();
    
    $baseUrl = (string)admin_setting_get('evolution.base_url', '');
    $apiKey = (string)admin_setting_get('evolution.api_key', '');
    
    // Tentar cada instância até encontrar uma conectada
    foreach ($instances as $inst) {
        $instName = (string)($inst['instance_name'] ?? '');
        if ($instName === '') continue;
        if (whatsapp_health_is_blocking(whatsapp_health_status($instName)['level'])) {
            error_log("[DISPATCH] Instância '$instName' ignorada no envio (conexão em loop/conflito/deslogada).");
            continue;
        }
        
        try {
            $tryApi = new EvolutionApiV1($baseUrl, $apiKey, $instName);
            $connState = $tryApi->connectionState();
            $connJson = $connState['json'] ?? [];
            
            // Extrair estado - múltiplos formatos possíveis
            $connInstanceState = '';
            if (isset($connJson['instance']['state'])) {
                $connInstanceState = (string)$connJson['instance']['state'];
            } elseif (isset($connJson['state'])) {
                $connInstanceState = (string)$connJson['state'];
            } elseif (isset($connJson['instance']['status'])) {
                $connInstanceState = (string)$connJson['instance']['status'];
            } elseif (isset($connJson['status']) && is_string($connJson['status'])) {
                $connInstanceState = (string)$connJson['status'];
            }
            
            $connInstanceStateLower = strtolower(trim($connInstanceState));
            
            if (in_array($connInstanceStateLower, ['open', 'connected'], true)) {
                $api = $tryApi;
                $apiInstanceName = $instName;
                error_log("[DISPATCH] Instância conectada encontrada: $instName (state=$connInstanceStateLower)");
                break;
            }
            
            // Se estado é 'close', tentar reconectar (apenas fora do modo worker —
            // no worker a reconexão com sleep trava o disparo; pulamos a instância).
            if (!$DISPATCH_WORKER_MODE && ($connInstanceStateLower === 'close' || $connInstanceStateLower === 'closed')) {
                error_log("[DISPATCH-SEND] Instância '$instName' com estado '$connInstanceState' — tentando reconectar...");
                try {
                    $connectRes = $tryApi->connectInstance($instName);
                    $connectCode = (int)($connectRes['status'] ?? 0);
                    $connectJson = $connectRes['json'] ?? [];
                    error_log("[DISPATCH-SEND] Connect '$instName': HTTP $connectCode");
                    
                    // Se retornou QR code, sessão expirou
                    $hasQr = isset($connectJson['base64']) || isset($connectJson['code']) || isset($connectJson['qrcode']);
                    if (!$hasQr) {
                        for ($retryAttempt = 0; $retryAttempt < 5; $retryAttempt++) {
                            sleep(2);
                            $recheckRes = $tryApi->connectionState();
                            $recheckState = strtolower(trim((string)(($recheckRes['json']['instance']['state'] ?? ($recheckRes['json']['state'] ?? '')))));
                            if (in_array($recheckState, ['open', 'connected'], true)) {
                                $api = $tryApi;
                                $apiInstanceName = $instName;
                                whatsapp_update_connection_status($instName, 'connected');
                                error_log("[DISPATCH-SEND] ✅ Instância '$instName' reconectada!");
                                break 2; // sai do for e do foreach
                            }
                            if ($recheckState !== 'connecting' && $recheckState !== 'close') break;
                        }
                    } else {
                        whatsapp_update_connection_status($instName, 'disconnected');
                    }
                } catch (Throwable $reconnErr) {
                    error_log("[DISPATCH-SEND] Erro ao reconectar '$instName': " . $reconnErr->getMessage());
                }
            }
            
            error_log("[DISPATCH] Instância '$instName' não conectada (state=$connInstanceState), tentando próxima...");
        } catch (Throwable $instErr) {
            error_log("[DISPATCH] Erro ao verificar instância '$instName': " . $instErr->getMessage());
            continue;
        }
    }
    
    // Fallback: se nenhuma instância do banco funcionou, tentar a instância padrão das admin_settings
    if ($api === null) {
        $defaultInstName = (string)admin_setting_get('evolution.instance', '');
        if ($defaultInstName !== '') {
            try {
                $tryApi = new EvolutionApiV1();
                $connState = $tryApi->connectionState();
                $connJson = $connState['json'] ?? [];
                $connInstanceState = (string)($connJson['instance']['state'] ?? ($connJson['state'] ?? ''));
                $connInstanceStateLower = strtolower(trim($connInstanceState));
                if (in_array($connInstanceStateLower, ['open', 'connected'], true)) {
                    $api = $tryApi;
                    $apiInstanceName = $defaultInstName;
                    error_log("[DISPATCH] Instância padrão admin_settings conectada: $defaultInstName");
                }
            } catch (Throwable $defErr) {
                error_log("[DISPATCH] Instância padrão admin_settings falhou: " . $defErr->getMessage());
            }
        }
        
        // Último recurso: usar instância que está como 'connected' no banco sem verificar API
        if ($api === null) {
            foreach ($instances as $inst) {
                $instName = (string)($inst['instance_name'] ?? '');
                $instConnStatus = (string)($inst['connection_status'] ?? '');
                if ($instName !== '' && $instConnStatus === 'connected') {
                    try {
                        $api = new EvolutionApiV1($baseUrl, $apiKey, $instName);
                        $apiInstanceName = $instName;
                        error_log("[DISPATCH] Usando instância '$instName' baseado no connection_status do banco (API não confirmou)");
                        break;
                    } catch (Throwable $e) {
                        continue;
                    }
                }
            }
        }
    }
    
    // Se nenhuma instância está conectada, tentar usar a padrão sem verificar (fallback agressivo)
    if ($api === null) {
        $defaultInstName = (string)admin_setting_get('evolution.instance', '');
        if ($defaultInstName !== '' && $baseUrl !== '' && $apiKey !== '') {
            error_log("[DISPATCH] FALLBACK: Nenhuma instância confirmada como conectada. Tentando usar instância padrão '$defaultInstName' sem verificação...");
            $api = new EvolutionApiV1($baseUrl, $apiKey, $defaultInstName);
            $apiInstanceName = $defaultInstName;
        }
    }
    
    // Se ainda assim não temos API, reverter e avisar
    if ($api === null) {
        db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = \'error\', error_message = \'Nenhuma instância WhatsApp conectada\' WHERE demand_id = :did AND dispatch_status = \'queued\'')
            ->execute(['did' => $id]);
        
        // Reverter status da demanda
        if ((string)$d['status'] === 'aguardando_captacao') {
            db()->prepare('UPDATE demands SET status = \'aguardando_captacao\', assumed_by_user_id = NULL, assumed_at = NULL WHERE id = :id')
                ->execute(['id' => $id]);
        }
        
        dispatch_abort('Nenhuma instância WhatsApp está conectada. Verifique em: Configurações → WhatsApp Conexão → aba Conexão.');
    }
} catch (Throwable $e) {
    // registra erro em todos
    $upd = db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = \'error\', error_message = :err WHERE demand_id = :did AND dispatch_status = \'queued\'');
    $upd->execute(['err' => 'Evolution API não configurada: ' . mb_strimwidth($e->getMessage(), 0, 220, ''), 'did' => $id]);
    dispatch_abort('WhatsApp não configurado. Configure em: Configurações → WhatsApp Conexão → aba Credenciais.');
}

$selLogs = db()->prepare(
    'SELECT dl.id, dl.message, g.evolution_group_jid FROM demand_dispatch_logs dl LEFT JOIN whatsapp_groups g ON g.id = dl.group_id WHERE dl.demand_id = :did AND dl.dispatch_status = \'queued\''
);
$selLogs->execute(['did' => $id]);
$toSend = $selLogs->fetchAll();

// ============================================================================
// REMETENTE VERIFICADA: a instância escolhida acima pode ter vindo de um
// fallback "sem verificação" (desconectada / não autenticada). Enviar por ela
// gera "TypeError: Cannot read properties of undefined (reading 'id')" na
// Evolution. Trocamos por uma instância 'open' que enxerga o grupo.
// ============================================================================
$firstGroupJid = '';
foreach ($toSend as $tsRow) {
    $gj = (string)($tsRow['evolution_group_jid'] ?? '');
    if ($gj !== '') { $firstGroupJid = $gj; break; }
}
if ($firstGroupJid !== '') {
    $pickDiag = [];
    $picked = dispatch_pick_sender_for_group($firstGroupJid, $apiInstanceName, $instances ?? [], $baseUrl, $apiKey, $pickDiag);
    if ($picked !== null) {
        if ($picked[1] !== $apiInstanceName) {
            error_log("[DISPATCH] Remetente trocada de '$apiInstanceName' para '{$picked[1]}' (verificada: conectada e membro de $firstGroupJid).");
        }
        [$api, $apiInstanceName] = $picked;
    } else {
        error_log("[DISPATCH] Nenhuma instância verificada para $firstGroupJid: " . implode('; ', $pickDiag));
    }
}

// ============================================================================
// ADICIONAR MEMBROS ANTES DO ENVIO
// ----------------------------------------------------------------------------
// Ordem correta: (1) adiciona ao grupo quem ainda não está, (2) depois envia a
// mensagem. Antes a mensagem saía primeiro e quem entrava depois não a recebia.
// Só adiciona quem AINDA NÃO é membro (re-adicionar membros existentes ou o
// próprio número derruba a sessão na Evolution). Lotes de 50 com pausa curta,
// no mesmo job (sem fila extra) — o worker não tem limite de tempo.
// ============================================================================
$addedTotal = 0;
$addErrors = 0;
foreach ($memberTargets as $addJid => $addList) {
    $addList = array_values(array_unique($addList));
    if (empty($addList)) {
        continue;
    }
    try {
        $addPicked = dispatch_pick_sender_for_group((string)$addJid, $apiInstanceName, $instances ?? [], $baseUrl, $apiKey);
        if ($addPicked === null) {
            error_log("[DISPATCH] $addJid: nenhuma instância conectada no grupo para adicionar membros.");
            continue;
        }
        [$addApi, $addInstName] = $addPicked;
        $missing = dispatch_missing_members($addApi, (string)$addJid, $addList, dispatch_instance_number($addInstName));
        if (empty($missing)) {
            error_log("[DISPATCH] $addJid: todos os " . count($addList) . " números já são membros.");
            continue;
        }
        demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'adding_members', 'Adicionando ' . count($missing) . ' profissional(is) ao grupo antes de enviar...');
        foreach (array_chunk($missing, DEMAND_DISPATCH_BATCH_SIZE) as $chunkIdx => $chunk) {
            if ($chunkIdx > 0) {
                sleep(2); // respiro entre lotes para não derrubar a sessão
            }
            $addRes = $addApi->updateGroupMembers((string)$addJid, 'add', $chunk);
            $addCode = (int)($addRes['status'] ?? 0);
            if ($addCode >= 200 && $addCode < 300) {
                $addedTotal += count($chunk);
            } else {
                $addErrors += count($chunk);
                error_log("[DISPATCH] $addJid: falha ao adicionar lote via '$addInstName': " . dispatch_evolution_error_text($addRes));
            }
        }
        error_log("[DISPATCH] $addJid: $addedTotal novo(s) membro(s) adicionados antes do envio via '$addInstName'.");
    } catch (Throwable $addErr) {
        error_log("[DISPATCH] Erro ao adicionar membros em $addJid: " . $addErr->getMessage());
    }
}
if ($addedTotal > 0) {
    // Tempo para o WhatsApp distribuir as chaves do grupo aos novos membros;
    // sem isso a mensagem pode não ser entregue a quem acabou de entrar.
    sleep(3);
}
try {
    db()->prepare('UPDATE demand_dispatch_runs SET added_count = :a WHERE id = :id')->execute(['a' => $addedTotal, 'id' => $DISPATCH_RUN_ID]);
} catch (Throwable $e) {}

$updOne = db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = :st, error_message = :err WHERE id = :id');

// ============================================================================
// GARANTIR QUE A INSTÂNCIA REMETENTE SEJA ADMIN DO GRUPO
// ----------------------------------------------------------------------------
// Os grupos de captação são configurados como "announcement" (somente admins
// publicam). Se a instância que vai ENVIAR não for admin do grupo, a Evolution
// aceita a requisição (HTTP 2xx) mas o WhatsApp DESCARTA a mensagem em silêncio
// — foi exatamente o sintoma: "enviado" sem erro, mas nada chega no grupo.
// Aqui garantimos que o número da instância remetente ($apiInstanceName) seja
// membro e admin de cada grupo deste disparo, ANTES de enviar.
// ============================================================================
if ($api !== null && $apiInstanceName !== null && $apiInstanceName !== '') {
    try {
        // Descobrir o número (JID) da instância remetente.
        $stmtSenderNum = db()->prepare("SELECT owner_number, owner_phone_formatted FROM whatsapp_instances WHERE instance_name = ? LIMIT 1");
        $stmtSenderNum->execute([$apiInstanceName]);
        $senderRow = $stmtSenderNum->fetch();
        $senderPhone = '';
        if ($senderRow) {
            $senderPhone = trim((string)($senderRow['owner_phone_formatted'] ?? ''));
            if ($senderPhone === '') {
                $senderPhone = preg_replace('/\D+/', '', (string)($senderRow['owner_number'] ?? ''));
            } else {
                $senderPhone = preg_replace('/\D+/', '', $senderPhone);
            }
            if (strlen($senderPhone) === 10 || strlen($senderPhone) === 11) {
                $senderPhone = '55' . $senderPhone;
            }
        }

        if (strlen($senderPhone) >= 12) {
            $senderJid = $senderPhone . '@s.whatsapp.net';
            foreach ($toSend as $tsRow) {
                $gj = (string)($tsRow['evolution_group_jid'] ?? '');
                if ($gj === '') continue;
                try {
                    // SOMENTE LEITURA. Antes este bloco fazia add + promote do
                    // PRÓPRIO número da instância: além de inútil (ninguém se
                    // promove sem já ser admin), isso derrubava a sessão do
                    // WhatsApp e a instância ia para 'close' antes do envio.
                    $members = dispatch_group_member_keys($api, $gj);
                    $selfKey = dispatch_member_key($senderPhone);
                    if ($members !== null && empty($members[$selfKey])) {
                        error_log("[DISPATCH] ⚠️ Remetente '$apiInstanceName' ($senderPhone) NÃO é admin de '$gj'. Em grupo 'somente admins' a mensagem pode ser descartada — promova este número pelo celular de um admin.");
                    }
                } catch (Throwable $pErr) {
                    error_log("[DISPATCH] Falha ao checar admin do remetente em '$gj': " . $pErr->getMessage());
                }
            }
        } else {
            error_log("[DISPATCH] Não foi possível resolver o número da instância remetente '$apiInstanceName' para promover a admin.");
        }
    } catch (Throwable $sErr) {
        error_log("[DISPATCH] Erro ao garantir remetente admin: " . $sErr->getMessage());
    }
}

demand_dispatch_run_set_phase($DISPATCH_RUN_ID, 'sending', 'Enviando a mensagem de captação ao(s) grupo(s)...');

$sent = 0;
$errCount = 0;
$lastDispatchError = '';
foreach ($toSend as $row) {
    $logId = (int)$row['id'];
    $jid = (string)($row['evolution_group_jid'] ?? '');
    $msgRow = (string)($row['message'] ?? $msg);
    if ($jid === '') {
        $updOne->execute(['st' => 'error', 'err' => 'Grupo sem evolution_group_jid configurado.', 'id' => $logId]);
        $errCount++;
        continue;
    }

    try {
        // Só envia por instância conectada E membro deste grupo (ver
        // dispatch_pick_sender_for_group). Sem isso a Evolution responde
        // "TypeError ... reading 'id'" quando a instância não está autenticada.
        $sendDiag = [];
        $pickedRow = dispatch_pick_sender_for_group($jid, $apiInstanceName, $instances ?? [], $baseUrl, $apiKey, $sendDiag);
        if ($pickedRow === null) {
            $errText = 'Nenhuma instância conectada e membro do grupo. ' . implode('; ', $sendDiag);
            $lastDispatchError = $errText;
            error_log("[DISPATCH] $jid: $errText");
            $updOne->execute(['st' => 'error', 'err' => mb_strimwidth($errText, 0, 255, '...'), 'id' => $logId]);
            $errCount++;
            continue;
        }
        [$sendApi, $sendInstName] = $pickedRow;
        if ($sendInstName !== $apiInstanceName) {
            error_log("[DISPATCH] $jid será enviado pela instância '$sendInstName' (membro do grupo).");
        }
        // Registra a dona verificada para os próximos disparos priorizarem ela.
        try {
            db()->prepare('UPDATE whatsapp_groups SET instance_name = ? WHERE evolution_group_jid = ?')
                ->execute([$sendInstName, $jid]);
        } catch (Throwable $e) {}

        try {
            $res = $sendApi->sendTextToGroup($jid, $msgRow);
        } catch (Throwable $sendErr) {
            // Timeout/erro de rede: a Evolution pode ter enviado mesmo assim.
            // NÃO reenvia (duplicaria); confere pelo token no histórico do grupo.
            error_log("[DISPATCH] Envio para $jid via '$sendInstName' sem resposta: " . $sendErr->getMessage() . " — verificando se a mensagem chegou...");
            $capToken = preg_match('/#CAP\d+-[A-F0-9]+/', $msgRow, $tm) ? $tm[0] : '';
            $foundId = null;
            for ($chk = 0; $chk < 4 && $foundId === null; $chk++) {
                sleep(10);
                try {
                    $foundId = dispatch_find_sent_by_token($sendApi, $jid, $capToken);
                } catch (Throwable $chkErr) {
                    error_log("[DISPATCH] Falha ao verificar envio em $jid: " . $chkErr->getMessage());
                }
            }
            if ($foundId === null) {
                throw $sendErr; // não chegou: trata como erro (catch externo)
            }
            error_log("[DISPATCH] ✅ Mensagem confirmada no grupo $jid apesar do timeout (id=" . ($foundId !== '' ? $foundId : '?') . ").");
            $res = ['status' => 201, 'json' => $foundId !== '' ? ['key' => ['id' => $foundId]] : null];
        }
        $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
        
        // Se falhou e o JID não tem hífen (possível LID), tentar buscar JID real
        if (!$ok && strpos($jid, '-') === false) {
            error_log("[DISPATCH] Envio falhou para JID sem hífen ($jid). Buscando JID real via fetchAllGroups...");
            try {
                // Buscar nome do grupo no banco para fazer match
                $stmtGrpName = db()->prepare('SELECT name FROM whatsapp_groups WHERE evolution_group_jid = ?');
                $stmtGrpName->execute([$jid]);
                $grpNameRow = $stmtGrpName->fetch();
                $grpName = $grpNameRow ? $grpNameRow['name'] : '';
                
                if ($grpName !== '') {
                    $allGroupsRes = $sendApi->fetchAllGroups(false);
                    $allGroupsCode = (int)($allGroupsRes['status'] ?? 0);
                    if ($allGroupsCode >= 200 && $allGroupsCode < 300) {
                        $groupsList = $allGroupsRes['json'] ?? [];
                        foreach ($groupsList as $grp) {
                            $grpSubject = $grp['subject'] ?? ($grp['name'] ?? '');
                            $grpId = $grp['id'] ?? ($grp['jid'] ?? '');
                            if ($grpSubject === $grpName && $grpId !== '' && $grpId !== $jid) {
                                error_log("[DISPATCH] JID real encontrado: $grpId (antigo: $jid). Atualizando banco e reenviando...");
                                // Atualizar o JID no banco
                                db()->prepare('UPDATE whatsapp_groups SET evolution_group_jid = ? WHERE evolution_group_jid = ?')
                                    ->execute([$grpId, $jid]);
                                // Tentar enviar com o JID correto
                                usleep(500000);
                                $res = $sendApi->sendTextToGroup($grpId, $msgRow);
                                $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
                                if ($ok) {
                                    $jid = $grpId; // Usar JID correto para salvar em chat_messages
                                }
                                break;
                            }
                        }
                    }
                }
            } catch (Throwable $lookupErr) {
                error_log("[DISPATCH] Erro ao buscar JID real: " . $lookupErr->getMessage());
            }
        }

        if ($ok) {
            // Extrair o message_id retornado pela API (para vincular reações futuras)
            $externalMsgId = null;
            $jsonRes = $res['json'] ?? null;
            if (is_array($jsonRes) && isset($jsonRes['key']['id'])) {
                $externalMsgId = (string)$jsonRes['key']['id'];
            }
            
            $updOne->execute(['st' => 'sent', 'err' => null, 'id' => $logId]);
            
            // Salvar external_message_id para vincular reações
            if ($externalMsgId) {
                $stmtExtId = db()->prepare('UPDATE demand_dispatch_logs SET external_message_id = :eid WHERE id = :id');
                $stmtExtId->execute(['eid' => $externalMsgId, 'id' => $logId]);
            }
            
            $sent++;
            whatsapp_health_record((string)$sendInstName, 'send_ok', 'open', null);
            
            // Salvar na chat_messages para aparecer no chat
            try {
                $stmtChat = db()->prepare(
                    'INSERT INTO chat_messages (remote_jid, message_text, from_me, message_timestamp, external_message_id) VALUES (?, ?, 1, ?, ?)'
                );
                $stmtChat->execute([$jid, $msgRow, time(), $externalMsgId]);
            } catch (Throwable $chatErr) {
                error_log('[DISPATCH] Erro ao salvar em chat_messages: ' . $chatErr->getMessage());
            }
        } else {
            $errText = "[instância '$sendInstName'] " . dispatch_evolution_error_text($res);
            $lastDispatchError = $errText;
            error_log("[DISPATCH] Falha definitiva ao enviar para '$jid': $errText");
            $updOne->execute(['st' => 'error', 'err' => mb_strimwidth($errText, 0, 255, '...'), 'id' => $logId]);
            $errCount++;
            whatsapp_health_record((string)$sendInstName, 'send_error', null, $errText);
        }
    } catch (Throwable $e) {
        $lastDispatchError = $e->getMessage();
        if (isset($sendInstName) && $sendInstName !== '') {
            whatsapp_health_record((string)$sendInstName, 'send_error', null, $e->getMessage());
        }
        $updOne->execute(['st' => 'error', 'err' => mb_strimwidth($e->getMessage(), 0, 255, ''), 'id' => $logId]);
        $errCount++;
        continue;
    }
}

// ============================================================================
// ADIÇÕES ADIADAS: só depois de a mensagem sair, adiciona ao grupo quem AINDA
// NÃO é membro (nunca o próprio número da instância). Se ninguém faltar, não
// faz nenhuma chamada de adição — que é o que derrubava a sessão.
// ============================================================================
// (As adições de membros agora acontecem ANTES do envio — ver bloco
// "ADICIONAR MEMBROS ANTES DO ENVIO". Não há mais adição depois da mensagem.)

// Atualizar status da sub-solicitação somente se pelo menos um envio foi bem-sucedido
if ($subRequest !== null && $sent > 0) {
    $updSub = db()->prepare('UPDATE demand_sub_requests SET status = \'em_captacao\', dispatched_at = NOW() WHERE id = :id');
    $updSub->execute(['id' => $subRequestId]);
}

// Finaliza o run (background) com o resultado, lido pelo acompanhamento de progresso.
$groupNames = implode(', ', array_map(static fn($g) => (string)($g['name'] ?? ''), $groups));
$summary = ' Grupo: ' . $groupNames . '.'
    . ($addedTotal > 0 ? ' ' . $addedTotal . ' profissional(is) adicionado(s) antes do envio.' : '')
    . ($addErrors > 0 ? ' ' . $addErrors . ' não puderam ser adicionados.' : '');
if ($sent > 0 && $errCount === 0) {
    demand_dispatch_run_finish($DISPATCH_RUN_ID, 'completed', 'Captação enviada via WhatsApp.' . $summary, $sent, $errCount);
} elseif ($sent > 0) {
    demand_dispatch_run_finish($DISPATCH_RUN_ID, 'completed_with_errors', 'Captação enviada para alguns grupos, mas houve erros em outros.' . $summary, $sent, $errCount);
} else {
    $failMsg = 'Falha ao enviar captação via WhatsApp'
        . ($apiInstanceName ? " (instância '$apiInstanceName')" : '')
        . ($lastDispatchError !== '' ? ': ' . mb_strimwidth($lastDispatchError, 0, 300, '...') : '. Verifique os logs.');
    demand_dispatch_run_finish($DISPATCH_RUN_ID, 'failed', $failMsg, $sent, $errCount);
}

dispatch_stop();
