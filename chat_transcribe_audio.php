<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: application/json; charset=utf-8');

// Receber ID da mensagem
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if ($messageId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID da mensagem inválido']);
    exit;
}

try {
    // Buscar mensagem
    $stmt = db()->prepare("
        SELECT 
            id,
            media_url,
            audio_transcription
        FROM chat_messages
        WHERE id = ? AND message_type = 'audio'
    ");
    $stmt->execute([$messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$message) {
        echo json_encode(['success' => false, 'error' => 'Mensagem de áudio não encontrada']);
        exit;
    }
    
    // Se já tem transcrição, retornar
    if (!empty($message['audio_transcription'])) {
        echo json_encode([
            'success' => true,
            'transcription' => $message['audio_transcription'],
            'cached' => true
        ]);
        exit;
    }
    
    // Verificar se arquivo existe
    $audioPath = __DIR__ . $message['media_url'];
    if (!file_exists($audioPath)) {
        echo json_encode(['success' => false, 'error' => 'Arquivo de áudio não encontrado']);
        exit;
    }
    
    // Transcrever usando Whisper API
    $openai = new OpenAiApi();
    $result = $openai->transcribeAudio($audioPath, 'pt');
    
    if ($result['status'] >= 200 && $result['status'] < 300) {
        $transcription = $result['json']['text'] ?? '';
        
        if (!empty($transcription)) {
            // Salvar transcrição no banco
            $updateStmt = db()->prepare("
                UPDATE chat_messages 
                SET audio_transcription = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$transcription, $messageId]);
            
            echo json_encode([
                'success' => true,
                'transcription' => $transcription,
                'cached' => false
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Transcrição vazia']);
        }
    } else {
        $errorMsg = $result['json']['error']['message'] ?? 'Erro desconhecido';
        echo json_encode(['success' => false, 'error' => 'Erro na API: ' . $errorMsg]);
    }
    
} catch (Exception $e) {
    error_log('[TRANSCRIBE] Erro: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
