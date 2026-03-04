<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('roles.manage');

$id = (int)($_POST['id'] ?? 0);
$permissionIds = $_POST['permission_ids'] ?? [];
if (!is_array($permissionIds)) {
    $permissionIds = [];
}

$stmt = db()->prepare('SELECT id, name, slug FROM roles WHERE id = :id');
$stmt->execute(['id' => $id]);
$role = $stmt->fetch();

if (!$role) {
    flash_set('error', 'Perfil não encontrado.');
    header('Location: /roles_list.php');
    exit;
}

$stmt = db()->prepare('SELECT p.slug FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = :rid');
$stmt->execute(['rid' => $id]);
$oldPerms = [];
foreach ($stmt->fetchAll() as $r) {
    $oldPerms[] = (string)$r['slug'];
}

$valid = [];
if (count($permissionIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
    $stmt = db()->prepare('SELECT id FROM permissions WHERE id IN (' . $placeholders . ')');
    $stmt->execute(array_map('intval', $permissionIds));
    foreach ($stmt->fetchAll() as $r) {
        $valid[] = (int)$r['id'];
    }
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('DELETE FROM role_permissions WHERE role_id = :rid');
    $stmt->execute(['rid' => $id]);

    if (count($valid) > 0) {
        $stmt = $db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)');
        foreach ($valid as $pid) {
            $stmt->execute(['rid' => $id, 'pid' => $pid]);
        }
    }

    $stmt = $db->prepare('SELECT p.slug FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = :rid');
    $stmt->execute(['rid' => $id]);
    $newPerms = [];
    foreach ($stmt->fetchAll() as $r) {
        $newPerms[] = (string)$r['slug'];
    }

    audit_log('update', 'role_permissions', (string)$id, ['permissions' => $oldPerms], ['permissions' => $newPerms]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Permissões do perfil atualizadas.');
header('Location: /roles_list.php');
exit;
