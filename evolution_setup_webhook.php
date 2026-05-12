<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    flash_set('error', 'Evolution API não configurada.');
    header('Location: /admin_settings.php');
    exit;
}

$webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/chat_webhook.php';

// Configurar webhook na Evolution API
$url = $baseUrl . '/webhook/set/' . urlencode($instanceName);

$payload = json_encode([
    'url' => $webhookUrl,
    'webhook_by_events' => false,
    'webhook_base64' => true,
    'events' => [
        'MESSAGES_UPSERT',
        'SEND_MESSAGE',
        'CONTACTS_UPSERT',
        'CONTACTS_UPDATE',
        'CONNECTION_UPDATE',
    ]
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 || $httpCode === 201) {
    flash_set('success', 'Webhook configurado com sucesso! URL: ' . $webhookUrl);
} else {
    // Tentar formato alternativo (Evolution API v2)
    $url2 = $baseUrl . '/webhook/set/' . urlencode($instanceName);
    $payload2 = json_encode([
        'webhook' => [
            'url' => $webhookUrl,
            'events' => ['MESSAGES_UPSERT', 'SEND_MESSAGE'],
            'webhook_by_events' => false,
            'webhook_base64' => true,
        ]
    ]);
    
    $ch2 = curl_init($url2);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload2);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'apikey: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
    
    $response2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    
    if ($httpCode2 === 200 || $httpCode2 === 201) {
        flash_set('success', 'Webhook configurado com sucesso (v2)! URL: ' . $webhookUrl);
    } else {
        flash_set('error', 'Erro ao configurar webhook. HTTP: ' . $httpCode . ' / ' . $httpCode2 . '. Resposta: ' . substr($response . ' | ' . $response2, 0, 300) . '. Configure manualmente no Manager da Evolution: ' . $webhookUrl);
    }
}

header('Location: /admin_settings.php');
exit;
