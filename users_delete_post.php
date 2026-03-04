<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, name, email, status FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /users_list.php');
    exit;
}

if (auth_user_id() === (int)$old['id']) {
    flash_set('error', 'Você não pode excluir seu próprio usuário.');
    header('Location: /users_list.php');
    exit;
}

$stmt = db()->prepare('DELETE FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('delete', 'users', (string)$id, $old, null);

flash_set('success', 'Usuário excluído.');
header('Location: /users_list.php');
exit;
