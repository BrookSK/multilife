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
$status = $input['status'] ?? '';

if (empty($chatId) || empty($status)) {
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

if (!in_array($status, ['aguardando', 'atendendo', 'resolvido'])) {
    echo json_encode(['success' => false, 'error' => 'Status inválido']);
    exit;
}

try {
    $stmt = db()->prepare("
        UPDATE chat_contacts 
        SET status = ?,
            assigned_to_user_id = ?,
            resolved_at = ?
        WHERE remote_jid = ?
    ");
    
    $userId = auth_user_id();
    $resolvedAt = ($status === 'resolvido') ? date('Y-m-d H:i:s') : null;
    
    $stmt->execute([$status, $userId, $resolvedAt, $chatId]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
