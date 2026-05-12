<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

$groupJid = '120363409753301661@g.us';

echo '<h2>Últimas mensagens do grupo</h2><pre>';

$stmt = db()->prepare('SELECT id, remote_jid, message_text, message_type, media_url, media_filename, from_me, message_timestamp, created_at FROM chat_messages WHERE remote_jid = ? ORDER BY id DESC LIMIT 20');
$stmt->execute([$groupJid]);
$msgs = $stmt->fetchAll();

echo "Total: " . count($msgs) . " mensagens\n\n";

foreach ($msgs as $m) {
    $time = date('H:i:s', (int)$m['message_timestamp']);
    $type = $m['message_type'] ?? 'text';
    $fromMe = $m['from_me'] ? 'SENT' : 'RECV';
    $media = $m['media_url'] ? '[MEDIA: ' . ($m['media_filename'] ?? $m['media_url']) . ']' : '';
    echo "#" . $m['id'] . " | $time | $fromMe | type=$type | " . substr($m['message_text'], 0, 60) . " $media\n";
}

echo "\n\n=== ÚLTIMOS LOGS DE WEBHOOK (error_log) ===\n";
echo "Verifique o error_log do Apache para entradas [WEBHOOK] recentes.\n";
echo "Se não houver entradas com 'document' ou 'DOCUMENT', a Evolution não está enviando o webhook para documentos em grupo.\n";

echo '</pre>';
