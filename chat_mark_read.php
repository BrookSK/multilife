<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: application/json; charset=utf-8');

$chatId = $_POST['chat_id'] ?? '';

if (empty($chatId)) {
    echo json_encode(['success' => false, 'error' => 'Chat ID não fornecido']);
    exit;
}

try {
    // Marcar todas as mensagens do chat como lidas (apenas mensagens recebidas, from_me=0)
    $stmt = db()->prepare("
        UPDATE chat_messages 
        SET is_read = 1 
        WHERE remote_jid = ? 
        AND from_me = 0 
        AND is_read = 0
    ");
    $stmt->execute([$chatId]);
    
    $affectedRows = $stmt->rowCount();
    
    error_log("[MARK_READ] Chat: $chatId | Mensagens marcadas como lidas: $affectedRows");
    
    echo json_encode([
        'success' => true,
        'marked' => $affectedRows
    ]);
    
} catch (Exception $e) {
    error_log("[MARK_READ] Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
