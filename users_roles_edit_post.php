<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$id = (int)($_POST['id'] ?? 0);
$roleIds = $_POST['role_ids'] ?? [];
if (!is_array($roleIds)) {
    $roleIds = [];
}

$stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /users_list.php');
    exit;
}

$stmt = db()->prepare('SELECT r.slug FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :uid');
$stmt->execute(['uid' => $id]);
$oldRoles = [];
foreach ($stmt->fetchAll() as $r) {
    $oldRoles[] = (string)$r['slug'];
}

$valid = [];
if (count($roleIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $stmt = db()->prepare('SELECT id FROM roles WHERE id IN (' . $placeholders . ')');
    $stmt->execute(array_map('intval', $roleIds));
    foreach ($stmt->fetchAll() as $r) {
        $valid[] = (int)$r['id'];
    }
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('DELETE FROM user_roles WHERE user_id = :uid');
    $stmt->execute(['uid' => $id]);

    if (count($valid) > 0) {
        $stmt = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
        foreach ($valid as $rid) {
            $stmt->execute(['uid' => $id, 'rid' => $rid]);
        }
    }

    $stmt = $db->prepare('SELECT r.slug FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :uid');
    $stmt->execute(['uid' => $id]);
    $newRoles = [];
    foreach ($stmt->fetchAll() as $r) {
        $newRoles[] = (string)$r['slug'];
    }

    audit_log('update', 'user_roles', (string)$id, ['roles' => $oldRoles], ['roles' => $newRoles]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Perfis atualizados.');
header('Location: /users_list.php');
exit;
