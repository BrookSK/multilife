<?php

declare(strict_types=1);

/**
 * Webhook da API do WhatsApp.
 *
 * 1. Grava o payload cru na fila (app/whatsapp_webhook_inbox.php), ignorando
 *    repetições (evento + WhatsApp + key.id);
 * 2. Responde 200 na hora e fecha a conexão: a API do WhatsApp não espera o
 *    processamento (mídia grande, consultas à API do WhatsApp, etc.);
 * 3. Processa o evento (regras em app/whatsapp_webhook_handler.php) e, de
 *    carona, alguns pendentes antigos da fila. O cron cron/chat_webhook_inbox.php
 *    cobre o resto.
 * Versão anterior guardada em chat_webhook_legacy.php (para voltar atrás).
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/whatsapp_webhook_handler.php';
require_once __DIR__ . '/app/whatsapp_webhook_inbox.php';

// Webhook não usa login: descarta a sessão (não trava nada nem deixa arquivo)
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_COOKIE[session_name()])) {
        @session_destroy();
    } else {
        session_write_close();
    }
}

ignore_user_abort(true);
@set_time_limit(120);
@ini_set('memory_limit', '512M'); // mídia em base64 no corpo

$t0 = microtime(true);
$raw = (string)file_get_contents('php://input');
if ($raw === '' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Corpo descartado pelo PHP: maior que post_max_size
    error_log('[WEBHOOK] corpo de ' . (int)$_SERVER['CONTENT_LENGTH'] . ' bytes descartado (post_max_size=' . ini_get('post_max_size') . ')');
    wa_event_log('error', 'webhook', 'corpo_descartado', [
        'summary' => 'Evento do WhatsApp PERDIDO: o corpo de ' . round((int)$_SERVER['CONTENT_LENGTH'] / 1048576, 1) . ' MB passou do limite do PHP (post_max_size=' . ini_get('post_max_size') . ')',
        'details' => ['bytes' => (int)$_SERVER['CONTENT_LENGTH'], 'post_max_size' => ini_get('post_max_size'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null],
    ]);
}
$payload = $raw !== '' ? json_decode($raw, true) : null;
if ($raw !== '' && !is_array($payload)) {
    wa_event_log('error', 'webhook', 'json_invalido', [
        'summary' => 'Chamada ao webhook com corpo que não é JSON válido (' . strlen($raw) . ' bytes): ignorada',
        'error' => json_last_error_msg(),
        'details' => ['inicio' => mb_substr($raw, 0, 300), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null],
    ]);
}

$inboxId = 0;
$processNow = false;
if (is_array($payload)) {
    $whEvent = wa_inbox_event($payload);
    $whInstance = trim((string)($payload['instance'] ?? ($payload['instanceName'] ?? '')));
    $whIds = wa_inbox_message_ids($payload);
    $whBase = [
        'instance' => $whInstance !== '' ? $whInstance : null,
        'message_id' => $whIds[0] ?? null,
        'jid' => (string)(wa_wh_message_list($payload['data'] ?? [])[0]['key']['remoteJid'] ?? '') ?: null,
        'status' => $whEvent,
    ];
    try {
        $stored = wa_inbox_store($raw, $payload);
        if ($stored['status'] === 'queued') {
            $inboxId = $stored['id'];
        } elseif ($stored['status'] === 'unavailable') {
            $processNow = true;
        }
        // Presença/contatos/conversas chegam aos montes: só os eventos que importam
        if (!in_array($whEvent, ['presence.update', 'contacts.upsert', 'contacts.update', 'chats.upsert', 'chats.update', 'chats.delete'], true)) {
            wa_event_log($stored['status'] === 'unavailable' ? 'warn' : 'debug', 'webhook',
                ['queued' => 'recebido', 'duplicate' => 'recebido_repetido', 'unavailable' => 'fila_indisponivel'][$stored['status']] ?? 'recebido', $whBase + [
                'ms' => (microtime(true) - $t0) * 1000,
                'summary' => [
                    'queued' => 'Sistema recebeu ' . $whEvent . ' e colocou na fila (#' . (int)$stored['id'] . ')',
                    'duplicate' => 'Sistema recebeu ' . $whEvent . ' REPETIDO (já estava na fila #' . (int)$stored['id'] . '): ignorado',
                    'unavailable' => 'Sistema recebeu ' . $whEvent . ', mas a fila está indisponível (pasta/banco): processando direto',
                ][$stored['status']] ?? $whEvent,
                'details' => ['fila' => (int)$stored['id'], 'kb' => round(strlen($raw) / 1024, 1), 'mensagens' => array_slice($whIds, 0, 20)],
            ]);
        }
    } catch (Throwable $e) {
        error_log('[WEBHOOK] fila indisponível, processando direto: ' . $e->getMessage());
        wa_event_log('warn', 'webhook', 'fila_indisponivel', $whBase + [
            'summary' => 'Fila do webhook com erro: processando o evento direto (' . $whEvent . ')',
            'error' => $e,
        ]);
        $processNow = true;
    }
}
unset($raw);

// Resposta imediata para a API do WhatsApp
$body = (string)json_encode(['status' => 'ok']);
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: ' . strlen($body));
header('Connection: close');
echo $body;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
}

// Processamento (depois da resposta)
try {
    if ($inboxId > 0) {
        wa_inbox_process($inboxId, $payload);
    } elseif ($processNow) {
        wa_webhook_handle($payload);
    }
} catch (Throwable $e) {
    error_log('[WEBHOOK] erro: ' . $e->getMessage());
    wa_event_log('error', 'processamento', 'erro_webhook', ($whBase ?? []) + [
        'summary' => 'Erro ao processar o evento recebido: ' . mb_substr($e->getMessage(), 0, 250),
        'error' => $e,
        'details' => ['trace' => mb_substr($e->getTraceAsString(), 0, 3000)],
    ]);
}
unset($payload);

// De carona: pendentes que ficaram para trás e, de vez em quando, a limpeza
try {
    wa_inbox_process_pending(3, 20);
    if (random_int(1, 500) === 1) {
        wa_inbox_cleanup();
    }
} catch (Throwable $e) {
    error_log('[WEBHOOK] fila (pendentes): ' . $e->getMessage());
    wa_event_log('error', 'processamento', 'erro_fila_pendentes', ['summary' => 'Erro ao processar pendentes da fila do webhook', 'error' => $e]);
}
