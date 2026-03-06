<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

// Aceitar tanto GET quanto POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$chatId = $input['chat_id'] ?? '';
$captureType = $input['capture_type'] ?? '';
$captureNotes = $input['capture_notes'] ?? '';

if (empty($chatId)) {
    echo json_encode(['success' => false, 'error' => 'Chat ID inválido']);
    exit;
}

try {
    // Verificar se colunas existem
    $columns = db()->query("SHOW COLUMNS FROM chat_contacts LIKE 'capture_type'")->fetch();
    if (!$columns) {
        db()->exec("ALTER TABLE chat_contacts ADD COLUMN capture_type VARCHAR(50) DEFAULT NULL");
    }
    $notesCol = db()->query("SHOW COLUMNS FROM chat_contacts LIKE 'capture_notes'")->fetch();
    if (!$notesCol) {
        db()->exec("ALTER TABLE chat_contacts ADD COLUMN capture_notes TEXT DEFAULT NULL");
    }
    
    $stmt = db()->prepare("
        UPDATE chat_contacts 
        SET capture_type = ?,
            capture_notes = ?
        WHERE remote_jid = ?
    ");
    
    $stmt->execute([$captureType, $captureNotes, $chatId]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
