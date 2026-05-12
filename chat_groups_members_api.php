<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$groupJid = trim($_GET['group_jid'] ?? '');

if (empty($groupJid)) {
    echo json_encode(['error' => 'group_jid não informado']);
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    echo json_encode(['error' => 'Evolution API não configurada']);
    exit;
}

try {
    $url = $baseUrl . '/group/participants/' . urlencode($instanceName) . '?groupJid=' . urlencode($groupJid);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        
        // Normalizar resposta - a Evolution pode retornar em diferentes formatos
        $participants = [];
        if (isset($data['participants'])) {
            $participants = $data['participants'];
        } elseif (isset($data[0]['id'])) {
            $participants = $data;
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $participants = $data['data'];
        }
        
        echo json_encode(['participants' => $participants]);
    } else {
        echo json_encode(['error' => 'Erro ao buscar membros. HTTP: ' . $httpCode]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
}
