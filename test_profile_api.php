<?php
// Script de teste para verificar se a API de perfil está funcionando

require_once __DIR__ . '/app/bootstrap.php';

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

echo "=== TESTE DE BUSCA DE PERFIL ===\n\n";
echo "Base URL: " . $baseUrl . "\n";
echo "Instance: " . $instanceName . "\n";
echo "API Key: " . substr($apiKey, 0, 10) . "...\n\n";

// Número para testar (substitua pelo número real)
$testNumber = '5517981628213@s.whatsapp.net';

echo "Testando busca de perfil para: " . $testNumber . "\n\n";

$profileUrl = $baseUrl . '/chat/fetchProfile/' . urlencode($instanceName);
$profilePayload = json_encode(['number' => $testNumber]);

echo "URL: " . $profileUrl . "\n";
echo "Payload: " . $profilePayload . "\n\n";

$ch = curl_init($profileUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $profilePayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$profileResponse = curl_exec($ch);
$profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $profileHttpCode . "\n";
if ($curlError) {
    echo "CURL Error: " . $curlError . "\n";
}
echo "\nResposta:\n";
echo $profileResponse . "\n\n";

if ($profileHttpCode === 200 && $profileResponse) {
    $profileData = json_decode($profileResponse, true);
    echo "=== DADOS DECODIFICADOS ===\n";
    print_r($profileData);
    
    $profileName = $profileData['name'] ?? $profileData['pushName'] ?? null;
    $profilePic = $profileData['profilePictureUrl'] ?? null;
    
    echo "\n=== EXTRAÍDO ===\n";
    echo "Nome: " . ($profileName ?? 'N/A') . "\n";
    echo "Foto: " . ($profilePic ?? 'N/A') . "\n";
} else {
    echo "ERRO: Não foi possível buscar perfil\n";
}
