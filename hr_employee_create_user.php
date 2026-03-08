<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

// Buscar funcionário
$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $employeeId]);
$employee = $stmt->fetch();

if (!$employee) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_dashboard.php');
    exit;
}

// Verificar se já tem usuário vinculado
if (!empty($employee['user_id'])) {
    flash_set('error', 'Este funcionário já possui um usuário vinculado.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId);
    exit;
}

// Validar dados necessários
if (empty($employee['email'])) {
    flash_set('error', 'Funcionário precisa ter e-mail cadastrado para criar usuário.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=cadastro');
    exit;
}

if (empty($employee['role_id'])) {
    flash_set('error', 'Funcionário precisa ter uma função definida para criar usuário.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=cadastro');
    exit;
}

// Verificar se já existe usuário com este e-mail
$stmt = db()->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => $employee['email']]);
$existingUser = $stmt->fetch();

if ($existingUser) {
    flash_set('error', 'Já existe um usuário com este e-mail.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId);
    exit;
}

// Gerar senha temporária
$tempPassword = bin2hex(random_bytes(8)); // Senha aleatória de 16 caracteres

// Criar usuário
$stmt = db()->prepare('INSERT INTO users (name, email, password, status) VALUES (:name, :email, :password, "active")');
$stmt->execute([
    'name' => $employee['full_name'],
    'email' => $employee['email'],
    'password' => password_hash($tempPassword, PASSWORD_DEFAULT)
]);

$userId = (int)db()->lastInsertId();

// Vincular role ao usuário
$stmt = db()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
$stmt->execute([
    'user_id' => $userId,
    'role_id' => (int)$employee['role_id']
]);

// Vincular usuário ao funcionário
$stmt = db()->prepare('UPDATE hr_employees SET user_id = :user_id WHERE id = :id');
$stmt->execute([
    'user_id' => $userId,
    'id' => $employeeId
]);

// Log de auditoria
audit_log('create', 'users', (string)$userId, null, [
    'name' => $employee['full_name'],
    'email' => $employee['email'],
    'created_from_hr' => true,
    'employee_id' => $employeeId
]);

// Mensagem de sucesso com a senha temporária
flash_set('success', 'Usuário criado com sucesso! E-mail: ' . $employee['email'] . ' | Senha temporária: ' . $tempPassword . ' (anote esta senha, ela não será exibida novamente)');

header('Location: /hr_employee_profile.php?id=' . $employeeId);
exit;
