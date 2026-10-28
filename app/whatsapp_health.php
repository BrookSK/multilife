<?php

declare(strict_types=1);

/**
 * SAÚDE DAS INSTÂNCIAS DO WHATSAPP (Evolution).
 *
 * Problema real: depois de muitas conexões/desconexões a sessão fica em LOOP —
 * a Evolution responde "conectado" (connectionState = open), mas fica
 * reconectando sem parar, gerando QR novo, ou aceita o envio e a mensagem não
 * sai. Só olhar o connectionState não detecta isso.
 *
 * Aqui registramos o histórico de eventos (webhook CONNECTION_UPDATE e
 * QRCODE_UPDATED, consultas de estado e resultado dos envios) e avaliamos uma
 * janela recente para classificar a instância e dizer EXPLICITAMENTE o que fazer.
 *
 * Níveis: ok | unstable (conectada mas envios falhando) | looping (reconectando
 * sem parar) | conflict (outra sessão usando o mesmo número) | logged_out
 * (desconectada do celular, precisa novo QR) | unknown (sem dados).
 */

const WHATSAPP_HEALTH_WINDOW_MIN = 15;

function whatsapp_health_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_instance_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_name VARCHAR(100) NOT NULL,
            event_type VARCHAR(30) NOT NULL COMMENT 'connection | qrcode | probe | send_error | send_ok',
            state VARCHAR(30) NULL,
            reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wie_instance_time (instance_name, created_at),
            KEY idx_wie_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[WHATSAPP_HEALTH] ensure: ' . $e->getMessage());
    }
}

/** Registra um evento (falhas são silenciosas: nunca pode quebrar envio/webhook). */
function whatsapp_health_record(string $instanceName, string $type, ?string $state = null, ?string $reason = null): void
{
    if ($instanceName === '') {
        return;
    }
    try {
        whatsapp_health_ensure_schema();
        // Consultas de estado repetidas com o mesmo resultado não ajudam: só grava mudança.
        if ($type === 'probe') {
            $last = db()->prepare("SELECT state FROM whatsapp_instance_events
                WHERE instance_name = ? AND event_type IN ('probe','connection') ORDER BY id DESC LIMIT 1");
            $last->execute([$instanceName]);
            if ((string)($last->fetchColumn() ?: '') === (string)$state) {
                return;
            }
        }
        db()->prepare('INSERT INTO whatsapp_instance_events (instance_name, event_type, state, reason) VALUES (?, ?, ?, ?)')
            ->execute([$instanceName, $type, $state !== null ? mb_substr($state, 0, 30) : null, $reason !== null ? mb_substr($reason, 0, 255) : null]);
        // Limpeza ocasional (mantém 7 dias).
        if (random_int(1, 200) === 1) {
            db()->exec('DELETE FROM whatsapp_instance_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        }
    } catch (Throwable $e) {
        error_log('[WHATSAPP_HEALTH] record: ' . $e->getMessage());
    }
}

/** Normaliza o estado vindo da Evolution ('open','close','connecting','refused'...). */
function whatsapp_health_normalize_state(string $state): string
{
    $s = strtolower(trim($state));
    if (in_array($s, ['open', 'connected'], true)) return 'open';
    if (in_array($s, ['close', 'closed', 'disconnected', 'refused'], true)) return 'close';
    if (in_array($s, ['connecting', 'qr', 'qrcode'], true)) return 'connecting';
    return $s;
}

/**
 * Trata um evento de webhook da Evolution. Retorna true se era de conexão/QR.
 * Também atualiza whatsapp_instances.connection_status.
 */
function whatsapp_health_handle_webhook(string $event, string $instanceName, array $data): bool
{
    if ($event !== 'connection.update' && $event !== 'qrcode.updated') {
        return false;
    }
    if ($event === 'qrcode.updated') {
        whatsapp_health_record($instanceName, 'qrcode', 'qrcode', null);
        return true;
    }
    $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
    $state = whatsapp_health_normalize_state((string)($payload['state'] ?? ($payload['connection'] ?? '')));
    $reason = $payload['statusReason'] ?? ($payload['lastDisconnect']['error']['output']['statusCode'] ?? null);
    whatsapp_health_record($instanceName, 'connection', $state, $reason !== null ? (string)$reason : null);
    if ($state !== '' && function_exists('whatsapp_update_connection_status')) {
        try {
            $map = ['open' => 'connected', 'close' => 'disconnected', 'connecting' => 'connecting'];
            if (isset($map[$state])) {
                whatsapp_update_connection_status($instanceName, $map[$state]);
            }
        } catch (Throwable $e) {}
    }
    return true;
}

/**
 * Avalia a saúde de uma instância na janela recente.
 *
 * @return array{level:string, title:string, message:string, actions:string[], counts:array<string,int>}
 */
function whatsapp_health_status(string $instanceName, int $windowMinutes = WHATSAPP_HEALTH_WINDOW_MIN): array
{
    whatsapp_health_ensure_schema();
    $rows = [];
    try {
        $st = db()->prepare("SELECT event_type, state, reason, created_at FROM whatsapp_instance_events
            WHERE instance_name = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY id ASC");
        $st->execute([$instanceName, $windowMinutes]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $c = ['open' => 0, 'close' => 0, 'connecting' => 0, 'qrcode' => 0, 'send_error' => 0, 'send_ok' => 0, 'changes' => 0];
    $lastState = null;
    $lastConnReason = '';
    $conflict = false;
    $errorsSinceLastOk = 0;
    $lastSendError = '';
    $lastChangeAt = 0;
    foreach ($rows as $r) {
        $type = (string)$r['event_type'];
        $state = (string)($r['state'] ?? '');
        if ($type === 'connection' || $type === 'probe') {
            if (isset($c[$state])) {
                $c[$state]++;
            }
            if ($lastState !== null && $lastState !== $state) {
                $c['changes']++;
                $lastChangeAt = strtotime((string)$r['created_at']);
            }
            $lastState = $state;
            if ($type === 'connection') {
                $lastConnReason = (string)($r['reason'] ?? '');
            }
            if ((string)($r['reason'] ?? '') === '440') {
                $conflict = true;
            }
        } elseif ($type === 'qrcode') {
            $c['qrcode']++;
        } elseif ($type === 'send_error') {
            $c['send_error']++;
            $errorsSinceLastOk++;
            $lastSendError = (string)($r['reason'] ?? '');
        } elseif ($type === 'send_ok') {
            $c['send_ok']++;
            $errorsSinceLastOk = 0;
        }
    }

    $resetSteps = [
        'No celular deste número: WhatsApp → Aparelhos conectados → desconecte TODOS os aparelhos.',
        'No sistema: Configurações → WhatsApp → nesta instância clique em "Desconectar (logout)" e depois em "Conectar" para gerar um QR novo.',
        'Leia o QR com o celular e aguarde 1–2 minutos sem reconectar de novo.',
        'Se continuar em loop: "Reiniciar instância" (ou recriar a instância) e repita a leitura do QR.',
    ];

    $win = $windowMinutes . ' min';
    if ($lastConnReason === '401' && $lastState === 'close') {
        return ['level' => 'logged_out', 'title' => 'Desconectado do celular',
            'message' => 'O WhatsApp encerrou a sessão desta instância (logout). Ela não envia nem recebe até ler um QR novo.',
            'actions' => array_slice($resetSteps, 1, 2), 'counts' => $c];
    }
    if ($conflict) {
        return ['level' => 'conflict', 'title' => 'Conflito de sessão',
            'message' => 'Outra sessão (outro WhatsApp Web ou outra instância da Evolution) está usando o mesmo número e derrubando esta conexão.',
            'actions' => array_merge(['Feche o WhatsApp Web/Desktop e qualquer outra instância da Evolution com este número.'], $resetSteps), 'counts' => $c];
    }
    // Recuperada: está aberta e sem nenhuma troca de estado há 5+ minutos (ex.: depois do reset).
    $settled = $lastState === 'open' && $lastChangeAt > 0 && (time() - $lastChangeAt) >= 300;
    if (!$settled && (($c['close'] >= 3 && $c['open'] >= 3) || $c['changes'] >= 6)) {
        return ['level' => 'looping', 'title' => 'Conexão em loop',
            'message' => "A instância caiu e reconectou {$c['close']} vez(es) nos últimos $win. Mesmo aparecendo como conectada, as mensagens podem não sair.",
            'actions' => $resetSteps, 'counts' => $c];
    }
    if ($c['qrcode'] >= 5 && $c['open'] === 0) {
        return ['level' => 'looping', 'title' => 'QR Code em loop',
            'message' => "A Evolution gerou {$c['qrcode']} QR Codes nos últimos $win sem conseguir conectar.",
            'actions' => $resetSteps, 'counts' => $c];
    }
    if ($errorsSinceLastOk >= 3) {
        return ['level' => 'unstable', 'title' => 'Conectada, mas os envios estão falhando',
            'message' => "Os últimos $errorsSinceLastOk envios falharam" . ($lastSendError !== '' ? ' (último erro: ' . mb_strimwidth($lastSendError, 0, 120, '…') . ')' : '') . '. A sessão provavelmente está corrompida.',
            'actions' => $resetSteps, 'counts' => $c];
    }
    if (!$rows) {
        return ['level' => 'unknown', 'title' => 'Sem eventos recentes', 'message' => 'Nenhum evento registrado na janela.', 'actions' => [], 'counts' => $c];
    }
    return ['level' => 'ok', 'title' => 'Estável', 'message' => 'Sem quedas ou falhas recentes.', 'actions' => [], 'counts' => $c];
}

/** O nível impede usar a instância para enviar/adicionar membros? */
function whatsapp_health_is_blocking(string $level): bool
{
    return in_array($level, ['looping', 'conflict', 'logged_out'], true);
}

/**
 * Instâncias ativas com problema.
 *
 * @return array<int,array{instance_name:string, status:array}>
 */
function whatsapp_health_problems(): array
{
    $out = [];
    whatsapp_health_ensure_schema();
    try {
        // Uma consulta só: avalia apenas instâncias ativas que tiveram eventos na janela
        // (roda em toda tela; sem eventos recentes não há o que alertar).
        $st = db()->prepare("SELECT DISTINCT e.instance_name FROM whatsapp_instance_events e
            INNER JOIN whatsapp_instances wi ON wi.instance_name = e.instance_name AND wi.status = 'active'
            WHERE e.created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $st->execute([WHATSAPP_HEALTH_WINDOW_MIN]);
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
    foreach ($names as $n) {
        $s = whatsapp_health_status((string)$n);
        if (!in_array($s['level'], ['ok', 'unknown'], true)) {
            $out[] = ['instance_name' => (string)$n, 'status' => $s];
        }
    }
    return $out;
}

/** Faixa de alerta no topo das telas (só para quem cuida de WhatsApp/captação). */
function whatsapp_health_banner_html(): string
{
    $uid = auth_user_id();
    if ($uid === null) {
        return '';
    }
    $perms = rbac_user_permissions($uid);
    if (!array_intersect(['demands.manage', 'chat.manage', 'admin.settings.manage'], $perms)) {
        return '';
    }
    $problems = whatsapp_health_problems();
    if (!$problems) {
        return '';
    }
    $html = '';
    foreach ($problems as $p) {
        $s = $p['status'];
        $red = whatsapp_health_is_blocking($s['level']);
        $html .= '<div class="alert" style="border:1px solid ' . ($red ? 'hsl(0,84%,60%)' : 'hsl(36,90%,55%)') . ';background:' . ($red ? 'hsla(0,84%,60%,.08)' : 'hsla(36,90%,55%,.10)') . '">';
        $html .= '<strong>⚠️ WhatsApp "' . h($p['instance_name']) . '": ' . h($s['title']) . '.</strong> ' . h($s['message']);
        if ($s['actions']) {
            $html .= '<div style="margin-top:6px"><strong>O que fazer:</strong><ol style="margin:4px 0 0 18px;padding:0">';
            foreach ($s['actions'] as $a) {
                $html .= '<li>' . h($a) . '</li>';
            }
            $html .= '</ol></div>';
        }
        if ($red) {
            $html .= '<div style="margin-top:6px;font-size:12px">Enquanto isso, a captação não usa esta instância para enviar.</div>';
        }
        $html .= '</div>';
    }
    return $html;
}
