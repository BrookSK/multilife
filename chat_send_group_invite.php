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

try {
    // Usar EvolutionApiV1 (mesma classe que funciona no envio de mensagens)
    $api = new EvolutionApiV1();
    
    // Extrair número do telefone do chatId
    $participantPhone = str_replace(['@s.whatsapp.net', '@g.us', '@lid'], '', $chatId);
    $participantJid = $participantPhone . '@s.whatsapp.net';
    
    // 1. Adicionar participante ao grupo
    $addResult = $api->updateGroupParticipant($groupJid, 'add', [$participantJid]);
    $addHttpCode = (int)($addResult['status'] ?? 0);
    
    if ($addHttpCode !== 200 && $addHttpCode !== 201) {
        $errorMsg = is_string($addResult['body_raw'] ?? null) 
                    ? $addResult['body_raw'] 
                    : json_encode($addResult['json'] ?? []);
        echo json_encode([
            'success' => false, 
            'error' => 'Erro ao adicionar participante ao grupo',
            'http_code' => $addHttpCode,
            'details' => substr($errorMsg, 0, 200)
        ]);
        exit;
    }
    
    // 2. Enviar mensagem de boas-vindas no grupo (se fornecida)
    if (!empty($welcomeMessage)) {
        sleep(2); // Aguardar 2 segundos para garantir que o participante foi adicionado
        
        $messageResult = $api->sendText($groupJid, $welcomeMessage);
        $messageHttpCode = (int)($messageResult['status'] ?? 0);
        
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
