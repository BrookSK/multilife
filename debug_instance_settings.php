<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
header('Content-Type: application/json');

$baseUrl = trim((string)admin_setting_get('evolution.base_url', ''));
$apiKey = trim((string)admin_setting_get('evolution.api_key', ''));
$instance = trim((string)admin_setting_get('evolution.instance', ''));

// Buscar settings
$ch = curl_init($baseUrl . '/settings/find/' . urlencode($instance));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Se groupsIgnore está ativo, desativar e reconfigurar
$settings = json_decode($resp, true);
$needsFix = false;

if (is_array($settings) && !empty($settings['groupsIgnore'])) {
    $needsFix = true;
    // Desativar groupsIgnore
    $ch2 = curl_init($baseUrl . '/settings/set/' . urlencode($instance));
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode([
        'rejectCall' => false,
        'groupsIgnore' => false,
        'alwaysOnline' => false,
        'readMessages' => false,
        'readStatus' => false,
        'syncFullHistory' => false,
    ]));
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    $resp2 = curl_exec($ch2);
    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
}

// Também reconfigurar webhook no formato correto (flat, sem wrapper)
$publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
if ($publicUrl === '') $publicUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$webhookUrl = rtrim($publicUrl, '/') . '/chat_webhook.php';

$ch3 = curl_init($baseUrl . '/webhook/set/' . urlencode($instance));
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_POST, true);
curl_setopt($ch3, CURLOPT_POSTFIELDS, json_encode([
    'enabled' => true,
    'url' => $webhookUrl,
    'events' => [
        'MESSAGES_UPSERT',
        'SEND_MESSAGE',
        'CONTACTS_UPSERT',
        'CONTACTS_UPDATE',
        'CONNECTION_UPDATE',
        'GROUPS_UPSERT',
        'GROUP_UPDATE',
        'GROUP_PARTICIPANTS_UPDATE',
        'QRCODE_UPDATED',
    ],
    'base64' => true,
]));
curl_setopt($ch3, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
$resp3 = curl_exec($ch3);
$code3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
curl_close($ch3);

echo json_encode([
    'current_settings' => $settings,
    'settings_http' => $code,
    'groups_ignore_was_active' => $needsFix,
    'settings_fixed' => $needsFix ? json_decode($resp2 ?? '{}', true) : 'not needed',
    'webhook_reconfigured' => [
        'http' => $code3,
        'url' => $webhookUrl,
        'format' => 'flat (no wrapper)',
        'base64' => true,
        'response' => json_decode($resp3, true),
    ],
], JSON_PRETTY_PRINT);
