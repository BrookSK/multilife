<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$groupId = trim($_POST['group_id'] ?? '');
$participantId = trim($_POST['participant_id'] ?? '');

if (empty($groupId) || empty($participantId)) {
    flash_set('error', 'Grupo e participante são obrigatórios.');
    header('Location: /chat_web.php');
    exit;
}

$baseUrl = wa_api_base_url();
$apiKey = wa_api_key();
$_currentUserId = (int)($_SESSION['auth_user_id'] ?? 0);
$_userInst = whatsapp_get_user_instance($_currentUserId);
$instanceName = $_userInst ? $_userInst['instance_name'] : wa_default_instance();

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    flash_set('error', 'API do WhatsApp não configurada.');
    header('Location: /chat_manage_group.php?id=' . urlencode($groupId));
    exit;
}

try {
    $payload = [
        'groupJid' => $groupId,
        'participants' => [$participantId]
    ];
    
    $ch = curl_init($baseUrl . '/group/updateParticipant/' . urlencode($instanceName) . '/remove');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        flash_set('success', 'Participante removido com sucesso!');
        audit_log('update', 'chat_groups', $groupId, null, ['action' => 'remove_member', 'participant' => $participantId]);
    } else {
        flash_set('error', 'Erro ao remover participante. Código: ' . $httpCode);
    }
} catch (Exception $e) {
    flash_set('error', 'Erro: ' . $e->getMessage());
}

header('Location: /chat_manage_group.php?id=' . urlencode($groupId));
exit;
