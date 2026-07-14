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
$_currentUserId = (int)($_SESSION['auth_user_id'] ?? 0);
$_userInst = whatsapp_get_user_instance($_currentUserId);
$instanceName = $_userInst ? $_userInst['instance_name'] : admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    echo json_encode(['error' => 'Evolution API não configurada']);
    exit;
}

try {
    // Tentar primeiro com getParticipants que retorna números reais
    $url = $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName) . '?getParticipants=true';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $participants = [];
    
    // Tentar extrair participantes do grupo específico via fetchAllGroups
    if ($httpCode === 200 && $response) {
        $allGroups = json_decode($response, true);
        if (is_array($allGroups)) {
            foreach ($allGroups as $g) {
                $gId = $g['id'] ?? '';
                if ($gId === $groupJid) {
                    $participants = $g['participants'] ?? [];
                    break;
                }
            }
        }
    }
    
    // Se não encontrou via fetchAllGroups, tentar endpoint direto
    if (empty($participants)) {
        $url2 = $baseUrl . '/group/participants/' . urlencode($instanceName) . '?groupJid=' . urlencode($groupJid);
        
        $ch2 = curl_init($url2);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
        
        $response2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        
        if ($httpCode2 === 200 && $response2) {
            $data = json_decode($response2, true);
            if (isset($data['participants'])) {
                $participants = $data['participants'];
            } elseif (isset($data[0]['id'])) {
                $participants = $data;
            } elseif (isset($data['data']) && is_array($data['data'])) {
                $participants = $data['data'];
            }
        }
    }
    
    echo json_encode(['participants' => $participants]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
}
