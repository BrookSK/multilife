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

// Mostrar todas as mensagens com seus tipos
$output = [];
if (is_array($messages)) {
    foreach (array_slice($messages, 0, 20) as $msg) {
        $key = $msg['key'] ?? [];
        $msgPayload = $msg['message'] ?? [];
        $msgKeys = is_array($msgPayload) ? array_keys($msgPayload) : [];
        
        $item = [
            'id' => $key['id'] ?? '',
            'fromMe' => $key['fromMe'] ?? false,
            'pushName' => $msg['pushName'] ?? '',
            'timestamp' => $msg['messageTimestamp'] ?? 0,
            'message_keys' => $msgKeys,
            'has_reaction' => isset($msgPayload['reactionMessage']),
        ];
        
        if (isset($msgPayload['reactionMessage'])) {
            $item['reaction'] = $msgPayload['reactionMessage'];
        }
        if (isset($msgPayload['conversation'])) {
            $item['text'] = substr($msgPayload['conversation'], 0, 50);
        }
        if (isset($msgPayload['extendedTextMessage'])) {
            $item['text'] = substr($msgPayload['extendedTextMessage']['text'] ?? '', 0, 50);
        }
        
        $output[] = $item;
    }
}

echo json_encode(['jid' => $jid, 'total' => count($messages), 'messages' => $output], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
