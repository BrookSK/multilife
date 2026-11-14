<?php

declare(strict_types=1);

/**
 * Webhook da Evolution API (mesmo modelo do helpdeskON):
 * decodifica o evento, processa e responde 200.
 * A regra de cada evento está em app/whatsapp_webhook_handler.php.
 * Versão anterior guardada em chat_webhook_legacy.php (para voltar atrás).
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/whatsapp_webhook_handler.php';

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

$payload = json_decode((string)file_get_contents('php://input'), true);

if (is_array($payload)) {
    try {
        wa_webhook_handle($payload);
    } catch (Throwable $e) {
        error_log('[WEBHOOK] erro: ' . $e->getMessage());
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
