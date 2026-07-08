<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
header('Content-Type: application/json');

$jid = trim($_GET['jid'] ?? '');
if ($jid === '') {
    echo json_encode(['error' => 'jid required']);
    exit;
}

$api = new EvolutionApiV1();
$res = $api->findMessages($jid);

$messages = $res['json']['messages']['records'] ?? $res['json']['messages'] ?? $res['json'] ?? [];

// Pegar só as últimas 3 mensagens que são mídia
$mediaMessages = [];
if (is_array($messages)) {
    foreach ($messages as $msg) {
        $msgPayload = $msg['message'] ?? [];
        if (isset($msgPayload['imageMessage']) || isset($msgPayload['audioMessage']) || isset($msgPayload['videoMessage']) || isset($msgPayload['documentMessage'])) {
            $mediaMessages[] = [
                'key' => $msg['key'] ?? [],
                'pushName' => $msg['pushName'] ?? '',
                'messageTimestamp' => $msg['messageTimestamp'] ?? 0,
                'message_keys' => is_array($msgPayload) ? array_keys($msgPayload) : 'not_array',
                'has_base64_in_msg' => isset($msg['base64']),
                'has_base64_in_payload' => isset($msgPayload['base64']),
                'imageMessage_keys' => isset($msgPayload['imageMessage']) ? array_keys($msgPayload['imageMessage']) : null,
                'audioMessage_keys' => isset($msgPayload['audioMessage']) ? array_keys($msgPayload['audioMessage']) : null,
                'imageMessage_url' => $msgPayload['imageMessage']['url'] ?? null,
                'imageMessage_directPath' => $msgPayload['imageMessage']['directPath'] ?? null,
                'imageMessage_mediaUrl' => $msgPayload['imageMessage']['mediaUrl'] ?? null,
            ];
            if (count($mediaMessages) >= 3) break;
        }
    }
}

echo json_encode([
    'jid' => $jid,
    'http_code' => $res['status'] ?? 0,
    'total_messages' => is_array($messages) ? count($messages) : 0,
    'media_messages' => $mediaMessages,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
