<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = auth_user_id();
$action = (string)($_POST['action'] ?? '');

if ($action === 'update_profile') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $specialty = trim((string)($_POST['specialty'] ?? ''));

    if ($name === '' || $email === '') {
        flash_set('error', 'Nome e e-mail são obrigatórios.');
        header('Location: /my_account.php');
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'E-mail inválido.');
        header('Location: /my_account.php');
        exit;
    }

    // Verificar se e-mail já existe para outro usuário
    $checkStmt = db()->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
    $checkStmt->execute(['email' => $email, 'id' => $userId]);
    if ($checkStmt->fetch()) {
        flash_set('error', 'Este e-mail já está em uso por outro usuário.');
        header('Location: /my_account.php');
        exit;
    }

    $stmt = db()->prepare('UPDATE users SET name = :name, email = :email, phone = :phone, specialty = :specialty WHERE id = :id');
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'specialty' => $specialty,
        'id' => $userId,
    ]);

    flash_set('success', 'Dados atualizados com sucesso.');
    header('Location: /my_account.php');
    exit;
}

if ($action === 'change_password') {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        flash_set('error', 'Preencha todos os campos de senha.');
        header('Location: /my_account.php');
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        flash_set('error', 'A nova senha e a confirmação não coincidem.');
        header('Location: /my_account.php');
        exit;
    }

    if (strlen($newPassword) < 6) {
        flash_set('error', 'A nova senha deve ter pelo menos 6 caracteres.');
        header('Location: /my_account.php');
        exit;
    }

    // Verificar senha atual
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
        flash_set('error', 'Senha atual incorreta.');
        header('Location: /my_account.php');
        exit;
    }

    // Atualizar senha
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $stmt->execute(['hash' => $newHash, 'id' => $userId]);

    flash_set('success', 'Senha alterada com sucesso!');
    header('Location: /my_account.php');
    exit;
}

flash_set('error', 'Ação inválida.');
header('Location: /my_account.php');
exit;
