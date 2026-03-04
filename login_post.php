<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
$password = isset($_POST['password']) ? (string)$_POST['password'] : '';

if ($email === '' || $password === '') {
    header('Location: /login.php?error=' . urlencode('Informe e-mail e senha.') . '&email=' . urlencode($email));
    exit;
}

$stmt = db()->prepare('SELECT id, password_hash, status FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /login.php?error=' . urlencode('Credenciais inválidas.') . '&email=' . urlencode($email));
    exit;
}

if ((string)$user['status'] !== 'active') {
    header('Location: /login.php?error=' . urlencode('Usuário inativo.') . '&email=' . urlencode($email));
    exit;
}

if (!password_verify($password, (string)$user['password_hash'])) {
    header('Location: /login.php?error=' . urlencode('Credenciais inválidas.') . '&email=' . urlencode($email));
    exit;
}

auth_login((int)$user['id']);
header('Location: /dashboard.php');
exit;
