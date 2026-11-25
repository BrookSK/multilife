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

$raw = (string)file_get_contents('php://input');
if ($raw === '' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Corpo descartado pelo PHP: maior que post_max_size
    error_log('[WEBHOOK] corpo de ' . (int)$_SERVER['CONTENT_LENGTH'] . ' bytes descartado (post_max_size=' . ini_get('post_max_size') . ')');
}
$payload = $raw !== '' ? json_decode($raw, true) : null;

$inboxId = 0;
$processNow = false;
if (is_array($payload)) {
    try {
        $stored = wa_inbox_store($raw, $payload);
        if ($stored['status'] === 'queued') {
            $inboxId = $stored['id'];
        } elseif ($stored['status'] === 'unavailable') {
            $processNow = true;
        }
    } catch (Throwable $e) {
        error_log('[WEBHOOK] fila indisponível, processando direto: ' . $e->getMessage());
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
}
