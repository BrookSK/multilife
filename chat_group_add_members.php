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

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    flash_set('error', 'Evolution API não configurada.');
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
    
    $ch = curl_init($baseUrl . '/group/updateParticipant/' . urlencode($instanceName) . '/add');
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
        flash_set('success', 'Participantes adicionados com sucesso!');
        audit_log('whatsapp_group_members_added', 'Membros adicionados ao grupo: ' . $groupId);
    } else {
        flash_set('error', 'Erro ao adicionar participantes. Código: ' . $httpCode);
    }
} catch (Exception $e) {
    flash_set('error', 'Erro: ' . $e->getMessage());
}

header('Location: /chat_manage_group.php?id=' . urlencode($groupId));
exit;
