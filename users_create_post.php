<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$status = (string)($_POST['status'] ?? 'active');

if ($name === '' || $email === '' || $password === '') {
    flash_set('error', 'Preencha nome, e-mail e senha.');
    header('Location: /users_create.php');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /users_create.php');
    exit;
}

if (mb_strlen($password) < 8) {
    flash_set('error', 'Senha deve ter no mínimo 8 caracteres.');
    header('Location: /users_create.php');
    exit;
}

if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
    flash_set('error', 'Senha deve conter letras e números.');
    header('Location: /users_create.php');
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    flash_set('error', 'Já existe um usuário com esse e-mail.');
    header('Location: /users_create.php');
    exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = db()->prepare('INSERT INTO users (name, email, password_hash, status) VALUES (:name, :email, :hash, :status)');
$stmt->execute([
    'name' => $name,
    'email' => $email,
    'hash' => $hash,
    'status' => $status,
]);

$id = (string)db()->lastInsertId();
audit_log('create', 'users', $id, null, ['name' => $name, 'email' => $email, 'status' => $status]);

flash_set('success', 'Usuário criado.');
header('Location: /users_list.php');
exit;
