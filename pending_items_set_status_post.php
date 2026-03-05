<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status = isset($_GET['status']) ? (string)$_GET['status'] : '';

if ($id <= 0 || !in_array($status, ['done', 'dismissed'], true)) {
    flash_set('error', 'Ação inválida.');
    header('Location: /pending_items_list.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM pending_items WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Pendência não encontrada.');
    header('Location: /pending_items_list.php');
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE pending_items SET status = :st, resolved_at = NOW() WHERE id = :id');
    $stmt->execute(['st' => $status, 'id' => $id]);

    audit_log('update', 'pending_items', (string)$id, $old, ['status' => $status]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Pendência atualizada.');
header('Location: /pending_items_list.php');
exit;
