<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$roleTitle = trim((string)($_POST['role_title'] ?? ''));
$status = (string)($_POST['status'] ?? 'active');
$notes = trim((string)($_POST['notes'] ?? ''));

if ($fullName === '') {
    flash_set('error', 'Informe o nome.');
    header('Location: /hr_employees_create.php');
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /hr_employees_create.php');
    exit;
}

if (!in_array($status, ['active','inactive'], true)) {
    $status = 'active';
}

$stmt = db()->prepare('INSERT INTO hr_employees (full_name, email, phone, role_title, status, notes) VALUES (:n,:e,:p,:r,:s,:no)');
$stmt->execute([
    'n' => $fullName,
    'e' => $email !== '' ? $email : null,
    'p' => $phone !== '' ? $phone : null,
    'r' => $roleTitle !== '' ? $roleTitle : null,
    's' => $status,
    'no' => $notes !== '' ? $notes : null,
]);

$id = (string)db()->lastInsertId();
audit_log('create', 'hr_employees', $id, null, ['full_name' => $fullName, 'status' => $status]);

page_history_log(
    '/hr_employees_list.php',
    'Funcionários',
    'create',
    'Criou novo funcionário: ' . $name,
    'employee',
    (int)$employeeId
);

flash_set('success', 'Funcionário criado.');
header('Location: /hr_employees_list.php');
exit;
