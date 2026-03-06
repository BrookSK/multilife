<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$chatId = isset($_GET['chat_id']) ? trim((string)$_GET['chat_id']) : '';

if (empty($chatId)) {
    echo json_encode(['error' => 'chat_id obrigatório']);
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    echo json_encode(['error' => 'Credenciais Evolution não configuradas']);
    exit;
}

$messages = [];

try {
    $payload = json_encode([
        'where' => [
            'key' => [
                'remoteJid' => $chatId
            ]
        ],
        'limit' => 10
    ]);
    
    $ch = curl_init($baseUrl . '/chat/findMessages/' . urlencode($instanceName));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . $apiKey,
        'Content-Type: application/json',
        'Cache-Control: no-cache'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data) && is_array($data)) {
            // FILTRO: A API Evolution ignora o filtro remoteJid, então filtramos no PHP
            $messages = array_filter($data, function($msg) use ($chatId) {
                $msgRemoteJid = $msg['key']['remoteJid'] ?? '';
                return $msgRemoteJid === $chatId;
            });
            
            // Reindexar array após filtro
            $messages = array_values($messages);
        }
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'messages' => $messages,
    'count' => count($messages)
]);
exit;
