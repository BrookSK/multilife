<?php

declare(strict_types=1);

/**
 * Integração Zoho Forms (formulário de faturamento) → WhatsApp.
 *
 * Fluxo:
 *   1. O Zoho Forms envia um webhook (JSON ou form-urlencoded) para
 *      zoho_billing_webhook.php quando um formulário de faturamento é enviado.
 *   2. Extraímos os campos (nome do profissional, paciente, especialidade,
 *      nº de atendimentos e telefone com DDD).
 *   3. Montamos a mensagem a partir de um template configurável.
 *   4. Enviamos a mensagem via Evolution API para o telefone recebido.
 *
 * Em "modo teste" o telefone recebido é IGNORADO e a mensagem é enviada para
 * um telefone de teste configurado nas settings — útil para o botão
 * "Testar webhook" no admin sem incomodar o profissional real.
 *
 * Chaves de settings usadas (tabela admin_settings):
 *   - zoho_billing.webhook_token  Token secreto exigido no webhook (opcional).
 *   - zoho_billing.test_mode      '1' = modo teste ativo; '0' = produção.
 *   - zoho_billing.test_phone     Telefone (com DDD) usado quando em modo teste.
 *   - zoho_billing.template       Template da mensagem com placeholders {..}.
 */

/**
 * Template padrão da mensagem de faturamento.
 * Placeholders disponíveis:
 *   {professional_name}  Nome do profissional
 *   {patient_name}       Nome do paciente
 *   {specialty}          Especialidade
 *   {sessions_count}     Nº de atendimentos realizados
 */
function zoho_billing_default_template(): string
{
    return "👋 Olá, *Sr(a). {professional_name}*, esta é uma mensagem automática para confirmar seu envio do formulário de faturamento.\n\n"
        . "*Nome do profissional*: {professional_name}\n\n"
        . "*Nome do paciente*: {patient_name}\n\n"
        . "*Especialidade*: {specialty}\n\n"
        . "*Nº de atendimentos realizados*: {sessions_count}\n\n"
        . "Obrigado por fazer parte da MultiLife Care.";
}

/**
 * Retorna o template configurado, ou o padrão se não houver.
 */
function zoho_billing_get_template(): string
{
    $tpl = (string)admin_setting_get('zoho_billing.template', '');
    $tpl = trim($tpl);
    return $tpl !== '' ? $tpl : zoho_billing_default_template();
}

/**
 * Aliases aceitos para cada campo lógico. O Zoho pode enviar os nomes dos
 * campos de várias formas (rótulo do formulário, chave interna, etc.), então
 * tentamos vários nomes possíveis, de forma case-insensitive.
 *
 * @return array<string, string[]>
 */
function zoho_billing_field_aliases(): array
{
    return [
        'professional_name' => [
            'professional_name', 'professional', 'nome_do_profissional', 'nome_profissional',
            'profissional', 'nomeprofissional', 'name', 'nome',
        ],
        'patient_name' => [
            'patient_name', 'patient', 'nome_do_paciente', 'nome_paciente',
            'paciente', 'nomepaciente',
        ],
        'specialty' => [
            'specialty', 'especialidade', 'speciality',
        ],
        'sessions_count' => [
            'sessions_count', 'sessions', 'n_de_atendimentos_realizados', 'numero_de_atendimentos',
            'atendimentos', 'qtd_atendimentos', 'quantidade_de_atendimentos', 'num_atendimentos',
            'nde_atendimentos', 'atendimentos_realizados',
        ],
        'phone' => [
            'phone', 'telefone', 'celular', 'whatsapp', 'phone_number', 'telefone_com_ddd',
            'telefonecomddd', 'ddd', 'fone', 'mobile',
        ],
    ];
}

/**
 * Normaliza uma chave para comparação (minúsculo, sem acentos, sem separadores).
 */
function zoho_billing_normalize_key(string $key): string
{
    $key = strtolower(trim($key));
    // Remover acentos comuns.
    $from = ['á','à','ã','â','ä','é','ê','è','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç','º','°','ª'];
    $to   = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','','',''];
    $key = str_replace($from, $to, $key);
    // Remover tudo que não for letra/número.
    $key = preg_replace('/[^a-z0-9]+/', '', $key) ?? '';
    return $key;
}

/**
 * Achata um payload que pode vir aninhado (ex.: {"data": {...}}) em um mapa
 * simples chave→valor, com as chaves normalizadas.
 *
 * @param mixed $payload
 * @return array<string, string>
 */
function zoho_billing_flatten_payload($payload): array
{
    $out = [];
    if (!is_array($payload)) {
        return $out;
    }
    foreach ($payload as $k => $v) {
        if (is_array($v)) {
            // Lista de escalares (ex.: múltipla escolha ["Fonoaudiologia"] ou
            // uploads ["a.png","b.png"]): junta os valores numa única string
            // sob a própria chave (vírgula como separador).
            if (zoho_billing_is_scalar_list($v)) {
                $joined = implode(', ', array_map(
                    static fn($item) => is_scalar($item) ? trim((string)$item) : '',
                    $v
                ));
                $joined = trim(preg_replace('/(,\s*)+/', ', ', $joined) ?? '', ', ');
                $nk = zoho_billing_normalize_key((string)$k);
                if ($nk !== '' && !isset($out[$nk])) {
                    $out[$nk] = $joined;
                }
                continue;
            }
            // Objeto associativo aninhado (ex.: "data", "values"): mescla recursivamente.
            foreach (zoho_billing_flatten_payload($v) as $sk => $sv) {
                if (!isset($out[$sk])) {
                    $out[$sk] = $sv;
                }
            }
            continue;
        }
        $nk = zoho_billing_normalize_key((string)$k);
        if ($nk !== '' && !isset($out[$nk])) {
            $out[$nk] = is_scalar($v) ? (string)$v : '';
        }
    }
    return $out;
}

/**
 * Retorna true se o array é uma lista (chaves sequenciais 0..n) cujos itens
 * são todos escalares — típico de múltipla escolha e uploads do Zoho.
 *
 * @param array<mixed> $arr
 */
function zoho_billing_is_scalar_list(array $arr): bool
{
    if ($arr === []) {
        return true;
    }
    if (array_keys($arr) !== range(0, count($arr) - 1)) {
        return false;
    }
    foreach ($arr as $item) {
        if (!is_scalar($item)) {
            return false;
        }
    }
    return true;
}

/**
 * Extrai os campos lógicos do payload recebido do Zoho.
 *
 * @param mixed $payload  Array já decodificado (JSON) ou $_POST.
 * @return array{professional_name:string,patient_name:string,specialty:string,sessions_count:string,phone:string}
 */
function zoho_billing_extract_fields($payload): array
{
    $flat = zoho_billing_flatten_payload($payload);

    $result = [
        'professional_name' => '',
        'patient_name' => '',
        'specialty' => '',
        'sessions_count' => '',
        'phone' => '',
    ];

    // 1) Mapeamento por posição no formato padrão do Zoho Forms (Field_N).
    //    O flatten normaliza "Field_1" -> "field1".
    //    Layout do formulário de faturamento:
    //      Field_1 = Nome do profissional (primeiro nome)
    //      Field_2 = Sobrenome do profissional
    //      Field_3 = Telefone (com DDD)
    //      Field_4 = Nome do paciente (primeiro nome)
    //      Field_5 = Sobrenome do paciente
    //      Field_6 = Especialidade (múltipla escolha)
    //      Field_7 = Nº de atendimentos (Number)
    //      Field_8..Field_16 = uploads de imagem (ignorados)
    $hasZohoFields = isset($flat['field1']) || isset($flat['field3']) || isset($flat['field7']);
    if ($hasZohoFields) {
        $get = static fn(string $k): string => isset($flat[$k]) ? trim($flat[$k]) : '';

        $result['professional_name'] = zoho_billing_join_name($get('field1'), $get('field2'));
        $result['phone'] = $get('field3');
        $result['patient_name'] = zoho_billing_join_name($get('field4'), $get('field5'));
        $result['specialty'] = $get('field6');
        $result['sessions_count'] = $get('field7');
    }

    // 2) Fallback por aliases (rótulos amigáveis), preenchendo apenas o que
    //    ainda estiver vazio. Cobre configurações alternativas do webhook.
    $aliases = zoho_billing_field_aliases();
    foreach ($aliases as $field => $names) {
        if (($result[$field] ?? '') !== '') {
            continue;
        }
        foreach ($names as $name) {
            $nk = zoho_billing_normalize_key($name);
            if (isset($flat[$nk]) && trim($flat[$nk]) !== '') {
                $result[$field] = trim($flat[$nk]);
                break;
            }
        }
    }

    return $result;
}

/**
 * Junta primeiro nome e sobrenome, ignorando partes vazias e colapsando
 * espaços duplicados.
 */
function zoho_billing_join_name(string $first, string $last): string
{
    $full = trim($first . ' ' . $last);
    return (string)preg_replace('/\s+/', ' ', $full);
}

/**
 * Monta a mensagem final substituindo os placeholders do template.
 *
 * @param array<string,string> $fields
 */
function zoho_billing_build_message(array $fields, ?string $template = null): string
{
    $tpl = $template ?? zoho_billing_get_template();

    $replacements = [
        '{professional_name}' => $fields['professional_name'] ?? '',
        '{patient_name}' => $fields['patient_name'] ?? '',
        '{specialty}' => $fields['specialty'] ?? '',
        '{sessions_count}' => $fields['sessions_count'] ?? '',
        '{phone}' => $fields['phone'] ?? '',
    ];

    return strtr($tpl, $replacements);
}

/**
 * Payload de exemplo usado no botão "Testar webhook".
 * Usa o mesmo formato Field_N do Zoho Forms para exercitar o parsing real.
 *
 * @return array<string,string>
 */
function zoho_billing_sample_payload(): array
{
    return [
        'Field_1' => 'Ananda Carolline',   // profissional - primeiro nome
        'Field_2' => 'Brandão Luz',        // profissional - sobrenome
        'Field_3' => '(17) 99999-9999',    // telefone com DDD
        'Field_4' => 'Jorge',              // paciente - primeiro nome
        'Field_5' => 'Machado Mendes',     // paciente - sobrenome
        'Field_6' => 'Fonoaudiologia',     // especialidade
        'Field_7' => '9',                  // nº de atendimentos
    ];
}

/**
 * Resolve o telefone de destino final (em formato "55DDNUMERO", só dígitos).
 *
 * Em modo teste, o telefone recebido é descartado e usamos o telefone de teste
 * configurado. Retorna null se não houver um telefone válido.
 *
 * @param string $incomingPhone  Telefone recebido no webhook.
 * @param bool   $forceTest      Força o modo teste (usado pelo botão de teste).
 * @return array{phone:?string, test_mode:bool, source:string}
 */
function zoho_billing_resolve_destination(string $incomingPhone, bool $forceTest = false): array
{
    $testMode = $forceTest || admin_setting_get('zoho_billing.test_mode', '0') === '1';

    if ($testMode) {
        $testPhone = (string)admin_setting_get('zoho_billing.test_phone', '');
        $normalized = demand_dispatch_normalize_phone($testPhone);
        return [
            'phone' => $normalized,
            'test_mode' => true,
            'source' => 'test_phone',
        ];
    }

    $normalized = demand_dispatch_normalize_phone($incomingPhone);
    return [
        'phone' => $normalized,
        'test_mode' => false,
        'source' => 'incoming',
    ];
}

/**
 * Prepara um payload do Zoho para envio: extrai campos, resolve destino e
 * monta a mensagem — SEM enviar. Usado tanto pelo enfileiramento (webhook)
 * quanto pelo envio direto (teste síncrono).
 *
 * @param mixed $payload    Array decodificado do webhook (ou $_POST).
 * @param bool  $forceTest  Força modo teste (botão "Testar webhook").
 * @return array{fields:array, phone:?string, test_mode:bool, message_text:string}
 */
function zoho_billing_prepare(array $payload, bool $forceTest = false): array
{
    $fields = zoho_billing_extract_fields($payload);
    $dest = zoho_billing_resolve_destination($fields['phone'], $forceTest);

    return [
        'fields' => $fields,
        'phone' => $dest['phone'],
        'test_mode' => $dest['test_mode'],
        'message_text' => zoho_billing_build_message($fields),
    ];
}

/**
 * Enfileira o envio da mensagem de faturamento como um job de integração.
 *
 * O webhook deve chamar esta função em vez de enviar diretamente: assim ele
 * responde ao Zoho em milissegundos e o worker (cron/integration_jobs_run.php)
 * cuida do envio com retentativa e espaçamento — suportando muitos webhooks
 * simultâneos sem timeout nem duplicação.
 *
 * @param mixed $payload    Array decodificado do webhook (ou $_POST).
 * @param bool  $forceTest  Força modo teste.
 * @return array{queued:bool, message:string, job_id:?int, fields:array, phone:?string, test_mode:bool, message_text:string}
 */
function zoho_billing_enqueue(array $payload, bool $forceTest = false): array
{
    $prep = zoho_billing_prepare($payload, $forceTest);
    $phone = $prep['phone'];
    $testMode = $prep['test_mode'];

    if ($phone === null || $phone === '') {
        $reason = $testMode
            ? 'Telefone de teste não configurado ou inválido (configure em Configurações → Zoho Faturamento).'
            : 'Telefone recebido no webhook é inválido ou está ausente.';
        return [
            'queued' => false,
            'message' => $reason,
            'job_id' => null,
            'fields' => $prep['fields'],
            'phone' => null,
            'test_mode' => $testMode,
            'message_text' => $prep['message_text'],
        ];
    }

    // Deduplicação: se o Zoho disparar o mesmo formulário mais de uma vez em
    // sequência, evitamos enfileirar envios idênticos. Consideramos duplicado
    // um job com o MESMO destino + MESMA mensagem criado dentro da janela
    // configurável (setting zoho_billing.dedupe_window_seconds, padrão 600s).
    if (zoho_billing_is_duplicate($phone, $prep['message_text'])) {
        return [
            'queued' => false,
            'duplicate' => true,
            'message' => 'Envio ignorado: mensagem idêntica já enfileirada recentemente para ' . $phone . ' (proteção contra webhook duplicado).',
            'job_id' => null,
            'fields' => $prep['fields'],
            'phone' => $phone,
            'test_mode' => $testMode,
            'message_text' => $prep['message_text'],
        ];
    }

    // Espaçar os envios para não estourar rate limits da Evolution quando
    // muitos webhooks chegam juntos: cada job novo roda alguns segundos após
    // o anterior enfileirado nos últimos instantes.
    $nextRunAt = zoho_billing_next_slot();

    $jobId = integration_job_enqueue('evolution', 'zoho_billing_notify', [
        'phone' => $phone,
        'message' => $prep['message_text'],
        'test_mode' => $testMode,
        'professional_name' => $prep['fields']['professional_name'] ?? '',
    ], $nextRunAt);

    return [
        'queued' => true,
        'duplicate' => false,
        'message' => 'Envio enfileirado para ' . $phone . ($testMode ? ' (modo teste)' : '') . '.',
        'job_id' => $jobId,
        'fields' => $prep['fields'],
        'phone' => $phone,
        'test_mode' => $testMode,
        'message_text' => $prep['message_text'],
    ];
}

/**
 * Verifica se já existe um job zoho_billing_notify com o mesmo destino e a
 * mesma mensagem criado dentro da janela de deduplicação. Isso protege contra
 * o Zoho chamar o webhook mais de uma vez para o mesmo envio.
 */
function zoho_billing_is_duplicate(string $phone, string $message): bool
{
    $windowSeconds = (int)admin_setting_get('zoho_billing.dedupe_window_seconds', '600');
    if ($windowSeconds <= 0) {
        return false; // deduplicação desativada
    }

    try {
        $since = (new DateTime('now'))->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS c FROM integration_jobs
             WHERE provider = 'evolution' AND action = 'zoho_billing_notify'
               AND created_at >= :since
               AND status IN ('pending','running','success')
               AND payload LIKE :needle"
        );
        // Casa pelo par phone+message no JSON do payload.
        $needle = '%' . str_replace(['%', '_'], ['\%', '\_'], json_encode($phone, JSON_UNESCAPED_UNICODE)) . '%';
        // Refinamos comparando a mensagem também, via segundo LIKE em memória.
        $stmt->execute(['since' => $since, 'needle' => $needle]);
        $rows = (int)($stmt->fetch()['c'] ?? 0);
        if ($rows === 0) {
            return false;
        }

        // Confirmação precisa: buscar os payloads recentes desse telefone e
        // comparar a mensagem exata (evita falso positivo por telefones parecidos).
        $stmt2 = db()->prepare(
            "SELECT payload FROM integration_jobs
             WHERE provider = 'evolution' AND action = 'zoho_billing_notify'
               AND created_at >= :since
               AND status IN ('pending','running','success')
             ORDER BY id DESC LIMIT 50"
        );
        $stmt2->execute(['since' => $since]);
        foreach ($stmt2->fetchAll() as $r) {
            $p = json_decode((string)($r['payload'] ?? ''), true);
            if (is_array($p)
                && (string)($p['phone'] ?? '') === $phone
                && (string)($p['message'] ?? '') === $message) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

/**
 * Calcula o próximo horário de execução para espaçar os envios, olhando os
 * jobs zoho_billing_notify já pendentes. Espaça em passos configuráveis
 * (setting zoho_billing.send_interval_ms, padrão 1200ms).
 */
function zoho_billing_next_slot(): ?string
{
    $intervalMs = (int)admin_setting_get('zoho_billing.send_interval_ms', '1200');
    if ($intervalMs <= 0) {
        return null; // sem espaçamento
    }

    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS c FROM integration_jobs
             WHERE provider = 'evolution' AND action = 'zoho_billing_notify'
               AND status IN ('pending','error','running')"
        );
        $stmt->execute();
        $pending = (int)($stmt->fetch()['c'] ?? 0);
    } catch (Throwable $e) {
        $pending = 0;
    }

    // Se não há fila, roda imediatamente (null); senão empilha por posição.
    if ($pending <= 0) {
        return null;
    }

    $offsetSeconds = (int)ceil(($pending * $intervalMs) / 1000);
    if ($offsetSeconds <= 0) {
        return null;
    }
    return (new DateTime('now'))->modify("+{$offsetSeconds} seconds")->format('Y-m-d H:i:s');
}

/**
 * Verifica, EM TEMPO REAL na Evolution, se uma instância está conectada.
 * Retorna true se o estado for "open"/"connected". Usa cache estático curto
 * para não repetir a chamada dentro do mesmo processo.
 */
function zoho_billing_instance_is_connected(string $instanceName): bool
{
    static $cache = [];

    $instanceName = trim($instanceName);
    if ($instanceName === '') {
        return false;
    }
    if (array_key_exists($instanceName, $cache)) {
        return $cache[$instanceName];
    }

    $connected = false;
    try {
        $baseUrl = (string)admin_setting_get('evolution.base_url', '');
        $apiKey = (string)admin_setting_get('evolution.api_key', '');
        $evo = new EvolutionApiV1($baseUrl, $apiKey, $instanceName);
        $res = $evo->connectionState($instanceName);

        $status = (int)($res['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            $json = $res['json'] ?? [];
            $state = '';
            if (is_array($json)) {
                $state = (string)($json['state'] ?? ($json['instance']['state'] ?? ''));
            }
            $connected = in_array(strtolower($state), ['open', 'connected'], true);
        }
    } catch (Throwable $e) {
        $connected = false;
    }

    $cache[$instanceName] = $connected;
    return $connected;
}

/**
 * Localiza a instância "financeiro" (a única autorizada a enviar os avisos do
 * Zoho). Casa pelo apelido (display_name) ou nome (instance_name) contendo o
 * termo configurado em zoho_billing.preferred_instance (padrão "financeiro").
 *
 * @return array{id:int, instance_name:string, display_name:string, connection_status:string}|null
 */
function zoho_billing_financeiro_instance(): ?array
{
    $preferred = trim((string)admin_setting_get('zoho_billing.preferred_instance', 'financeiro'));
    if ($preferred === '') {
        return null;
    }

    try {
        $stmt = db()->prepare(
            "SELECT id, instance_name, display_name, connection_status
             FROM whatsapp_instances
             WHERE status = 'active'
               AND instance_name IS NOT NULL AND instance_name != ''
               AND (LOWER(display_name) LIKE :needle OR LOWER(instance_name) LIKE :needle2)
             ORDER BY (connection_status = 'connected') DESC, is_default DESC, id ASC
             LIMIT 1"
        );
        $like = '%' . mb_strtolower($preferred) . '%';
        $stmt->execute(['needle' => $like, 'needle2' => $like]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = false;
    }

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'instance_name' => (string)$row['instance_name'],
        'display_name' => (string)($row['display_name'] ?? ''),
        'connection_status' => (string)($row['connection_status'] ?? ''),
    ];
}

/**
 * Notifica in-app os usuários vinculados à instância financeiro que ela está
 * desconectada e, por isso, os avisos do Zoho não estão sendo enviados.
 *
 * Aplica um throttle (setting zoho_billing.disconnect_notice_throttle_seconds,
 * padrão 1800s = 30min) para não criar dezenas de notificações quando muitos
 * webhooks chegam juntos com a instância caída.
 *
 * @param array{id:int, instance_name:string, display_name:string}|null $instance
 */
function zoho_billing_notify_disconnected(?array $instance): void
{
    if ($instance === null || (int)$instance['id'] <= 0) {
        return;
    }

    $throttle = (int)admin_setting_get('zoho_billing.disconnect_notice_throttle_seconds', '1800');
    $throttleKey = 'zoho_billing.last_disconnect_notice_at';

    if ($throttle > 0) {
        $last = (string)admin_setting_get($throttleKey, '');
        if ($last !== '') {
            $lastTs = strtotime($last);
            if ($lastTs !== false && (time() - $lastTs) < $throttle) {
                return; // ainda dentro da janela — não notifica de novo
            }
        }
    }

    // Usuários vinculados à instância (N:N + dono legado).
    $userIds = whatsapp_instance_user_ids((int)$instance['id']);
    try {
        $stmt = db()->prepare("SELECT user_id FROM whatsapp_instances WHERE id = :id AND user_id IS NOT NULL");
        $stmt->execute(['id' => (int)$instance['id']]);
        $legacy = $stmt->fetchColumn();
        if ($legacy) {
            $userIds[] = (int)$legacy;
        }
    } catch (Throwable $e) {
        // segue com os IDs que já temos
    }
    $userIds = array_values(array_unique(array_filter($userIds, static fn($v) => (int)$v > 0)));

    if (count($userIds) === 0) {
        return;
    }

    $label = $instance['display_name'] !== '' ? $instance['display_name'] : $instance['instance_name'];
    $title = 'WhatsApp do Financeiro desconectado';
    $message = 'A conexão "' . $label . '" está desconectada e os avisos de faturamento do Zoho não estão sendo enviados. Reconecte pelo Meu WhatsApp.';

    foreach ($userIds as $uid) {
        try {
            notification_create((int)$uid, 'whatsapp', $title, $message, '/my_whatsapp.php');
        } catch (Throwable $e) {
            // não interrompe o fluxo por causa de uma notificação
        }
    }

    // Marca o horário do último aviso (para o throttle). Gravamos direto com
    // updated_by_user_id = NULL, pois este código roda no worker/cron (sem
    // usuário logado) e a coluna tem FK para users(id).
    try {
        db()->prepare(
            'INSERT INTO admin_settings (setting_key, setting_value, updated_by_user_id)
             VALUES (:k, :v, NULL)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['k' => $throttleKey, 'v' => date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // ignora falha ao gravar o throttle
    }
}

/**
 * Envia diretamente (síncrono) uma mensagem já montada para um telefone já
 * normalizado. Usado pelo worker e pelo teste. Lança exceção em falha (para
 * o worker acionar retry).
 *
 * REGRA: envia SOMENTE pela instância "financeiro". Não há fallback para
 * outras instâncias. Se a financeiro estiver desconectada, notifica os
 * usuários vinculados a ela e lança exceção (o job/teste falha).
 *
 * @param string      $phone
 * @param string      $message
 * @param string|null $preferInstance  (ignorado — mantido por compatibilidade)
 * @return array{success:bool, http_status:int, instance:string}
 */
function zoho_billing_send(string $phone, string $message, ?string $preferInstance = null): array
{
    $financeiro = zoho_billing_financeiro_instance();

    if ($financeiro === null) {
        throw new RuntimeException('Instância "financeiro" não encontrada. Verifique o apelido/nome e a configuração (Configurações → Zoho Faturamento).');
    }

    $instance = $financeiro['instance_name'];

    if (!zoho_billing_instance_is_connected($instance)) {
        // Notifica quem usa a financeiro para reconectar.
        zoho_billing_notify_disconnected($financeiro);
        throw new RuntimeException('A instância "' . $instance . '" (financeiro) está desconectada. Reconecte o WhatsApp para enviar os avisos do Zoho.');
    }

    $baseUrl = (string)admin_setting_get('evolution.base_url', '');
    $apiKey = (string)admin_setting_get('evolution.api_key', '');
    $evo = new EvolutionApiV1($baseUrl, $apiKey, $instance);
    $res = $evo->sendText($phone, $message);
    $status = (int)($res['status'] ?? 0);
    $ok = $status >= 200 && $status < 300;
    if (!$ok) {
        throw new RuntimeException('Evolution HTTP ' . $status . ' (instância "' . $instance . '")');
    }
    return ['success' => true, 'http_status' => $status, 'instance' => $instance];
}

/**
 * Processa um payload de forma SÍNCRONA (extrai, monta e envia na hora).
 * Mantido para o botão "Testar webhook", onde queremos feedback imediato.
 *
 * @param mixed $payload    Array decodificado do webhook (ou $_POST).
 * @param bool  $forceTest  Força modo teste (botão "Testar webhook").
 * @return array{success:bool, message:string, fields:array, phone:?string, test_mode:bool, http_status:?int, message_text:?string}
 */
function zoho_billing_process(array $payload, bool $forceTest = false): array
{
    $prep = zoho_billing_prepare($payload, $forceTest);
    $phone = $prep['phone'];
    $testMode = $prep['test_mode'];
    $messageText = $prep['message_text'];

    if ($phone === null || $phone === '') {
        $reason = $testMode
            ? 'Telefone de teste não configurado ou inválido (configure em Configurações → Zoho Faturamento).'
            : 'Telefone recebido no webhook é inválido ou está ausente.';
        return [
            'success' => false,
            'message' => $reason,
            'fields' => $prep['fields'],
            'phone' => null,
            'test_mode' => $testMode,
            'http_status' => null,
            'message_text' => $messageText,
        ];
    }

    try {
        $res = zoho_billing_send($phone, $messageText);
    } catch (Throwable $e) {
        return [
            'success' => false,
            'message' => 'Erro ao enviar via Evolution API: ' . $e->getMessage(),
            'fields' => $prep['fields'],
            'phone' => $phone,
            'test_mode' => $testMode,
            'http_status' => null,
            'message_text' => $messageText,
        ];
    }

    return [
        'success' => true,
        'message' => 'Mensagem enviada com sucesso para ' . $phone . ' pela instância "' . $res['instance'] . '"' . ($testMode ? ' (modo teste)' : '') . '.',
        'fields' => $prep['fields'],
        'phone' => $phone,
        'test_mode' => $testMode,
        'http_status' => $res['http_status'],
        'message_text' => $messageText,
    ];
}

/**
 * Log simples em arquivo para depuração do webhook do Zoho.
 */
function zoho_billing_log(string $message): void
{
    $logFile = dirname(__DIR__) . '/logs/zoho_billing_webhook.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}
