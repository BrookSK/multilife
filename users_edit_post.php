<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phoneRaw = trim((string)($_POST['phone'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$status = (string)($_POST['status'] ?? 'active');

$phone = null;
if ($phoneRaw !== '') {
    $digits = preg_replace('/\D+/', '', $phoneRaw);
    if ($digits === '' || mb_strlen($digits) < 10) {
        flash_set('error', 'Telefone inválido.');
        header('Location: /users_edit.php?id=' . $id);
        exit;
    }
    $phone = $digits;
}

$stmt = db()->prepare('SELECT id, name, email, phone, status FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /users_list.php');
    exit;
}

if ($name === '' || $email === '') {
    flash_set('error', 'Preencha nome e e-mail.');
    header('Location: /users_edit.php?id=' . $id);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /users_edit.php?id=' . $id);
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$stmt = db()->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
$stmt->execute(['email' => $email, 'id' => $id]);
if ($stmt->fetch()) {
    flash_set('error', 'Já existe outro usuário com esse e-mail.');
    header('Location: /users_edit.php?id=' . $id);
    exit;
}

if ($password !== '' && mb_strlen($password) < 8) {
    flash_set('error', 'Nova senha deve ter no mínimo 8 caracteres.');
    header('Location: /users_edit.php?id=' . $id);
    exit;
}

if ($password !== '' && (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))) {
    flash_set('error', 'Nova senha deve conter letras e números.');
    header('Location: /users_edit.php?id=' . $id);
    exit;
}

db()->beginTransaction();
try {
    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = db()->prepare('UPDATE users SET name = :name, email = :email, phone = :phone, status = :status, password_hash = :hash WHERE id = :id');
        $stmt->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'status' => $status, 'hash' => $hash, 'id' => $id]);
    } else {
        $stmt = db()->prepare('UPDATE users SET name = :name, email = :email, phone = :phone, status = :status WHERE id = :id');
        $stmt->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'status' => $status, 'id' => $id]);
    }

    audit_log('update', 'users', (string)$id, $old, ['name' => $name, 'email' => $email, 'phone' => $phone, 'status' => $status]);

    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    throw $e;
}

flash_set('success', 'Usuário atualizado.');
header('Location: /users_list.php');
exit;
