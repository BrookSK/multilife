<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: application/json');

$baseUrl = trim((string)admin_setting_get('openai.base_url', ''));
$apiKey = trim((string)admin_setting_get('openai.api_key', ''));
$model = trim((string)admin_setting_get('openai.model', 'gpt-4o-mini'));

$results = [
    'config' => [
        'base_url' => $baseUrl,
        'api_key_prefix' => substr($apiKey, 0, 8) . '...',
        'api_key_length' => strlen($apiKey),
        'model' => $model,
    ],
];

// Teste 1: Listar modelos disponíveis
$ch = curl_init($baseUrl . '/models');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$results['models_endpoint'] = [
    'url' => $baseUrl . '/models',
    'http_code' => $code,
    'response_preview' => substr($resp, 0, 500),
];

// Teste 2: Chat completion simples
$chatUrl = $baseUrl . '/chat/completions';
$ch2 = curl_init($chatUrl);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
]);
curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode([
    'model' => $model,
    'messages' => [['role' => 'user', 'content' => 'Responda apenas: OK']],
    'max_tokens' => 5,
]));
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
$resp2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

$results['chat_test'] = [
    'url' => $chatUrl,
    'http_code' => $code2,
    'response' => $resp2,
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
