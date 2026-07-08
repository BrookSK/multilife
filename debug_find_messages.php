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

// Pegar a última mensagem que é mídia recebida (fromMe=false)
$mediaMsg = null;
if (is_array($messages)) {
    foreach ($messages as $msg) {
        $key = $msg['key'] ?? [];
        if (!empty($key['fromMe'])) continue;
        $msgPayload = $msg['message'] ?? [];
        if (isset($msgPayload['imageMessage']) || isset($msgPayload['audioMessage']) || isset($msgPayload['videoMessage']) || isset($msgPayload['documentMessage'])) {
            $mediaMsg = $msg;
            break;
        }
    }
}

if (!$mediaMsg) {
    echo json_encode(['error' => 'No received media found', 'total' => count($messages)]);
    exit;
}

// Tentar baixar via getBase64FromMediaMessage
$baseUrl = $api->getBaseUrl();
$apiKey = $api->getApiKey();
$instance = $api->getInstance();

$downloadUrl = $baseUrl . '/chat/getBase64FromMediaMessage/' . urlencode($instance);
$downloadPayload = json_encode([
    'message' => [
        'key' => $mediaMsg['key'],
    ],
    'convertToMp4' => false,
]);

$ch = curl_init($downloadUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $downloadPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$downloadResp = curl_exec($ch);
$downloadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$downloadData = json_decode($downloadResp, true);

echo json_encode([
    'media_key' => $mediaMsg['key'],
    'media_type' => array_keys($mediaMsg['message'] ?? []),
    'download_endpoint' => $downloadUrl,
    'download_http_code' => $downloadCode,
    'download_response_keys' => is_array($downloadData) ? array_keys($downloadData) : 'not_array',
    'download_has_base64' => isset($downloadData['base64']),
    'download_base64_length' => isset($downloadData['base64']) ? strlen($downloadData['base64']) : 0,
    'download_response_preview' => substr($downloadResp, 0, 300),
], JSON_PRETTY_PRINT);
