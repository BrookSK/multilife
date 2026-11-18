<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
session_release(); // polling a cada 30 s em todas as telas

header('Content-Type: application/json');

$userId = auth_user_id();

$stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
$stmt->execute(['uid' => $userId]);
$count = (int)$stmt->fetchColumn();

echo json_encode(['count' => $count]);

// Monitor de conexão real do WhatsApp (app/whatsapp_watchdog.php): este polling
// roda em toda tela aberta, então serve de "cron" — no máximo 1 rodada por
// minuto para o sistema todo. Responde primeiro e roda depois, sem segurar a tela.
if (function_exists('wa_watchdog_enabled') && wa_watchdog_enabled()) {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    wa_watchdog_tick();
}
