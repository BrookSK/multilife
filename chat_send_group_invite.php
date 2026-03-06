<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$chatId = $input['chat_id'] ?? '';
$groupJid = $input['group_jid'] ?? '';
$welcomeMessage = $input['welcome_message'] ?? '';

if (empty($chatId) || empty($groupJid)) {
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    echo json_encode(['success' => false, 'error' => 'Evolution API não configurada']);
    exit;
}

try {
    // Extrair número do telefone do chatId
    $participantPhone = str_replace(['@s.whatsapp.net', '@g.us'], '', $chatId);
    
    // 1. Adicionar participante ao grupo
    $addUrl = $baseUrl . '/group/updateParticipant/' . urlencode($instanceName);
    $addPayload = json_encode([
        'groupJid' => $groupJid,
        'action' => 'add',
        'participants' => [$participantPhone . '@s.whatsapp.net']
    ]);
    
    $ch = curl_init($addUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $addPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $addResponse = curl_exec($ch);
    $addHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($addHttpCode !== 200 && $addHttpCode !== 201) {
        echo json_encode([
            'success' => false, 
            'error' => 'Erro ao adicionar participante ao grupo. HTTP Code: ' . $addHttpCode,
            'response' => $addResponse
        ]);
        exit;
    }
    
    // 2. Enviar mensagem de boas-vindas no grupo (se fornecida)
    if (!empty($welcomeMessage)) {
        sleep(2); // Aguardar 2 segundos para garantir que o participante foi adicionado
        
        $messageUrl = $baseUrl . '/message/sendText/' . urlencode($instanceName);
        $messagePayload = json_encode([
            'number' => $groupJid,
            'text' => $welcomeMessage
        ]);
        
        $ch = curl_init($messageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $messagePayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $messageResponse = curl_exec($ch);
        $messageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($messageHttpCode !== 200 && $messageHttpCode !== 201) {
            // Participante foi adicionado, mas mensagem falhou
            echo json_encode([
                'success' => true, 
                'warning' => 'Participante adicionado, mas erro ao enviar mensagem de boas-vindas',
                'message_http_code' => $messageHttpCode
            ]);
            exit;
        }
    }
    
    // Registrar ação no log de auditoria
    audit_log('group_invite_sent', 'Convite enviado para ' . $chatId . ' no grupo ' . $groupJid);
    
    echo json_encode(['success' => true, 'message' => 'Convite enviado com sucesso!']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
