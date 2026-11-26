<?php

declare(strict_types=1);

/**
 * Captação pela API oficial: envio DIRETO para cada profissional.
 *
 * Grupos da API oficial têm no máximo 8 participantes e ninguém é adicionado
 * sem aceitar o convite, então a captação em grupo (centenas de profissionais
 * da especialidade/região) não funciona lá. No modo API oficial a captação vai
 * para cada profissional da especialidade/região (a mesma lista que entrava no
 * grupo), numa conversa privada:
 *   - quem falou com o número nas últimas 24 h recebe a mensagem completa com o
 *     botão "Tenho interesse";
 *   - os demais recebem o modelo aprovado "Captação" (também com o botão);
 *   - tocar em "Tenho interesse" OU reagir à mensagem registra o interesse na
 *     Lista de Espera (mesma tabela demand_interested_professionals).
 *
 * Tabela demand_dispatch_direct: uma linha por profissional, com o id das
 * mensagens (para achar a captação pela resposta/reação) e a situação
 * (enviada, entregue, lida, falhou).
 */

function demand_dispatch_direct_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS demand_dispatch_direct (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        dispatch_log_id BIGINT UNSIGNED NOT NULL,
        demand_id BIGINT UNSIGNED NOT NULL,
        phone VARCHAR(40) NOT NULL,
        instance_name VARCHAR(100) NULL,
        external_message_id VARCHAR(190) NULL,
        text_message_id VARCHAR(190) NULL,
        via VARCHAR(60) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_ddd (dispatch_log_id, phone),
        KEY idx_ddd_msg (external_message_id),
        KEY idx_ddd_txt (text_message_id),
        KEY idx_ddd_demand (demand_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Disparo (demand_dispatch_logs) de uma mensagem enviada direto ao profissional, ou null. */
function demand_dispatch_direct_find(string $messageId): ?array
{
    if ($messageId === '') {
        return null;
    }
    try {
        demand_dispatch_direct_ensure_table();
        $st = db()->prepare("SELECT dl.id, dl.demand_id, d.status AS demand_status
                             FROM demand_dispatch_direct dd
                             INNER JOIN demand_dispatch_logs dl ON dl.id = dd.dispatch_log_id
                             LEFT JOIN demands d ON d.id = dl.demand_id
                             WHERE dd.external_message_id = ? OR dd.text_message_id = ?
                             ORDER BY dd.id DESC LIMIT 1");
        $st->execute([$messageId, $messageId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Situação vinda do webhook (enviada/entregue/lida/falhou). */
function demand_dispatch_direct_status(string $messageId, string $status, array $s = []): void
{
    $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 9];
    if (!isset($rank[$status]) || $messageId === '') {
        return;
    }
    try {
        demand_dispatch_direct_ensure_table();
        if ($status === 'failed') {
            $err = is_array($s['errors'][0] ?? null) ? $s['errors'][0] : [];
            $text = function_exists('wa_cloud_error_text')
                ? wa_cloud_error_text(['status' => 400, 'json' => ['error' => ['code' => (int)($err['code'] ?? 0), 'message' => (string)($err['title'] ?? ''),
                    'error_data' => ['details' => (string)($err['error_data']['details'] ?? '')]]]])
                : (string)($err['title'] ?? 'falhou');
            db()->prepare("UPDATE demand_dispatch_direct SET status = 'failed', error = ? WHERE external_message_id = ?")
                ->execute([mb_substr($text, 0, 255), $messageId]);
            return;
        }
        $order = "CASE status WHEN 'queued' THEN 0 WHEN 'sent' THEN 1 WHEN 'delivered' THEN 2 WHEN 'read' THEN 3 ELSE 9 END";
        db()->prepare("UPDATE demand_dispatch_direct SET status = ? WHERE external_message_id = ? AND $order < ?")
            ->execute([$status, $messageId, $rank[$status]]);
    } catch (Throwable $e) {
        error_log('[DISPATCH_DIRECT] status: ' . $e->getMessage());
    }
}

/** Contagem por situação de um disparo direto. */
function demand_dispatch_direct_counts(int $dispatchLogId): array
{
    $out = ['total' => 0, 'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'skipped' => 0];
    try {
        demand_dispatch_direct_ensure_table();
        $st = db()->prepare('SELECT status, COUNT(*) c FROM demand_dispatch_direct WHERE dispatch_log_id = ? GROUP BY status');
        $st->execute([$dispatchLogId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Número da API oficial que envia: o do captador, senão o padrão, senão o primeiro. */
function demand_dispatch_direct_pick_instance(string $captadorInstance): string
{
    if ($captadorInstance !== '' && wa_cloud_instance($captadorInstance) !== null) {
        return $captadorInstance;
    }
    $default = wa_default_instance();
    if ($default !== '' && wa_cloud_instance($default) !== null) {
        return $default;
    }
    $all = wa_cloud_instances();
    return $all ? (string)$all[0]['instance_name'] : '';
}

/**
 * Envia a captação para cada profissional.
 *
 * @param array $c demand_id, run_id, user_id, message (com o token #CAP), token,
 *                 phones (55DDNNNN...), captador_instance, vars (numero, titulo,
 *                 local, especialidade, frequencia), location_label, scope_state (bool)
 * @return array{sent:int, failed:int, skipped:int, log_id:int, instance:string, last_error:string}
 */
function demand_dispatch_direct_execute(array $c): array
{
    demand_dispatch_direct_ensure_table();
    $demandId = (int)$c['demand_id'];
    $runId = (int)($c['run_id'] ?? 0);
    $message = (string)$c['message'];
    $token = (string)$c['token'];
    $phones = array_values(array_unique(array_filter(array_map('strval', (array)$c['phones']))));
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'log_id' => 0, 'instance' => '', 'last_error' => ''];

    $instance = demand_dispatch_direct_pick_instance((string)($c['captador_instance'] ?? ''));
    if ($instance === '') {
        $out['last_error'] = 'Nenhum número da API oficial cadastrado (Configurações → WhatsApp → API oficial).';
        return $out;
    }
    $out['instance'] = $instance;

    // Um registro do disparo (sem grupo) para a Lista de Espera e o histórico
    db()->prepare("INSERT INTO demand_dispatch_logs (demand_id, group_id, dispatched_by_user_id, message, capture_token, dispatch_status) VALUES (?, NULL, ?, ?, ?, 'queued')")
        ->execute([$demandId, $c['user_id'] ?? null, $message, $token]);
    $logId = (int)db()->lastInsertId();
    $out['log_id'] = $logId;
    if (function_exists('demand_dispatch_ensure_log_instance_column') && demand_dispatch_ensure_log_instance_column()) {
        try {
            db()->prepare('UPDATE demand_dispatch_logs SET instance_name = ? WHERE id = ?')->execute([$instance, $logId]);
        } catch (Throwable $e) {}
    }

    $vars = (array)($c['vars'] ?? []);
    $values = [
        (string)($vars['numero'] ?? $demandId),
        (string)($vars['titulo'] ?? ''),
        (string)($vars['local'] ?? ''),
        (string)($vars['especialidade'] ?? ''),
        (string)($vars['frequencia'] ?? ''),
    ];
    $payload = 'interesse:' . ltrim($token, '#');
    $api = new WhatsAppApi(null, null, $instance);
    $ins = db()->prepare("INSERT IGNORE INTO demand_dispatch_direct (dispatch_log_id, demand_id, phone, instance_name, status) VALUES (?, ?, ?, ?, 'queued')");
    $upd = db()->prepare('UPDATE demand_dispatch_direct SET status = ?, external_message_id = ?, text_message_id = ?, via = ?, error = ? WHERE dispatch_log_id = ? AND phone = ?');
    $total = count($phones);
    $errors = [];
    foreach ($phones as $i => $phone) {
        $ins->execute([$logId, $demandId, $phone, $instance]);
        if ($runId > 0 && ($i % 5 === 0)) {
            demand_dispatch_run_set_phase($runId, 'sending', 'Enviando a captação direto para cada profissional (' . ($i + 1) . ' de ' . $total . ') pela API oficial...');
        }
        try {
            $res = $api->request('POST', '/message/sendButtons/' . rawurlencode($instance), [], [
                'number' => $phone,
                'description' => $message,
                'shortText' => 'Tem interesse e disponibilidade para a captação #' . $demandId . '? Toque no botão abaixo.',
                'footer' => 'MultiLife Care',
                'buttons' => [['type' => 'reply', 'displayText' => 'Tenho interesse', 'id' => $payload]],
                'cloudTemplate' => ['purpose' => 'captacao', 'values' => $values, 'payloads' => [$payload]],
            ], 40);
            $ok = (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if ($ok) {
                $mainId = (string)($res['json']['key']['id'] ?? '');
                $textId = (string)($res['json']['extraIds'][0] ?? '');
                $via = (string)($res['json']['cloud']['via'] ?? ($res['json']['cloudVia'] ?? 'interactive'));
                $upd->execute(['sent', $mainId !== '' ? $mainId : null, $textId !== '' ? $textId : null, mb_substr($via, 0, 60), null, $logId, $phone]);
                $out['sent']++;
            } else {
                $err = wa_api_error_message($res);
                $upd->execute(['failed', null, null, null, mb_substr($err, 0, 255), $logId, $phone]);
                $out['failed']++;
                $errors[$err] = ($errors[$err] ?? 0) + 1;
                $out['last_error'] = $err;
            }
        } catch (Throwable $e) {
            $upd->execute(['failed', null, null, null, mb_substr($e->getMessage(), 0, 255), $logId, $phone]);
            $out['failed']++;
            $out['last_error'] = $e->getMessage();
            $errors[$e->getMessage()] = ($errors[$e->getMessage()] ?? 0) + 1;
        }
        usleep(120000); // ~8 por segundo (bem abaixo do limite da Meta)
    }

    db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = ?, error_message = ? WHERE id = ?')
        ->execute([$out['sent'] > 0 ? 'sent' : 'error',
            $out['failed'] > 0 ? mb_strimwidth($out['failed'] . ' falha(s): ' . $out['last_error'], 0, 250, '…') : null, $logId]);
    arsort($errors);
    wa_event_log($out['sent'] > 0 ? 'info' : 'error', 'captacao', $out['sent'] > 0 ? 'disparo_direto_enviado' : 'disparo_direto_falhou', [
        'instance' => $instance, 'status' => $token,
        'summary' => 'Captação #' . $demandId . ' pela API oficial: enviada a ' . $out['sent'] . ' de ' . $total . ' profissional(is)'
            . ($out['failed'] ? ' · ' . $out['failed'] . ' falha(s)' : ''),
        'details' => ['disparo' => $logId, 'erros' => array_slice($errors, 0, 10, true)],
    ]);
    // As cópias no chat entram agora (e não só quando o cron terminar)
    if (function_exists('wa_cloud_echo_flush')) {
        wa_cloud_echo_flush();
    }
    return $out;
}
