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

// Usar IP direto para evitar problemas de DNS/Cloudflare
$webhookUrl = 'http://186.209.113.140/chat_webhook.php';

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
curl_setopt($ch, CURLOPT_POST, true);
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
    flash_set('success', 'Webhook configurado com IP direto! URL: ' . $webhookUrl);
} else {
    flash_set('error', 'Erro ao configurar webhook. HTTP: ' . $httpCode . '. Resposta: ' . substr($response, 0, 300));
}

header('Location: /admin_settings.php');
exit;
