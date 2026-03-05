<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$instanceName = $_GET['instance'] ?? '';

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');

if (empty($baseUrl) || empty($apiKey)) {
    echo json_encode(['error' => 'Evolution API não configurada']);
    exit;
}

if (empty($instanceName)) {
    echo json_encode(['error' => 'Nome da instância não informado']);
    exit;
}

try {
    $url = '';
    
    switch ($action) {
        case 'connect':
            $url = $baseUrl . '/instance/connect/' . urlencode($instanceName);
            break;
        case 'status':
            $url = $baseUrl . '/instance/connectionState/' . urlencode($instanceName);
            break;
        default:
            echo json_encode(['error' => 'Ação inválida']);
            exit;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desenvolvimento
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        echo $response;
    } else {
        echo json_encode(['error' => 'Erro na API Evolution. Código: ' . $httpCode]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Erro ao conectar: ' . $e->getMessage()]);
}
