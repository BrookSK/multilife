<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('permissions.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, name, slug FROM permissions WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Permissão não encontrada.');
    header('Location: /permissions_list.php');
    exit;
}

$stmt = db()->prepare('DELETE FROM permissions WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('delete', 'permissions', (string)$id, $old, null);

flash_set('success', 'Permissão excluída.');
header('Location: /permissions_list.php');
exit;
