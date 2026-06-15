<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phoneRaw = trim((string)($_POST['phone'] ?? ''));
$specialty = trim((string)($_POST['specialty'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$status = (string)($_POST['status'] ?? 'active');

$phone = null;
if ($phoneRaw !== '') {
    $digits = preg_replace('/\D+/', '', $phoneRaw);
    if ($digits === '' || mb_strlen($digits) < 10) {
        flash_set('error', 'Telefone inválido.');
        header('Location: /users_create.php');
        exit;
    }
    $phone = $digits;
}

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
$stmt = db()->prepare('INSERT INTO users (name, email, phone, specialty, password_hash, status) VALUES (:name, :email, :phone, :specialty, :hash, :status)');
$stmt->execute([
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'specialty' => $specialty !== '' ? $specialty : null,
    'hash' => $hash,
    'status' => $status,
]);

$id = (string)db()->lastInsertId();
audit_log('create', 'users', $id, null, ['name' => $name, 'email' => $email, 'phone' => $phone, 'specialty' => $specialty, 'status' => $status]);

// Atribuir role selecionada no formulário
$selectedRoles = [];
$selectedRole = trim((string)($_POST['role'] ?? ''));
$autoRole = trim((string)($_POST['auto_role'] ?? ''));

// Prioridade: campo radio > auto_role (do chat)
if ($selectedRole !== '') {
    $selectedRoles = [$selectedRole];
} elseif ($autoRole !== '') {
    $selectedRoles = [$autoRole];
} elseif (!empty($_POST['roles']) && is_array($_POST['roles'])) {
    // Fallback para formato antigo (checkbox)
    $selectedRoles = [(string)$_POST['roles'][0]];
}

if (!empty($selectedRoles)) {
    foreach ($selectedRoles as $roleSlug) {
        $roleSlug = trim((string)$roleSlug);
        if ($roleSlug === '') continue;
        try {
            $stmtRole = db()->prepare("SELECT id FROM roles WHERE slug = ?");
            $stmtRole->execute([$roleSlug]);
            $roleRow = $stmtRole->fetch();
            if ($roleRow) {
                $stmtAssign = db()->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
                $stmtAssign->execute([(int)$id, (int)$roleRow['id']]);
            }
        } catch (Exception $e) {
            error_log("[USER_CREATE] Erro ao atribuir role '$roleSlug': " . $e->getMessage());
        }
    }
}

page_history_log(
    '/users_list.php',
    'Usuários',
    'create',
    'Criou novo usuário: ' . $name,
    'user',
    (int)$id
);

flash_set('success', 'Usuário criado.');
header('Location: /users_list.php');
exit;
