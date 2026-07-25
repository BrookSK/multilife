<?php
/**
 * Script de diagnóstico: testa a criação de grupo na Evolution API.
 * Acesse: /test_evolution_group.php
 * Após o teste, APAGUE este arquivo.
 */
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

header('Content-Type: text/plain; charset=utf-8');

$baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
$apiKey = (string)admin_setting_get('evolution.api_key', '');
$instanceName = (string)admin_setting_get('evolution.instance', '');

echo "=== DIAGNÓSTICO EVOLUTION API ===\n\n";
echo "Base URL: $baseUrl\n";
echo "Instance: $instanceName\n";
echo "API Key: " . substr($apiKey, 0, 8) . "...\n\n";

// 1. Testar conexão
echo "--- Teste 1: Connection State ---\n";
$url1 = $baseUrl . '/instance/connectionState/' . urlencode($instanceName);
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url1,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ["apikey: " . $apiKey],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res1 = curl_exec($ch);
$code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err1 = curl_error($ch);
curl_close($ch);
echo "URL: $url1\n";
echo "HTTP: $code1\n";
echo "Erro cURL: $err1\n";
echo "Resposta: $res1\n\n";

// 2. Testar criação de grupo com 1 participante fixo
echo "--- Teste 2: Criar Grupo ---\n";
$url2 = $baseUrl . '/group/create/' . urlencode($instanceName);

// Usar o telefone do usuário logado
$stmtPhone = db()->prepare("SELECT phone FROM users WHERE id = ?");
$stmtPhone->execute([auth_user_id()]);
$userPhone = $stmtPhone->fetchColumn();
$cleanPhone = preg_replace('/\D+/', '', (string)$userPhone);
if (strlen($cleanPhone) === 10 || strlen($cleanPhone) === 11) $cleanPhone = '55' . $cleanPhone;

echo "Telefone do usuário logado: $cleanPhone (original: $userPhone)\n";

$payload = json_encode([
    'subject' => 'Teste API - ' . date('H:i:s'),
    'participants' => [$cleanPhone],
]);

echo "URL: $url2\n";
echo "Payload: $payload\n\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url2,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json", "apikey: " . $apiKey],
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err2 = curl_error($ch);
curl_close($ch);
echo "HTTP: $code2\n";
echo "Erro cURL: $err2\n";
echo "Resposta: $res2\n\n";

// 3. Se falhou, tentar sem participantes
if ($code2 >= 400) {
    echo "--- Teste 3: Criar Grupo SEM participantes ---\n";
    $payload3 = json_encode([
        'subject' => 'Teste Vazio - ' . date('H:i:s'),
        'participants' => [],
    ]);
    echo "Payload: $payload3\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url2,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $payload3,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "apikey: " . $apiKey],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res3 = curl_exec($ch);
    $code3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP: $code3\n";
    echo "Resposta: $res3\n\n";
}

// 4. Se tudo falhou, tentar com formato de número diferente (@s.whatsapp.net)
if ($code2 >= 400) {
    echo "--- Teste 4: Criar Grupo com @s.whatsapp.net ---\n";
    $payload4 = json_encode([
        'subject' => 'Teste JID - ' . date('H:i:s'),
        'participants' => [$cleanPhone . '@s.whatsapp.net'],
    ]);
    echo "Payload: $payload4\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url2,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $payload4,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "apikey: " . $apiKey],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res4 = curl_exec($ch);
    $code4 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP: $code4\n";
    echo "Resposta: $res4\n\n";
}

echo "=== FIM DO DIAGNÓSTICO ===\n";
echo "APAGUE este arquivo após o teste: test_evolution_group.php\n";
