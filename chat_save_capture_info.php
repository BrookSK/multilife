<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json');

auth_require_login();
rbac_require_permission('chat.manage');

$chatId = isset($_POST['chat_id']) ? trim((string)$_POST['chat_id']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
$type = isset($_POST['type']) ? trim((string)$_POST['type']) : '';
$notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';

if ($chatId === '') {
    echo json_encode(['success' => false, 'error' => 'Chat ID não informado']);
    exit;
}

try {
    $db = db();
    
    // Garantir que a tabela existe
    $db->exec("
        CREATE TABLE IF NOT EXISTS chat_capture_info (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id VARCHAR(100) NOT NULL,
            status VARCHAR(50) DEFAULT 'aguardando',
            type VARCHAR(50) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_by_user_id INT UNSIGNED DEFAULT NULL,
            updated_by_user_id INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_chat_id (chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Verificar se já existe registro para este chat
    $stmt = $db->prepare('SELECT id FROM chat_capture_info WHERE chat_id = :chat_id');
    $stmt->execute(['chat_id' => $chatId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Atualizar registro existente
        $stmt = $db->prepare(
            'UPDATE chat_capture_info 
             SET status = :status, type = :type, notes = :notes, updated_at = NOW(), updated_by_user_id = :uid
             WHERE chat_id = :chat_id'
        );
        $stmt->execute([
            'chat_id' => $chatId,
            'status' => $status,
            'type' => $type,
            'notes' => $notes,
            'uid' => auth_user_id()
        ]);
    } else {
        // Criar novo registro
        $stmt = $db->prepare(
            'INSERT INTO chat_capture_info (chat_id, status, type, notes, created_by_user_id, updated_by_user_id)
             VALUES (:chat_id, :status, :type, :notes, :uid, :uid)'
        );
        $stmt->execute([
            'chat_id' => $chatId,
            'status' => $status,
            'type' => $type,
            'notes' => $notes,
            'uid' => auth_user_id()
        ]);
    }
    
    audit_log('update', 'chat_capture_info', $chatId, null, [
        'status' => $status,
        'type' => $type
    ]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log('Erro ao salvar informações de captação: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar: ' . $e->getMessage()]);
}
