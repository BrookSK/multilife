<?php

declare(strict_types=1);

/**
 * CRON: fila de entrada do webhook da API do WhatsApp (chat_webhook_inbox).
 *
 * O próprio webhook processa cada evento logo depois de responder 200. Este cron
 * reprocessa o que ficou para trás (PHP caiu no meio, tempo esgotado, banco fora)
 * e faz a limpeza da fila. Agendar a cada 1 minuto:
 *   php cron/chat_webhook_inbox.php token=SEU_CRON_TOKEN
 *   (ou https://<site>/cron/chat_webhook_inbox.php?token=<cron.token>)
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../app/whatsapp_webhook_handler.php';
require_once __DIR__ . '/../app/whatsapp_webhook_inbox.php';

ignore_user_abort(true);
@set_time_limit(120);
@ini_set('memory_limit', '512M'); // payloads com mídia em base64

$r = wa_inbox_process_pending(200, 50);
$cleaned = wa_inbox_cleanup();
$stats = wa_inbox_stats();

echo 'Fila do webhook: ' . $r['processed'] . ' processado(s), ' . $r['failed'] . ' não concluído(s), '
    . $cleaned . ' removido(s) na limpeza. Últimas 24h: '
    . $stats['pending'] . ' pendente(s), ' . $stats['processing'] . ' em processamento, '
    . $stats['done'] . ' concluído(s), ' . $stats['failed'] . " com falha.\n";
