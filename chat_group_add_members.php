<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$groupId = trim($_POST['group_id'] ?? '');
$participants = $_POST['participants'] ?? [];

if (empty($groupId) || empty($participants)) {
    flash_set('error', 'Grupo e participantes são obrigatórios.');
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
    $formattedParticipants = [];
    foreach ($participants as $phone) {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (!empty($cleanPhone)) {
            $formattedParticipants[] = $cleanPhone . '@s.whatsapp.net';
        }
    }
    
    $payload = [
        'groupJid' => $groupId,
        'participants' => $formattedParticipants
    ];
    
    $payload['action'] = 'add';
    $payload['forceInvite'] = true; // API oficial: convite pedido de propósito sai mesmo se já foi enviado antes
    $rawRes = wa_raw_request('POST', $baseUrl . '/group/updateParticipant/' . urlencode($instanceName) . '/add', $payload, 90);
    $httpCode = $rawRes['status'];
    
    if ($httpCode === 200 || $httpCode === 201) {
        flash_set('success', wa_provider_can('group_add_members')
            ? 'Participantes adicionados com sucesso!'
            : 'Convite com o link do grupo enviado para ' . (int)($rawRes['json']['invitesSent'] ?? count($formattedParticipants)) . ' pessoa(s). Elas entram no grupo ao tocar no link.');
        audit_log('update', 'chat_groups', $groupId, null, ['action' => 'add_members', 'participants' => $formattedParticipants]);
    } else {
        flash_set('error', 'Erro ao adicionar participantes: ' . wa_api_error_message(['status' => $httpCode, 'json' => $rawRes['json'], 'body_raw' => $rawRes['body'] !== '' ? $rawRes['body'] : $rawRes['error']]));
    }
} catch (Exception $e) {
    flash_set('error', 'Erro: ' . $e->getMessage());
}

header('Location: /chat_manage_group.php?id=' . urlencode($groupId));
exit;
