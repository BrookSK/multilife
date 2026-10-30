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
    // Só as mensagens dos WhatsApps que o usuário vê: abrir a conversa na Captação
    // não pode zerar o "não lidas" da mesma pessoa no WhatsApp do Financeiro.
    [$instSql, $instParams] = whatsapp_messages_instance_filter(
        (int)(auth_user_id() ?? 0), (string)$chatId, trim((string)($_POST['instance'] ?? ''))
    );
    // Marcar todas as mensagens do chat como lidas (apenas mensagens recebidas, from_me=0)
    $stmt = db()->prepare("
        UPDATE chat_messages 
        SET is_read = 1 
        WHERE remote_jid = ? 
        AND from_me = 0 
        AND is_read = 0" . $instSql . "
    ");
    $stmt->execute(array_merge([$chatId], $instParams));
    
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
