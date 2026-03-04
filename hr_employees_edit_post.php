<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_employees_list.php');
    exit;
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$roleTitle = trim((string)($_POST['role_title'] ?? ''));
$status = (string)($_POST['status'] ?? 'active');
$notes = trim((string)($_POST['notes'] ?? ''));

if ($fullName === '') {
    flash_set('error', 'Informe o nome.');
    header('Location: /hr_employees_edit.php?id=' . $id);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /hr_employees_edit.php?id=' . $id);
    exit;
}

if (!in_array($status, ['active','inactive'], true)) {
    $status = 'active';
}

$stmt = db()->prepare('UPDATE hr_employees SET full_name = :n, email = :e, phone = :p, role_title = :r, status = :s, notes = :no WHERE id = :id');
$stmt->execute([
    'n' => $fullName,
    'e' => $email !== '' ? $email : null,
    'p' => $phone !== '' ? $phone : null,
    'r' => $roleTitle !== '' ? $roleTitle : null,
    's' => $status,
    'no' => $notes !== '' ? $notes : null,
    'id' => $id,
]);

audit_log('update', 'hr_employees', (string)$id, $old, ['full_name' => $fullName, 'status' => $status]);

flash_set('success', 'Funcionário atualizado.');
header('Location: /hr_employees_list.php');
exit;
