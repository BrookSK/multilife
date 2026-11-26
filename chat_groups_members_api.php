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

$baseUrl = wa_api_base_url();
$apiKey = wa_api_key();
$_currentUserId = (int)($_SESSION['auth_user_id'] ?? 0);
$_userInst = whatsapp_get_user_instance($_currentUserId);
$instanceName = $_userInst ? $_userInst['instance_name'] : wa_default_instance();

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    echo json_encode(['error' => 'API do WhatsApp não configurada']);
    exit;
}

try {
    // Tentar primeiro com getParticipants que retorna números reais
    $url = $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName) . '?getParticipants=true';
    
    // API oficial: a lista de todos os grupos com participantes custa uma consulta por grupo; vai direto ao grupo
    $raw1 = wa_is_cloud() ? ['body' => '', 'status' => 0] : wa_raw_request('GET', $url, null, 15);
    $response = $raw1['body'];
    $httpCode = $raw1['status'];
    
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
        
        $raw2 = wa_raw_request('GET', $url2, null, 15);
        $response2 = $raw2['body'];
        $httpCode2 = $raw2['status'];
        
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
