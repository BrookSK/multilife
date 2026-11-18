<?php

declare(strict_types=1);

/**
 * WATCHDOG DE CONEXÃO REAL (Evolution) + TRAVA DE NÚMERO DUPLICADO.
 *
 * Problema: a Evolution mostra a instância como "open" lendo o estado em
 * memória. Quando o socket do WhatsApp morre pela metade (zumbi), ela continua
 * "conectada", o envio parece funcionar, mas nada chega (mensagem, reação...).
 *
 * O watchdog (serviço da infra, porta 8083, doc watchdog-evolution-multilife.md)
 * observa o fluxo real de eventos de cada instância e devolve um veredito:
 *   healthy | silent (zumbi) | warming_up | awaiting_scan (QR) | dormant | unknown
 * e uma ação de recuperação (heal = restart sem QR).
 *
 * Aqui:
 *  - lemos o veredito (cache de 60 s na tabela whatsapp_instance_monitor);
 *  - fluxo automático (wa_watchdog_tick): silent → 1 heal automático; se depois
 *    de 5 min continuar silent → alerta humano; awaiting_scan por 5 min → alerta;
 *    watchdog fora do ar por 5 min → alerta para os administradores;
 *  - barramos o MESMO número conectado em duas instâncias (wa_pairing_guard):
 *    quando um QR Code gerado pelo sistema é lido com um número que já está em
 *    outra instância, a conexão nova é desfeita e o motivo aparece na tela.
 *
 * O watchdog atende outras empresas na mesma Evolution: só olhamos/agimos nas
 * instâncias ativas cadastradas em whatsapp_instances.
 *
 * Quem roda o fluxo: o polling de notificações (a cada 30 s em qualquer tela
 * aberta, no máximo 1 rodada por minuto para o sistema todo) e, se agendado,
 * cron/whatsapp_watchdog.php (cobre os horários sem ninguém logado).
 */

const WA_WATCHDOG_DEFAULT_URL = 'http://177.104.185.7:8083';
const WA_WATCHDOG_GLOBAL_ROW = '__watchdog__';
/** Intervalo mínimo entre rodadas do fluxo automático (segundos). */
const WA_WATCHDOG_TICK_SEC = 60;
/** Idade máxima do cache para ser mostrado na tela (segundos). */
const WA_WATCHDOG_FRESH_SEC = 600;
/** Espera entre heals da mesma instância e antes de escalar (segundos). */
const WA_WATCHDOG_HEAL_COOLDOWN_SEC = 300;
const WA_WATCHDOG_ESCALATE_SEC = 300;
/**
 * Uma reconexão automática por instância nesta janela (segundos). Se a mesma
 * instância voltar a ficar sem eventos dentro dela, vai direto para o alerta
 * humano (evita reiniciar de 30 em 30 min uma sessão que precisa de QR).
 */
const WA_WATCHDOG_AUTO_HEAL_WINDOW_SEC = 3 * 3600;
/** Heals automáticos por rodada (cada um pode levar até 35 s). */
const WA_WATCHDOG_MAX_HEALS_PER_TICK = 2;
/** Validade da marca "QR gerado pelo sistema" (minutos). */
const WA_PAIRING_WINDOW_MIN = 30;

// --------------------------------------------------------------------------
// Configuração e schema
// --------------------------------------------------------------------------

/** @return array{url:string, token:string, auto_heal:bool} */
function wa_watchdog_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $url = '';
    $token = '';
    $autoHeal = '1';
    try {
        $url = trim((string)admin_setting_get('evolution.watchdog_url', ''));
        $token = trim((string)admin_setting_get('evolution.watchdog_token', ''));
        $autoHeal = trim((string)admin_setting_get('evolution.watchdog_auto_heal', '1'));
    } catch (Throwable $e) {
        error_log('[WATCHDOG] config: ' . $e->getMessage());
    }
    // Variável de ambiente tem prioridade (token fora do banco, se a infra preferir)
    $envToken = getenv('EVO_WATCHDOG_TOKEN');
    if (is_string($envToken) && trim($envToken) !== '') {
        $token = trim($envToken);
    }
    $envUrl = getenv('EVO_WATCHDOG_URL');
    if (is_string($envUrl) && trim($envUrl) !== '') {
        $url = trim($envUrl);
    }
    if ($url === '') {
        $url = WA_WATCHDOG_DEFAULT_URL;
    }
    return $cfg = ['url' => rtrim($url, '/'), 'token' => $token, 'auto_heal' => $autoHeal !== '0'];
}

/** Watchdog configurado (tem token)? Sem token o sistema segue como antes. */
function wa_watchdog_enabled(): bool
{
    return wa_watchdog_config()['token'] !== '';
}

function wa_watchdog_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_instance_monitor (
            instance_name VARCHAR(100) NOT NULL,
            verdict VARCHAR(20) NULL,
            evo_state VARCHAR(20) NULL,
            last_event_seconds INT NULL,
            last_heal_seconds INT NULL,
            checked_at DATETIME NULL,
            verdict_since DATETIME NULL,
            auto_heal_at DATETIME NULL,
            alert_kind VARCHAR(30) NULL,
            alert_sent_at DATETIME NULL,
            actions_at DATETIME NULL,
            fail_at DATETIME NULL,
            pairing_requested_at DATETIME NULL,
            duplicate_note VARCHAR(500) NULL,
            duplicate_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (instance_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[WATCHDOG] ensure: ' . $e->getMessage());
    }
}

/**
 * Todas as linhas do monitor (uma consulta por requisição; gravações limpam o cache).
 *
 * @return array<string,array>
 */
function wa_monitor_rows(bool $reset = false): array
{
    static $rows = null;
    if ($reset) {
        $rows = null;
        return [];
    }
    if ($rows !== null) {
        return $rows;
    }
    wa_watchdog_ensure_schema();
    $rows = [];
    try {
        foreach (db()->query('SELECT * FROM whatsapp_instance_monitor')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(string)$r['instance_name']] = $r;
        }
    } catch (Throwable $e) {
        error_log('[WATCHDOG] monitor_rows: ' . $e->getMessage());
    }
    return $rows;
}

/** Linha do monitor (ou null). */
function wa_monitor_row(string $instanceName): ?array
{
    return wa_monitor_rows()[$instanceName] ?? null;
}

/** Grava campos na linha do monitor (cria a linha se não existir). */
function wa_monitor_set(string $instanceName, array $fields): void
{
    if ($instanceName === '' || !$fields) {
        return;
    }
    wa_watchdog_ensure_schema();
    $allowed = ['verdict', 'evo_state', 'last_event_seconds', 'last_heal_seconds', 'checked_at', 'verdict_since',
        'auto_heal_at', 'alert_kind', 'alert_sent_at', 'actions_at', 'fail_at', 'pairing_requested_at',
        'duplicate_note', 'duplicate_at'];
    $cols = [];
    $vals = [];
    $upd = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) {
            continue;
        }
        $cols[] = $k;
        $vals[] = $v;
        $upd[] = $k . ' = VALUES(' . $k . ')';
    }
    if (!$cols) {
        return;
    }
    try {
        $sql = 'INSERT INTO whatsapp_instance_monitor (instance_name, ' . implode(', ', $cols) . ') VALUES (?'
            . str_repeat(', ?', count($cols)) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $upd);
        db()->prepare($sql)->execute(array_merge([$instanceName], $vals));
    } catch (Throwable $e) {
        error_log('[WATCHDOG] monitor_set: ' . $e->getMessage());
    }
    wa_monitor_rows(true);
}

// --------------------------------------------------------------------------
// HTTP com o watchdog
// --------------------------------------------------------------------------

/** @return array{ok:bool, status:int, json:?array, error:string} */
function wa_watchdog_request(string $method, string $path, int $timeoutSeconds): array
{
    $cfg = wa_watchdog_config();
    if ($cfg['token'] === '') {
        return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'watchdog sem token configurado'];
    }
    try {
        $res = http_json_request($method, $cfg['url'] . $path, ['x-token' => $cfg['token']], null, $timeoutSeconds);
    } catch (Throwable $e) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'error' => $e->getMessage()];
    }
    $status = (int)($res['status'] ?? 0);
    $json = is_array($res['json'] ?? null) ? $res['json'] : null;
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'json' => $json,
        'error' => $status >= 200 && $status < 300 ? '' : (string)($json['error'] ?? ('HTTP ' . $status)),
    ];
}

/**
 * Vereditos de todas as instâncias da Evolution (uma chamada só).
 *
 * @return array<string,array>|null  nome => {verdict,state,lastEventSecondsAgo,lastHealSecondsAgo}; null = falhou
 */
function wa_watchdog_fetch_all(): ?array
{
    $res = wa_watchdog_request('GET', '/status', 8);
    if (!$res['ok'] || !is_array($res['json']['instances'] ?? null)) {
        if ($res['error'] !== '') {
            error_log('[WATCHDOG] GET /status falhou: ' . $res['error']);
        }
        return null;
    }
    $out = [];
    foreach ($res['json']['instances'] as $i) {
        if (is_array($i) && (string)($i['name'] ?? '') !== '') {
            $out[(string)$i['name']] = $i;
        }
    }
    return $out;
}

/**
 * Pede a reconexão (restart sem QR) de uma instância.
 * O watchdog recusa se a instância estiver saudável, desativada ou aguardando QR.
 *
 * @return array{ok:bool, message:string, status:int}
 */
function wa_watchdog_heal(string $instanceName): array
{
    $res = wa_watchdog_request('POST', '/heal/' . rawurlencode($instanceName), 35);
    $json = $res['json'] ?? [];
    $ok = $res['ok'] && !empty($json['ok']);
    $reason = trim((string)($json['reason'] ?? ($json['message'] ?? ($json['error'] ?? ''))));
    if ($reason === '' && !$ok) {
        $reason = $res['error'] !== '' ? $res['error'] : 'falha desconhecida';
    }
    try {
        integration_log('evolution', 'POST /heal/' . $instanceName . ' (watchdog)', $ok ? 'success' : 'error', $res['status'] ?: null,
            ['instance' => $instanceName], $json ?: null, $ok ? null : mb_strimwidth($reason, 0, 250, ''), 1);
    } catch (Throwable $e) {}
    if (function_exists('whatsapp_health_record')) {
        whatsapp_health_record($instanceName, 'heal', $ok ? 'restart' : 'refused', mb_strimwidth($reason, 0, 250, ''));
    }
    return ['ok' => $ok, 'message' => $reason, 'status' => (int)$res['status']];
}

// --------------------------------------------------------------------------
// Snapshot (cache) dos vereditos
// --------------------------------------------------------------------------

/**
 * Instâncias ativas do sistema (as únicas que olhamos no watchdog).
 *
 * @return array<string,array> nome => linha de whatsapp_instances
 */
function wa_watchdog_system_instances(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    return $cache = wa_watchdog_system_instances_load();
}

/** @return array<string,array> */
function wa_watchdog_system_instances_load(): array
{
    try {
        $st = db()->query("SELECT id, instance_name, display_name, user_id, owner_number, owner_phone_formatted,
                connection_status, is_default, created_at
            FROM whatsapp_instances WHERE status = 'active'");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (string)($r['instance_name'] ?? '');
            if ($n !== '') {
                $out[$n] = $r;
            }
        }
        return $out;
    } catch (Throwable $e) {
        // Bases antigas sem display_name
        try {
            $st = db()->query("SELECT id, instance_name, user_id, owner_number, owner_phone_formatted, connection_status, is_default, created_at
                FROM whatsapp_instances WHERE status = 'active'");
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['instance_name']] = $r;
            }
            return $out;
        } catch (Throwable $e2) {
            error_log('[WATCHDOG] system_instances: ' . $e2->getMessage());
            return [];
        }
    }
}

/** Nome amigável: apelido (instância) ou só a instância. */
function wa_instance_label(string $instanceName, ?array $row = null): string
{
    $alias = trim((string)($row['display_name'] ?? ''));
    return $alias !== '' && $alias !== $instanceName ? $alias . ' (' . $instanceName . ')' : $instanceName;
}

/**
 * Lê o watchdog e grava o snapshot das instâncias do sistema.
 * Também sincroniza whatsapp_instances.connection_status com o estado real.
 *
 * @param bool $force ignora o cache (mínimo de 15 s entre leituras)
 * @return bool true se o snapshot foi atualizado agora
 */
function wa_watchdog_refresh(bool $force = false): bool
{
    if (!wa_watchdog_enabled()) {
        return false;
    }
    wa_watchdog_ensure_schema();
    $g = wa_monitor_row(WA_WATCHDOG_GLOBAL_ROW);
    $now = time();
    $lastCheck = $g && $g['checked_at'] ? strtotime((string)$g['checked_at']) : 0;
    $lastFail = $g && $g['fail_at'] ? strtotime((string)$g['fail_at']) : 0;
    $minAge = $force ? 15 : 45;
    if ($now - $lastCheck < $minAge) {
        return false;
    }
    // Watchdog falhou há pouco: não trava as telas tentando de novo a cada acesso
    if ($lastFail > $lastCheck && $now - $lastFail < 60) {
        return false;
    }

    $all = wa_watchdog_fetch_all();
    $nowSql = date('Y-m-d H:i:s', $now);
    if ($all === null) {
        $fields = ['fail_at' => $nowSql];
        if (($g['verdict'] ?? '') !== 'down') {
            $fields['verdict'] = 'down';
            $fields['verdict_since'] = $nowSql;
            $fields['alert_kind'] = null;
        }
        wa_monitor_set(WA_WATCHDOG_GLOBAL_ROW, $fields);
        return false;
    }

    $instances = wa_watchdog_system_instances();
    $known = 0;
    foreach ($instances as $name => $inst) {
        $w = $all[$name] ?? null;
        $verdict = $w ? (string)($w['verdict'] ?? 'unknown') : 'unknown';
        $state = $w ? (string)($w['state'] ?? '') : '';
        if ($verdict !== 'unknown') {
            $known++;
        }
        $old = wa_monitor_row($name);
        $fields = [
            'verdict' => $verdict,
            'evo_state' => $state !== '' ? mb_substr($state, 0, 20) : null,
            'last_event_seconds' => isset($w['lastEventSecondsAgo']) && is_numeric($w['lastEventSecondsAgo']) ? (int)$w['lastEventSecondsAgo'] : null,
            'last_heal_seconds' => isset($w['lastHealSecondsAgo']) && is_numeric($w['lastHealSecondsAgo']) ? (int)$w['lastHealSecondsAgo'] : null,
            'checked_at' => $nowSql,
        ];
        if (!$old || (string)($old['verdict'] ?? '') !== $verdict) {
            $fields['verdict_since'] = $nowSql;
        }
        // Episódio de problema terminou: o próximo problema gera alerta de novo.
        // auto_heal_at fica (vale a janela WA_WATCHDOG_AUTO_HEAL_WINDOW_SEC).
        if (in_array($verdict, ['healthy', 'warming_up', 'dormant'], true) && $old && $old['alert_kind'] !== null) {
            $fields['alert_kind'] = null;
            $fields['alert_sent_at'] = null;
        }
        wa_monitor_set($name, $fields);

        // Status gravado no sistema segue o estado real da Evolution
        $map = ['open' => 'connected', 'close' => 'disconnected', 'connecting' => 'connecting'];
        if (isset($map[$state]) && (string)($inst['connection_status'] ?? '') !== $map[$state]) {
            try {
                whatsapp_update_connection_status($name, $map[$state]);
            } catch (Throwable $e) {}
        }
    }

    // Evolution inteira fora (watchdog responde, mas não conhece nenhuma das nossas)
    $globalVerdict = ($instances && $known === 0) ? 'down' : 'ok';
    $gFields = ['checked_at' => $nowSql];
    if (($g['verdict'] ?? '') !== $globalVerdict) {
        $gFields['verdict'] = $globalVerdict;
        $gFields['verdict_since'] = $nowSql;
        $gFields['alert_kind'] = null;
        $gFields['alert_sent_at'] = null;
    }
    wa_monitor_set(WA_WATCHDOG_GLOBAL_ROW, $gFields);
    return true;
}

/**
 * Veredito em cache de uma instância (null se não houver dado recente).
 *
 * @return array{instance:string, verdict:string, state:string, last_event_seconds:?int, checked_at:string, verdict_since:string, auto_heal_at:?string, alert_kind:?string}|null
 */
function wa_watchdog_get(string $instanceName): ?array
{
    if (!wa_watchdog_enabled() || $instanceName === '') {
        return null;
    }
    $r = wa_monitor_row($instanceName);
    if (!$r || !$r['checked_at'] || (string)$r['verdict'] === '') {
        return null;
    }
    $age = time() - strtotime((string)$r['checked_at']);
    if ($age > WA_WATCHDOG_FRESH_SEC) {
        return null;
    }
    $lastEvent = $r['last_event_seconds'] !== null ? (int)$r['last_event_seconds'] + max(0, $age) : null;
    return [
        'instance' => $instanceName,
        'verdict' => (string)$r['verdict'],
        'state' => (string)($r['evo_state'] ?? ''),
        'last_event_seconds' => $lastEvent,
        'checked_at' => (string)$r['checked_at'],
        'verdict_since' => (string)($r['verdict_since'] ?? ''),
        'auto_heal_at' => $r['auto_heal_at'] !== null ? (string)$r['auto_heal_at'] : null,
        'alert_kind' => $r['alert_kind'] !== null ? (string)$r['alert_kind'] : null,
    ];
}

/**
 * Texto e cores de cada veredito (mesmo padrão em todas as telas).
 *
 * @return array{label:string, color:string, bg:string, dot:string, hint:string}
 */
function wa_watchdog_verdict_view(string $verdict): array
{
    switch ($verdict) {
        case 'healthy':
            return ['label' => 'Conectado', 'color' => '#166534', 'bg' => '#dcfce7', 'dot' => '#22c55e',
                'hint' => 'Conexão recebendo eventos normalmente.'];
        case 'silent':
            return ['label' => 'Suspeita de queda', 'color' => '#9a3412', 'bg' => '#ffedd5', 'dot' => '#f97316',
                'hint' => 'Aparece como conectada, mas nenhum evento chega há mais de 30 min. Mensagens recebidas podem não estar entrando no sistema.'];
        case 'awaiting_scan':
            return ['label' => 'Aguardando QR Code', 'color' => '#854d0e', 'bg' => '#fef9c3', 'dot' => '#eab308',
                'hint' => 'A conexão caiu e só volta lendo o QR Code com o celular deste número.'];
        case 'dormant':
            return ['label' => 'Desativada', 'color' => '#475569', 'bg' => '#f1f5f9', 'dot' => '#94a3b8',
                'hint' => 'Desconectada na Evolution. Para usar, conecte lendo o QR Code.'];
        case 'warming_up':
            return ['label' => 'Monitorando', 'color' => '#475569', 'bg' => '#f1f5f9', 'dot' => '#94a3b8',
                'hint' => 'Conexão recém-iniciada ou reconectada: o monitor ainda está coletando dados.'];
        default:
            return ['label' => 'Sem dados', 'color' => '#991b1b', 'bg' => '#fee2e2', 'dot' => '#ef4444',
                'hint' => 'O monitor não encontrou esta instância ou a Evolution está fora do ar.'];
    }
}

/** "há 12 min", "há 2 h 5 min". */
function wa_watchdog_ago(?int $seconds): string
{
    if ($seconds === null) {
        return '';
    }
    if ($seconds < 90) {
        return 'há ' . max(0, $seconds) . ' s';
    }
    $min = (int)round($seconds / 60);
    if ($min < 60) {
        return 'há ' . $min . ' min';
    }
    $h = intdiv($min, 60);
    $m = $min % 60;
    return 'há ' . $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
}

/** Dados do veredito para as respostas JSON (status das telas de conexão). */
function wa_watchdog_public(string $instanceName): ?array
{
    $wd = wa_watchdog_get($instanceName);
    if (!$wd) {
        return null;
    }
    $v = wa_watchdog_verdict_view($wd['verdict']);
    return [
        'verdict' => $wd['verdict'],
        'state' => $wd['state'],
        'label' => $v['label'],
        'hint' => $v['hint'],
        'color' => $v['color'],
        'bg' => $v['bg'],
        'dot' => $v['dot'],
        'last_event' => wa_watchdog_ago($wd['last_event_seconds']),
        'can_heal' => $wd['verdict'] === 'silent',
        'auto_heal_at' => $wd['auto_heal_at'] ? date('H:i', strtotime($wd['auto_heal_at'])) : null,
    ];
}

// --------------------------------------------------------------------------
// Fluxo automático
// --------------------------------------------------------------------------

/**
 * Rodada do fluxo automático (no máximo 1 por minuto para o sistema todo).
 * Silenciosa: nunca quebra a requisição que a chamou.
 *
 * @return string resumo (para o cron)
 */
function wa_watchdog_tick(bool $force = false): string
{
    if (!wa_watchdog_enabled()) {
        return 'watchdog sem token: nada a fazer';
    }
    try {
        wa_watchdog_ensure_schema();
        $g = wa_monitor_row(WA_WATCHDOG_GLOBAL_ROW);
        $lastRun = $g && $g['actions_at'] ? strtotime((string)$g['actions_at']) : 0;
        if (!$force && time() - $lastRun < WA_WATCHDOG_TICK_SEC) {
            return 'rodada recente: aguardando';
        }
        $lock = db()->query("SELECT GET_LOCK('ml_wa_watchdog', 0)")->fetchColumn();
        if ((string)$lock !== '1') {
            return 'outra rodada em andamento';
        }
        try {
            wa_monitor_rows(true); // relê depois de pegar a trava
            $g = wa_monitor_row(WA_WATCHDOG_GLOBAL_ROW);
            $lastRun = $g && $g['actions_at'] ? strtotime((string)$g['actions_at']) : 0;
            if (!$force && time() - $lastRun < WA_WATCHDOG_TICK_SEC) {
                return 'rodada recente: aguardando';
            }
            wa_monitor_set(WA_WATCHDOG_GLOBAL_ROW, ['actions_at' => date('Y-m-d H:i:s')]);
            if (function_exists('set_time_limit')) {
                @set_time_limit(150);
            }
            return wa_watchdog_run_actions();
        } finally {
            db()->query("SELECT RELEASE_LOCK('ml_wa_watchdog')");
        }
    } catch (Throwable $e) {
        error_log('[WATCHDOG] tick: ' . $e->getMessage());
        return 'erro: ' . $e->getMessage();
    }
}

/** Executa o fluxo 5.4 da documentação do watchdog. */
function wa_watchdog_run_actions(): string
{
    $log = [];
    wa_watchdog_refresh(true);
    $cfg = wa_watchdog_config();
    $now = time();

    // Watchdog / Evolution fora do ar há 5 min → avisa os administradores (1 vez)
    $g = wa_monitor_row(WA_WATCHDOG_GLOBAL_ROW);
    if ($g && (string)$g['verdict'] === 'down') {
        $since = $g['verdict_since'] ? strtotime((string)$g['verdict_since']) : $now;
        if ($now - $since >= WA_WATCHDOG_ESCALATE_SEC && (string)($g['alert_kind'] ?? '') !== 'down') {
            wa_watchdog_notify(null, 'down', 'Monitor do WhatsApp sem resposta',
                'O monitor de conexões da Evolution (watchdog) não responde, ou a Evolution está fora do ar, desde '
                . date('H:i', $since) . '. Avise a equipe de infraestrutura.');
            wa_monitor_set(WA_WATCHDOG_GLOBAL_ROW, ['alert_kind' => 'down', 'alert_sent_at' => date('Y-m-d H:i:s')]);
            $log[] = 'alerta: watchdog/evolution fora do ar';
        }
        return $log ? implode("\n", $log) : 'watchdog sem resposta';
    }

    $instances = wa_watchdog_system_instances();
    $heals = 0;
    $evoList = null; // fetchInstances só se alguma conexão nova precisar de conferência
    foreach ($instances as $name => $inst) {
        $r = wa_monitor_row($name);
        if (!$r || !$r['checked_at'] || $now - strtotime((string)$r['checked_at']) > 180) {
            continue;
        }
        $verdict = (string)$r['verdict'];
        $since = $r['verdict_since'] ? strtotime((string)$r['verdict_since']) : $now;
        $label = wa_instance_label($name, $inst);

        // Conexão nova (QR lido) ainda não conferida: trava de número duplicado
        if ((string)$r['evo_state'] === 'open' && wa_pairing_pending($name)) {
            if ($evoList === null) {
                $evoList = wa_evolution_instances_list();
            }
            $guard = wa_pairing_guard($name, null, $evoList);
            if ($guard && $guard['blocked']) {
                $log[] = $name . ': conexão desfeita (número duplicado)';
                continue;
            }
        }

        if ($verdict === 'silent') {
            $lastHeal = $r['last_heal_seconds'] !== null ? (int)$r['last_heal_seconds'] : null;
            $autoHealAt = $r['auto_heal_at'] ? strtotime((string)$r['auto_heal_at']) : 0;
            $recentHeal = $autoHealAt > 0 && $now - $autoHealAt < WA_WATCHDOG_AUTO_HEAL_WINDOW_SEC;
            if ($cfg['auto_heal'] && !$recentHeal) {
                if (($lastHeal === null || $lastHeal >= WA_WATCHDOG_HEAL_COOLDOWN_SEC) && $heals < WA_WATCHDOG_MAX_HEALS_PER_TICK) {
                    $heals++;
                    $res = wa_watchdog_heal($name);
                    $healFields = ['auto_heal_at' => date('Y-m-d H:i:s')];
                    if ($res['ok']) {
                        // Até a próxima leitura: "Monitorando" (não "Suspeita de queda")
                        $healFields['verdict'] = 'warming_up';
                        $healFields['verdict_since'] = date('Y-m-d H:i:s');
                    }
                    wa_monitor_set($name, $healFields);
                    $log[] = $name . ': reconexão automática ' . ($res['ok'] ? 'disparada' : 'recusada (' . $res['message'] . ')');
                }
                continue;
            }
            // 5 min depois da reconexão (ou do início do problema, se ela foi antes / está desligada)
            $ref = $recentHeal ? max($autoHealAt, $since) : $since;
            $escalate = $now - $ref >= WA_WATCHDOG_ESCALATE_SEC;
            if ($escalate && (string)($r['alert_kind'] ?? '') !== 'silent') {
                $agoTxt = wa_watchdog_ago($r['last_event_seconds'] !== null ? (int)$r['last_event_seconds'] : null);
                $msg = $recentHeal
                    ? 'A conexão aparece como conectada, mas não chega nenhum evento ' . $agoTxt
                        . '. A reconexão automática (' . date('H:i', $autoHealAt) . ') não resolveu. Provavelmente é preciso ler o QR Code de novo: Meu WhatsApp → Reconectar.'
                    : 'A conexão aparece como conectada, mas não chega nenhum evento ' . $agoTxt
                        . '. Mensagens recebidas podem não estar entrando no sistema. Use "Reconectar" no aviso do topo da tela.';
                wa_watchdog_notify($name, 'silent', 'WhatsApp "' . $label . '" sem receber mensagens', $msg);
                wa_monitor_set($name, ['alert_kind' => 'silent', 'alert_sent_at' => date('Y-m-d H:i:s')]);
                $log[] = $name . ': alerta humano (continua sem eventos)';
            }
        } elseif ($verdict === 'awaiting_scan') {
            if ($now - $since >= WA_WATCHDOG_ESCALATE_SEC && (string)($r['alert_kind'] ?? '') !== 'awaiting_scan') {
                wa_watchdog_notify($name, 'awaiting_scan', 'WhatsApp "' . $label . '" aguardando QR Code',
                    'A conexão deste número caiu e só volta lendo o QR Code com o celular dele. Abra Meu WhatsApp → Reconectar e leia o QR Code.');
                wa_monitor_set($name, ['alert_kind' => 'awaiting_scan', 'alert_sent_at' => date('Y-m-d H:i:s')]);
                $log[] = $name . ': alerta humano (aguardando QR)';
            }
        }
    }
    return $log ? implode("\n", $log) : 'ok: nada a fazer';
}

/**
 * Usuários que recebem o alerta: administradores + usuários vinculados à instância.
 *
 * @return array<int,string> user_id => link para abrir
 */
function wa_watchdog_alert_recipients(?string $instanceName): array
{
    $out = [];
    try {
        $st = db()->query("SELECT DISTINCT u.id FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE r.slug = 'admin' AND u.status = 'active'");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $out[(int)$uid] = '/admin_settings.php?aba=whatsapp-instancias';
        }
    } catch (Throwable $e) {
        error_log('[WATCHDOG] recipients admin: ' . $e->getMessage());
    }
    if ($instanceName !== null && $instanceName !== '') {
        try {
            $st = db()->prepare("SELECT DISTINCT u.id FROM whatsapp_instances wi
                LEFT JOIN whatsapp_instance_users wiu ON wiu.instance_id = wi.id
                INNER JOIN users u ON u.id = COALESCE(wiu.user_id, wi.user_id)
                WHERE wi.instance_name = ? AND u.status = 'active'");
            $st->execute([$instanceName]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                if (!isset($out[(int)$uid])) {
                    $out[(int)$uid] = '/my_whatsapp.php';
                }
            }
            // Dono legado (whatsapp_instances.user_id) além do vínculo N:N
            $st = db()->prepare("SELECT wi.user_id FROM whatsapp_instances wi INNER JOIN users u ON u.id = wi.user_id
                WHERE wi.instance_name = ? AND u.status = 'active'");
            $st->execute([$instanceName]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                if ($uid !== null && !isset($out[(int)$uid])) {
                    $out[(int)$uid] = '/my_whatsapp.php';
                }
            }
        } catch (Throwable $e) {
            error_log('[WATCHDOG] recipients users: ' . $e->getMessage());
        }
    }
    return $out;
}

/** Alerta humano: notificação no sino para cada destinatário. */
function wa_watchdog_notify(?string $instanceName, string $kind, string $title, string $message): void
{
    foreach (wa_watchdog_alert_recipients($instanceName) as $uid => $link) {
        try {
            notification_create($uid, 'whatsapp_conexao', mb_strimwidth($title, 0, 250, '…'), $message, $link);
        } catch (Throwable $e) {
            error_log('[WATCHDOG] notify: ' . $e->getMessage());
        }
    }
    error_log('[WATCHDOG] alerta ' . $kind . ($instanceName ? ' ' . $instanceName : '') . ': ' . $title);
}

// --------------------------------------------------------------------------
// Trava de número duplicado
// --------------------------------------------------------------------------

/**
 * Chave para comparar números: só dígitos, com DDI 55, sem o 9 extra de celular
 * brasileiro (o WhatsApp às vezes devolve o número sem ele). Aceita JID
 * ("5517991253062:12@s.whatsapp.net").
 */
function whatsapp_number_key(?string $raw): string
{
    $s = (string)$raw;
    $s = (string)preg_replace('/[@:].*$/', '', $s);
    $d = (string)preg_replace('/\D+/', '', $s);
    if ($d === '') {
        return '';
    }
    if (strlen($d) === 10 || strlen($d) === 11) {
        $d = '55' . $d;
    }
    if (strlen($d) === 13 && str_starts_with($d, '55') && $d[4] === '9') {
        $d = substr($d, 0, 4) . substr($d, 5);
    }
    return strlen($d) >= 10 ? $d : '';
}

/** Número para exibir: +55 (17) 99125-3062. */
function whatsapp_number_display(?string $raw): string
{
    $d = (string)preg_replace('/\D+/', '', (string)preg_replace('/[@:].*$/', '', (string)$raw));
    if (strlen($d) === 10 || strlen($d) === 11) {
        $d = '55' . $d;
    }
    if (str_starts_with($d, '55') && (strlen($d) === 12 || strlen($d) === 13)) {
        $local = substr($d, 4);
        return '+55 (' . substr($d, 2, 2) . ') ' . substr($local, 0, strlen($local) - 4) . '-' . substr($local, -4);
    }
    return $d !== '' ? '+' . $d : '';
}

/**
 * Outras instâncias ATIVAS do sistema com o mesmo número.
 *
 * @return array<int,array{instance:string,label:string}>
 */
function whatsapp_instances_with_number(string $number, string $exceptInstance = ''): array
{
    $key = whatsapp_number_key($number);
    if ($key === '') {
        return [];
    }
    $out = [];
    foreach (wa_watchdog_system_instances() as $name => $row) {
        if ($name === $exceptInstance) {
            continue;
        }
        $k1 = whatsapp_number_key((string)($row['owner_phone_formatted'] ?? ''));
        $k2 = whatsapp_number_key((string)($row['owner_number'] ?? ''));
        if (($k1 !== '' && $k1 === $key) || ($k2 !== '' && $k2 === $key)) {
            $out[] = ['instance' => $name, 'label' => wa_instance_label($name, $row)];
        }
    }
    return $out;
}

/**
 * Grupos de instâncias ativas que hoje dividem o mesmo número (para avisar na tela).
 *
 * @return array<string,string[]> instância => outras instâncias com o mesmo número
 */
function whatsapp_duplicate_number_map(): array
{
    $byKey = [];
    foreach (wa_watchdog_system_instances() as $name => $row) {
        $key = whatsapp_number_key((string)($row['owner_phone_formatted'] ?: ($row['owner_number'] ?? '')));
        if ($key !== '') {
            $byKey[$key][] = $name;
        }
    }
    $map = [];
    foreach ($byKey as $names) {
        if (count($names) < 2) {
            continue;
        }
        foreach ($names as $n) {
            $map[$n] = array_values(array_diff($names, [$n]));
        }
    }
    return $map;
}

/**
 * Lista da Evolution (todas as instâncias, inclusive fora do sistema).
 *
 * @return array<int,array{name:string,state:string,key:string}>|null null = não conseguiu ler
 */
function wa_evolution_instances_list(): ?array
{
    try {
        $baseUrl = trim((string)admin_setting_get('evolution.base_url', ''));
        $apiKey = trim((string)admin_setting_get('evolution.api_key', ''));
        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }
        $res = http_json_request('GET', rtrim($baseUrl, '/') . '/instance/fetchInstances', ['apikey' => $apiKey], null, 12);
        if ((int)$res['status'] < 200 || (int)$res['status'] >= 300 || !is_array($res['json'])) {
            return null;
        }
        $out = [];
        foreach ($res['json'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $i = isset($item['instance']) && is_array($item['instance']) ? $item['instance'] : $item; // v1 | v2
            $name = (string)($i['name'] ?? ($i['instanceName'] ?? ''));
            if ($name === '') {
                continue;
            }
            $state = whatsapp_health_normalize_state((string)($i['connectionStatus'] ?? ($i['status'] ?? ($i['state'] ?? ''))));
            $owner = (string)($i['ownerJid'] ?? ($i['owner'] ?? ($i['number'] ?? '')));
            $out[] = ['name' => $name, 'state' => $state, 'key' => whatsapp_number_key($owner), 'raw_owner' => $owner];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[WATCHDOG] fetchInstances: ' . $e->getMessage());
        return null;
    }
}

/** A resposta de /instance/connect trouxe QR Code (ou código de pareamento)? */
function wa_connect_response_has_qr(array $r): bool
{
    $inst = is_array($r['instance'] ?? null) ? $r['instance'] : [];
    $state = (string)($inst['state'] ?? (is_string($r['state'] ?? null) ? $r['state'] : ''));
    if ($state === 'open' || $state === 'connected') {
        return false;
    }
    $qr = is_array($r['qrcode'] ?? null) ? $r['qrcode'] : [];
    foreach ([$r['base64'] ?? '', $r['code'] ?? '', $r['pairingCode'] ?? '', $qr['base64'] ?? '', $qr['code'] ?? ''] as $v) {
        if (is_string($v) && $v !== '') {
            return true;
        }
    }
    return false;
}

/** Marca: o sistema gerou um QR Code para esta instância (conferir o número ao conectar). */
function wa_pairing_mark(string $instanceName): void
{
    wa_monitor_set($instanceName, ['pairing_requested_at' => date('Y-m-d H:i:s'), 'duplicate_note' => null, 'duplicate_at' => null]);
}

function wa_pairing_pending(string $instanceName): bool
{
    $r = wa_monitor_row($instanceName);
    if (!$r || !$r['pairing_requested_at']) {
        return false;
    }
    return time() - strtotime((string)$r['pairing_requested_at']) <= WA_PAIRING_WINDOW_MIN * 60;
}

/** Aviso de conexão desfeita por número duplicado (últimos 15 min), para a tela. */
function wa_pairing_duplicate_note(string $instanceName): ?string
{
    $r = wa_monitor_row($instanceName);
    if (!$r || !$r['duplicate_note'] || !$r['duplicate_at']) {
        return null;
    }
    return time() - strtotime((string)$r['duplicate_at']) <= 900 ? (string)$r['duplicate_note'] : null;
}

/**
 * Conferência ao conectar: se esta instância acabou de ler um QR Code gerado
 * pelo sistema com um número que já está em OUTRA instância (do sistema, ou
 * conectado na Evolution), desfaz esta conexão nova.
 *
 * Só age quando há marca de QR pendente: reinício/heal de uma instância antiga
 * nunca derruba nada. Instâncias que já dividem número hoje são só avisadas
 * na tela (whatsapp_duplicate_number_map).
 *
 * @param string|null $ownerNumber número/JID conectado, se já conhecido
 * @param array|null  $evoList     lista de wa_evolution_instances_list() já lida
 * @return array{blocked:bool, number:string, message:string}|null null = nada a conferir
 */
function wa_pairing_guard(string $instanceName, ?string $ownerNumber = null, ?array $evoList = null): ?array
{
    if ($instanceName === '' || $instanceName === WA_WATCHDOG_GLOBAL_ROW) {
        return null;
    }
    wa_watchdog_ensure_schema();
    // Reivindica a marca (só uma requisição confere: webhook, tela ou rodada)
    $st = db()->prepare('UPDATE whatsapp_instance_monitor SET pairing_requested_at = NULL
        WHERE instance_name = ? AND pairing_requested_at IS NOT NULL AND pairing_requested_at > DATE_SUB(NOW(), INTERVAL ' . (int)WA_PAIRING_WINDOW_MIN . ' MINUTE)');
    $st->execute([$instanceName]);
    wa_monitor_rows(true);
    if ($st->rowCount() < 1) {
        return null;
    }
    $markedAt = date('Y-m-d H:i:s');

    $key = whatsapp_number_key($ownerNumber);
    if ($evoList === null) {
        $evoList = wa_evolution_instances_list();
    }
    if ($key === '' && is_array($evoList)) {
        foreach ($evoList as $e) {
            if ($e['name'] === $instanceName) {
                $key = $e['key'];
                $ownerNumber = $e['raw_owner'];
                break;
            }
        }
    }
    if ($key === '') {
        // Ainda não deu para saber o número: devolve a marca para a próxima conferência
        wa_monitor_set($instanceName, ['pairing_requested_at' => $markedAt]);
        return null;
    }

    $conflicts = [];
    $inSystem = [];
    foreach (whatsapp_instances_with_number((string)$ownerNumber, $instanceName) as $c) {
        $conflicts[$c['instance']] = 'na instância "' . $c['label'] . '" do sistema';
        $inSystem[] = $c['label'];
    }
    if (is_array($evoList)) {
        foreach ($evoList as $e) {
            if ($e['name'] !== $instanceName && $e['key'] === $key && $e['state'] === 'open' && !isset($conflicts[$e['name']])) {
                $conflicts[$e['name']] = 'na instância "' . $e['name'] . '" da Evolution (fora do sistema)';
            }
        }
    }
    $display = whatsapp_number_display((string)$ownerNumber);
    if (!$conflicts) {
        // Conexão nova: o veredito antigo (ex.: aguardando QR) não vale mais
        wa_monitor_set($instanceName, ['verdict' => 'warming_up', 'verdict_since' => date('Y-m-d H:i:s')]);
        return ['blocked' => false, 'number' => $display, 'message' => ''];
    }

    $where = implode(' e ', array_values($conflicts));
    $message = 'O número ' . $display . ' já está ' . $where . '. Duas conexões com o mesmo número disputam a sessão e as mensagens somem, '
        . 'por isso esta conexão nova foi desfeita. '
        . ($inSystem
            ? 'Use a instância "' . $inSystem[0] . '" (botão Reconectar) ou remova-a antes de conectar este número aqui.'
            : 'Desconecte o número dessa outra conexão antes de conectá-lo aqui.');

    // Desfaz a conexão nova
    try {
        $api = new EvolutionApiV1(null, null, $instanceName);
        $api->logoutInstance($instanceName);
    } catch (Throwable $e) {
        error_log('[WATCHDOG] logout duplicado ' . $instanceName . ': ' . $e->getMessage());
    }
    try {
        whatsapp_update_connection_status($instanceName, 'disconnected');
    } catch (Throwable $e) {}
    if (function_exists('whatsapp_health_record')) {
        whatsapp_health_record($instanceName, 'duplicate', 'close', mb_strimwidth('número já usado em ' . implode(', ', array_keys($conflicts)), 0, 250, ''));
    }
    wa_monitor_set($instanceName, ['duplicate_note' => mb_strimwidth($message, 0, 500, '…'), 'duplicate_at' => date('Y-m-d H:i:s')]);
    try {
        audit_log('update', 'whatsapp_instances', null, null, ['action' => 'pairing_blocked_duplicate', 'instance' => $instanceName, 'conflicts' => array_keys($conflicts)]);
    } catch (Throwable $e) {}
    $selfRow = wa_watchdog_system_instances()[$instanceName] ?? null;
    wa_watchdog_notify($instanceName, 'duplicate', 'WhatsApp "' . wa_instance_label($instanceName, $selfRow) . '": número já conectado em outra instância', $message);

    return ['blocked' => true, 'number' => $display, 'message' => $message];
}

// --------------------------------------------------------------------------
// Tela: aviso no topo + botão Reconectar
// --------------------------------------------------------------------------

/** O usuário pode pedir reconexão desta instância? (admin de WhatsApp ou vinculado a ela) */
function wa_watchdog_user_can_heal(int $userId, string $instanceName): bool
{
    if ($userId <= 0 || $instanceName === '') {
        return false;
    }
    $sys = wa_watchdog_system_instances();
    if (!isset($sys[$instanceName])) {
        return false; // nunca mexe em instância fora do sistema (o watchdog é compartilhado)
    }
    $perms = rbac_user_permissions($userId);
    if (array_intersect(['admin.settings.manage', 'whatsapp.manage'], $perms) || rbac_user_has_role($userId, 'admin')) {
        return true;
    }
    foreach (whatsapp_list_user_instances($userId) as $i) {
        if ((string)($i['instance_name'] ?? '') === $instanceName) {
            return true;
        }
    }
    return false;
}

/** Script do botão "Reconectar" (uma vez por página). */
function wa_watchdog_heal_js(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<script>window.mlWaHeal = window.mlWaHeal || function(instance, btn){
  if (btn) { btn.disabled = true; btn.dataset.txt = btn.textContent; btn.textContent = "Reconectando..."; }
  var fd = new FormData(); fd.append("instance", instance);
  fetch("/whatsapp_instance_heal_post.php", {method:"POST", body:fd, credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest"}})
  .then(function(r){ return r.text().then(function(t){ try { return JSON.parse(t); } catch(e){ return {ok:false, msg:"Resposta inesperada do servidor (HTTP " + r.status + "). Recarregue a página."}; } }); })
  .then(function(d){ alert(d.msg || (d.ok ? "Reconexão disparada." : "Não foi possível reconectar.")); if (d.ok) { location.reload(); } else if (btn) { btn.disabled = false; btn.textContent = btn.dataset.txt || "Reconectar"; } })
  .catch(function(e){ alert("Erro: " + e.message); if (btn) { btn.disabled = false; btn.textContent = btn.dataset.txt || "Reconectar"; } });
};</script>';
}

/**
 * Problemas do watchdog para o aviso do topo (instâncias que o usuário vê).
 *
 * @return array<int,array{instance:string,label:string,verdict:string,view:array,wd:array,can_heal:bool,link:string}>
 */
function wa_watchdog_banner_problems(int $userId): array
{
    if (!wa_watchdog_enabled() || $userId <= 0) {
        return [];
    }
    $sys = wa_watchdog_system_instances();
    if (!$sys) {
        return [];
    }
    $isAdmin = rbac_user_has_role($userId, 'admin') || rbac_user_can($userId, 'admin.settings.manage');
    $own = [];
    foreach (whatsapp_list_user_instances($userId) as $i) {
        $own[(string)($i['instance_name'] ?? '')] = true;
    }
    $out = [];
    foreach ($sys as $name => $row) {
        if (!$isAdmin && !isset($own[$name])) {
            continue;
        }
        $wd = wa_watchdog_get($name);
        if (!$wd || !in_array($wd['verdict'], ['silent', 'awaiting_scan'], true)) {
            continue;
        }
        $out[] = [
            'instance' => $name,
            'label' => wa_instance_label($name, $row),
            'verdict' => $wd['verdict'],
            'view' => wa_watchdog_verdict_view($wd['verdict']),
            'wd' => $wd,
            'can_heal' => $wd['verdict'] === 'silent',
            'link' => isset($own[$name]) ? '/my_whatsapp.php' : '/admin_settings.php?aba=whatsapp-instancias',
        ];
    }
    return $out;
}

/** HTML do aviso do topo para os problemas do watchdog. */
function wa_watchdog_banner_html(int $userId, array $skipInstances = []): string
{
    $problems = array_values(array_filter(wa_watchdog_banner_problems($userId),
        static fn($p) => !in_array($p['instance'], $skipInstances, true)));
    if (!$problems) {
        return '';
    }
    $html = '';
    foreach ($problems as $p) {
        $v = $p['view'];
        $border = $p['verdict'] === 'silent' ? 'hsl(25,95%,53%)' : 'hsl(45,93%,47%)';
        $bg = $p['verdict'] === 'silent' ? 'hsla(25,95%,53%,.10)' : 'hsla(45,93%,47%,.12)';
        $html .= '<div class="alert wa-health-alert" data-dismiss-key="' . h('wd|' . $p['instance'] . '|' . $p['verdict']) . '" style="position:relative;padding-right:36px;border:1px solid ' . $border . ';background:' . $bg . '">';
        $html .= '<button type="button" class="wa-health-close" title="Fechar aviso" aria-label="Fechar aviso" style="position:absolute;top:6px;right:8px;background:none;border:none;font-size:20px;line-height:1;cursor:pointer;color:inherit;opacity:.6">&times;</button>';
        $html .= '<strong>⚠️ WhatsApp "' . h($p['label']) . '": ' . h($v['label']) . '.</strong> ';
        if ($p['verdict'] === 'silent') {
            $ago = wa_watchdog_ago($p['wd']['last_event_seconds']);
            $html .= h('A conexão aparece como conectada, mas nenhum evento chega' . ($ago !== '' ? ' ' . $ago : '') . '. Mensagens recebidas podem não estar entrando no sistema.');
            if ($p['wd']['auto_heal_at']) {
                $html .= ' ' . h('O sistema já tentou reconectar sozinho às ' . date('H:i', strtotime($p['wd']['auto_heal_at'])) . '.');
            }
            $html .= '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
            $html .= '<button type="button" class="btn btnPrimary" style="font-size:12px;padding:6px 12px" onclick=\'mlWaHeal(' . h((string)json_encode($p['instance'])) . ', this)\'>🔄 Reconectar</button>';
            $html .= '<span style="font-size:12px">Refaz a conexão sem QR Code. Se não resolver em 5 min, leia o QR Code de novo em <a href="' . h($p['link']) . '">Meu WhatsApp</a>.</span>';
            $html .= '</div>';
        } else {
            $html .= h('A conexão caiu e só volta lendo o QR Code com o celular deste número.');
            $html .= ' <a href="' . h($p['link']) . '" style="font-weight:700">Abrir e ler o QR Code</a>';
        }
        $html .= '</div>';
    }
    return $html . wa_watchdog_heal_js();
}
