<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

echo '<h2>Diagnóstico de Webhook - Evolution API</h2>';
echo '<pre>';

echo "Configuração atual:\n";
echo "  Base URL: $baseUrl\n";
echo "  Instance: $instanceName\n";
echo "  API Key: " . substr($apiKey, 0, 8) . "...\n\n";

// 1. Verificar instâncias disponíveis
echo "=== INSTÂNCIAS DISPONÍVEIS ===\n";
$url = $baseUrl . '/instance/fetchInstances';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$instances = json_decode($resp, true);
if (is_array($instances)) {
    foreach ($instances as $inst) {
        $name = $inst['instance']['instanceName'] ?? $inst['instanceName'] ?? $inst['name'] ?? '?';
        $state = $inst['instance']['state'] ?? $inst['state'] ?? '?';
        echo "  - $name (state: $state)\n";
    }
} else {
    echo "  Erro ao buscar instâncias. HTTP: $code\n";
    echo "  Resposta: " . substr($resp, 0, 300) . "\n";
}

echo "\n=== WEBHOOK CONFIGURADO ===\n";

// 2. Verificar webhook da instância configurada
$url2 = $baseUrl . '/webhook/find/' . urlencode($instanceName);
$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
$resp2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "  Endpoint: $url2\n";
echo "  HTTP: $code2\n";
echo "  Resposta: " . $resp2 . "\n";

// 3. Tentar endpoint alternativo
echo "\n=== WEBHOOK (endpoint alternativo) ===\n";
$url3 = $baseUrl . '/webhook/' . urlencode($instanceName);
$ch3 = curl_init($url3);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch3, CURLOPT_TIMEOUT, 10);
$resp3 = curl_exec($ch3);
$code3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
curl_close($ch3);

echo "  Endpoint: $url3\n";
echo "  HTTP: $code3\n";
echo "  Resposta: " . $resp3 . "\n";

// 4. Verificar settings da instância
echo "\n=== SETTINGS DA INSTÂNCIA ===\n";
$url4 = $baseUrl . '/instance/fetchInstances?instanceName=' . urlencode($instanceName);
$ch4 = curl_init($url4);
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch4, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
curl_setopt($ch4, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch4, CURLOPT_TIMEOUT, 10);
$resp4 = curl_exec($ch4);
$code4 = curl_getinfo($ch4, CURLINFO_HTTP_CODE);
curl_close($ch4);

echo "  HTTP: $code4\n";
$data4 = json_decode($resp4, true);
if (is_array($data4)) {
    echo "  " . json_encode($data4, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "  Resposta: " . substr($resp4, 0, 500) . "\n";
}

echo "\n=== URL ESPERADA DO WEBHOOK ===\n";
echo "  https://" . $_SERVER['HTTP_HOST'] . "/chat_webhook.php\n";

echo '</pre>';
echo '<br><a href="/admin_settings.php">Voltar</a>';
