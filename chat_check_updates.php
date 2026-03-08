<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: application/json');

$chatId = trim($_GET['chat_id'] ?? '');
$lastTimestamp = (int)($_GET['last_timestamp'] ?? 0);

if (empty($chatId)) {
    echo json_encode(['has_new_messages' => false]);
    exit;
}

try {
    // Verificar se há mensagens mais recentes que o último timestamp
    $stmt = db()->prepare("
        SELECT COUNT(*) as new_count
        FROM chat_messages
        WHERE remote_jid = ?
        AND message_timestamp > ?
    ");
    $stmt->execute([$chatId, $lastTimestamp]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $hasNewMessages = ((int)$result['new_count']) > 0;
    
    echo json_encode([
        'has_new_messages' => $hasNewMessages,
        'new_count' => (int)$result['new_count']
    ]);
    
} catch (Exception $e) {
    error_log('Erro ao verificar atualizações: ' . $e->getMessage());
    echo json_encode(['has_new_messages' => false, 'error' => $e->getMessage()]);
}
