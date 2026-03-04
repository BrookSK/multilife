<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$id = (int)($_POST['id'] ?? 0);
$toUserId = (int)($_POST['to_user_id'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));

$stmt = db()->prepare('SELECT id, assigned_user_id FROM chat_conversations WHERE id = :id');
$stmt->execute(['id' => $id]);
$c = $stmt->fetch();

if (!$c) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_list.php');
    exit;
}

$stmt = db()->prepare('SELECT id FROM users WHERE id = :id AND status = \'active\'');
$stmt->execute(['id' => $toUserId]);
if (!$stmt->fetch()) {
    flash_set('error', 'Usuário destino inválido.');
    header('Location: /chat_view.php?id=' . $id);
    exit;
}

$fromUserId = $c['assigned_user_id'] !== null ? (int)$c['assigned_user_id'] : null;

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE chat_conversations SET assigned_user_id = :to WHERE id = :id');
    $stmt->execute(['to' => $toUserId, 'id' => $id]);

    $stmt = $db->prepare('INSERT INTO chat_events (conversation_id, event_type, from_user_id, to_user_id, note) VALUES (:cid, :type, :from, :to, :note)');
    $stmt->execute([
        'cid' => $id,
        'type' => 'transfer',
        'from' => $fromUserId,
        'to' => $toUserId,
        'note' => $note !== '' ? $note : null,
    ]);

    audit_log('update', 'chat_transfer', (string)$id, ['assigned_user_id' => $fromUserId], ['assigned_user_id' => $toUserId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Conversa transferida.');
header('Location: /chat_view.php?id=' . $id);
exit;
