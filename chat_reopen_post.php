<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, status FROM chat_conversations WHERE id = :id');
$stmt->execute(['id' => $id]);
$c = $stmt->fetch();

if (!$c) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_list.php');
    exit;
}

if ((string)$c['status'] === 'open') {
    flash_set('success', 'Conversa já estava aberta.');
    header('Location: /chat_view.php?id=' . $id);
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE chat_conversations SET status = \'open\' WHERE id = :id');
    $stmt->execute(['id' => $id]);

    $stmt = $db->prepare('INSERT INTO chat_events (conversation_id, event_type, from_user_id, to_user_id, note) VALUES (:cid, :type, :from, NULL, NULL)');
    $stmt->execute([
        'cid' => $id,
        'type' => 'reopen',
        'from' => auth_user_id(),
    ]);

    audit_log('update', 'chat_reopen', (string)$id, ['status' => 'closed'], ['status' => 'open']);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Conversa reaberta.');
header('Location: /chat_view.php?id=' . $id);
exit;
