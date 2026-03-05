<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$id = (int)($_POST['id'] ?? 0);
$body = trim((string)($_POST['body'] ?? ''));

if ($body === '') {
    flash_set('error', 'Digite uma mensagem.');
    header('Location: /chat_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT id, status FROM chat_conversations WHERE id = :id');
$stmt->execute(['id' => $id]);
$c = $stmt->fetch();

if (!$c) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_list.php');
    exit;
}

if ((string)$c['status'] !== 'open') {
    flash_set('error', 'Conversa finalizada. Reabra para enviar mensagens.');
    header('Location: /chat_view.php?id=' . $id);
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO chat_messages (conversation_id, direction, body, sent_by_user_id) VALUES (:cid, :dir, :body, :uid)');
    $stmt->execute([
        'cid' => $id,
        'dir' => 'out',
        'body' => $body,
        'uid' => auth_user_id(),
    ]);

    $preview = mb_strimwidth($body, 0, 250, '...');
    $stmt = $db->prepare('UPDATE chat_conversations SET last_message_at = NOW(), last_message_preview = :p WHERE id = :id');
    $stmt->execute(['p' => $preview, 'id' => $id]);

    $stmt = $db->prepare(
        "UPDATE pending_items\n"
        . "SET status = 'done', resolved_at = NOW()\n"
        . "WHERE status = 'open' AND type = 'chat_unanswered'\n"
        . "  AND related_table = 'chat_conversations' AND related_id = :rid"
    );
    $stmt->execute(['rid' => $id]);

    audit_log('create', 'chat_messages', (string)$id, null, ['direction' => 'out', 'body' => $preview]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Mensagem enviada (integração WhatsApp será adicionada depois).');
header('Location: /chat_view.php?id=' . $id);
exit;
