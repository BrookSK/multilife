<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('roles.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, name, slug FROM roles WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Perfil não encontrado.');
    header('Location: /roles_list.php');
    exit;
}

$stmt = db()->prepare('DELETE FROM roles WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('delete', 'roles', (string)$id, $old, null);

flash_set('success', 'Perfil excluído.');
header('Location: /roles_list.php');
exit;
