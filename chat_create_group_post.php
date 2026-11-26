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

$baseUrl = wa_api_base_url();
$apiKey = wa_api_key();
$_currentUserId = (int)($_SESSION['auth_user_id'] ?? 0);
$_userInst = whatsapp_get_user_instance($_currentUserId);
$instanceName = $_userInst ? $_userInst['instance_name'] : wa_default_instance();

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    flash_set('error', 'API do WhatsApp não configurada.');
    header('Location: /chat_create_group.php');
    exit;
}

try {
    // Formatar participantes para API do WhatsApp
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
    
    $rawRes = wa_raw_request('POST', $baseUrl . '/group/create/' . urlencode($instanceName), $payload, 60);
    $response = $rawRes['body'];
    $httpCode = $rawRes['status'];
    
    if ($httpCode === 200 || $httpCode === 201) {
        $newJid = (string)($rawRes['json']['id'] ?? '');
        flash_set('success', wa_provider_can('group_add_members')
            ? 'Grupo criado com sucesso!'
            : 'Grupo criado na API oficial. Os participantes escolhidos receberam o link de convite (entram ao tocar nele; máximo ' . WA_CLOUD_GROUP_MAX_PARTICIPANTS . ').');
        audit_log('whatsapp_group_created', 'Grupo WhatsApp criado: ' . $groupName);
        
        // Salvar no banco de dados
        try { db()->exec("ALTER TABLE whatsapp_groups ADD COLUMN instance_name VARCHAR(100) NULL"); } catch (Throwable $e) {}
        try {
            $stmt = db()->prepare(
                "INSERT INTO whatsapp_groups (name, description, evolution_group_jid, instance_name, status, created_by_user_id, created_at)
                VALUES (:name, :desc, :jid, :inst, 'active', :uid, NOW())"
            );
            $stmt->execute([
                'name' => $groupName,
                'desc' => $description,
                'jid' => $newJid !== '' ? $newJid : null,
                'inst' => $instanceName,
                'uid' => auth_user_id()
            ]);
        } catch (Throwable $e) {
            error_log('[CHAT_CREATE_GROUP] ' . $e->getMessage());
        }
    } else {
        flash_set('error', 'Erro ao criar grupo: ' . wa_api_error_message(['status' => $httpCode, 'json' => $rawRes['json'], 'body_raw' => $response !== '' ? $response : $rawRes['error']]));
    }
} catch (Exception $e) {
    flash_set('error', 'Erro ao criar grupo: ' . $e->getMessage());
}

header('Location: /chat_web.php?type=groups');
exit;
