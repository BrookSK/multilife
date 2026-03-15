<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /chat_list.php');
    exit;
}

$stmt = db()->prepare('SELECT id, status FROM chat_conversations WHERE id = :id');
$stmt->execute(['id' => $id]);
$chat = $stmt->fetch();

if (!$chat) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_list.php');
    exit;
}

if ((string)$chat['status'] !== 'waiting') {
    flash_set('warning', 'Esta conversa já foi aceita.');
    header('Location: /chat_view.php?id=' . $id);
    exit;
}

$db = db();
$db->beginTransaction();

try {
    // Mudar status para active e atribuir ao usuário atual
    $stmt = $db->prepare(
        'UPDATE chat_conversations 
         SET status = :status, assigned_user_id = :uid, updated_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'id' => $id,
        'status' => 'active',
        'uid' => auth_user_id()
    ]);
    
    // Registrar evento
    $stmt = $db->prepare(
        'INSERT INTO chat_events (conversation_id, event_type, to_user_id, note)
         VALUES (:cid, :type, :uid, :note)'
    );
    $stmt->execute([
        'cid' => $id,
        'type' => 'assign',
        'uid' => auth_user_id(),
        'note' => 'Chat aceito e movido para Ativa'
    ]);
    
    audit_log('update', 'chat_conversations', (string)$id, null, [
        'action' => 'accept',
        'status' => 'active'
    ]);
    
    $db->commit();
    
    flash_set('success', 'Chat aceito! Agora você pode responder.');
    header('Location: /chat_view.php?id=' . $id);
    exit;
    
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao aceitar chat: ' . $e->getMessage());
    header('Location: /chat_view.php?id=' . $id);
    exit;
}
