<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: application/json; charset=utf-8');

error_log('[TRANSCRIBE_API] === REQUEST RECEBIDO ===');
error_log('[TRANSCRIBE_API] POST data: ' . json_encode($_POST));

// Receber ID da mensagem
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

error_log('[TRANSCRIBE_API] Message ID: ' . $messageId);

if ($messageId <= 0) {
    $response = ['success' => false, 'error' => 'ID da mensagem inválido'];
    error_log('[TRANSCRIBE_API] Erro: ID inválido');
    echo json_encode($response);
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
        error_log('[TRANSCRIBE_API] Transcrição já existe (cache)');
        $response = [
            'success' => true,
            'transcription' => $message['audio_transcription'],
            'cached' => true
        ];
        error_log('[TRANSCRIBE_API] Response: ' . json_encode($response));
        echo json_encode($response);
        exit;
    }
    
    // Verificar se arquivo existe
    $audioPath = __DIR__ . $message['media_url'];
    error_log('[TRANSCRIBE_API] Audio path: ' . $audioPath);
    error_log('[TRANSCRIBE_API] File exists: ' . (file_exists($audioPath) ? 'YES' : 'NO'));
    
    if (!file_exists($audioPath)) {
        $response = ['success' => false, 'error' => 'Arquivo de áudio não encontrado'];
        error_log('[TRANSCRIBE_API] Erro: Arquivo não encontrado');
        echo json_encode($response);
        exit;
    }
    
    // Transcrever usando Whisper API
    error_log('[TRANSCRIBE_API] Iniciando transcrição via Whisper API...');
    $openai = new OpenAiApi();
    $result = $openai->transcribeAudio($audioPath, 'pt');
    
    error_log('[TRANSCRIBE_API] Whisper API status: ' . $result['status']);
    
    if ($result['status'] >= 200 && $result['status'] < 300) {
        $transcription = $result['json']['text'] ?? '';
        error_log('[TRANSCRIBE_API] Transcrição recebida: ' . substr($transcription, 0, 100) . '...');
        
        if (!empty($transcription)) {
            // Salvar transcrição no banco
            $updateStmt = db()->prepare("
                UPDATE chat_messages 
                SET audio_transcription = ? 
                WHERE id = ?
            ");
            $updateStmt->execute([$transcription, $messageId]);
            
            $response = [
                'success' => true,
                'transcription' => $transcription,
                'cached' => false
            ];
            error_log('[TRANSCRIBE_API] ✅ Sucesso! Response: ' . json_encode($response));
            echo json_encode($response);
        } else {
            $response = ['success' => false, 'error' => 'Transcrição vazia'];
            error_log('[TRANSCRIBE_API] Erro: Transcrição vazia');
            echo json_encode($response);
        }
    } else {
        $errorMsg = $result['json']['error']['message'] ?? 'Erro desconhecido';
        $response = ['success' => false, 'error' => 'Erro na API: ' . $errorMsg];
        error_log('[TRANSCRIBE_API] Erro na API: ' . $errorMsg);
        echo json_encode($response);
    }
    
} catch (Exception $e) {
    error_log('[TRANSCRIBE_API] EXCEPTION: ' . $e->getMessage());
    error_log('[TRANSCRIBE_API] Stack trace: ' . $e->getTraceAsString());
    $response = ['success' => false, 'error' => $e->getMessage()];
    echo json_encode($response);
}
