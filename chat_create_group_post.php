<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$groupName = trim($_POST['group_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$participants = $_POST['participants'] ?? [];

if (empty($groupName)) {
    flash_set('error', 'Nome do grupo é obrigatório.');
    header('Location: /chat_create_group.php');
    exit;
}

if (empty($participants) || !is_array($participants)) {
    flash_set('error', 'Selecione pelo menos um participante.');
    header('Location: /chat_create_group.php');
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    flash_set('error', 'Evolution API não configurada.');
    header('Location: /chat_create_group.php');
    exit;
}

try {
    // Formatar participantes para Evolution API
    $formattedParticipants = [];
    foreach ($participants as $phone) {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (!empty($cleanPhone)) {
            $formattedParticipants[] = $cleanPhone . '@s.whatsapp.net';
        }
    }
    
    if (empty($formattedParticipants)) {
        flash_set('error', 'Nenhum participante válido selecionado.');
        header('Location: /chat_create_group.php');
        exit;
    }
    
    $payload = [
        'subject' => $groupName,
        'description' => $description,
        'participants' => $formattedParticipants
    ];
    
    $ch = curl_init($baseUrl . '/group/create/' . urlencode($instanceName));
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
        flash_set('success', 'Grupo criado com sucesso!');
        audit_log('whatsapp_group_created', 'Grupo WhatsApp criado: ' . $groupName);
        
        // Salvar no banco de dados
        $stmt = db()->prepare(
            "INSERT INTO whatsapp_groups (name, description, status, created_by_user_id, created_at)
            VALUES (:name, :desc, 'active', :uid, NOW())"
        );
        $stmt->execute([
            'name' => $groupName,
            'desc' => $description,
            'uid' => auth_user_id()
        ]);
    } else {
        flash_set('error', 'Erro ao criar grupo. Código: ' . $httpCode);
    }
} catch (Exception $e) {
    flash_set('error', 'Erro ao criar grupo: ' . $e->getMessage());
}

header('Location: /chat_web.php?type=groups');
exit;
