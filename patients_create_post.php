<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$fullName = trim((string)($_POST['full_name'] ?? ''));
$cpf = trim((string)($_POST['cpf'] ?? ''));
$rg = trim((string)($_POST['rg'] ?? ''));
$birthDate = trim((string)($_POST['birth_date'] ?? ''));
$whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
$phonePrimary = trim((string)($_POST['phone_primary'] ?? ''));
$phoneSecondary = trim((string)($_POST['phone_secondary'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));

if ($fullName === '') {
    flash_set('error', 'Informe o nome completo.');
    header('Location: /patients_create.php');
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /patients_create.php');
    exit;
}

if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    flash_set('error', 'Data de nascimento inválida.');
    header('Location: /patients_create.php');
    exit;
}

$stmt = db()->prepare('INSERT INTO patients (full_name, cpf, rg, birth_date, whatsapp, phone_primary, phone_secondary, email) VALUES (:n,:cpf,:rg,:bd,:wa,:pp,:ps,:em)');
$stmt->execute([
    'n' => $fullName,
    'cpf' => $cpf !== '' ? $cpf : null,
    'rg' => $rg !== '' ? $rg : null,
    'bd' => $birthDate !== '' ? $birthDate : null,
    'wa' => $whatsapp !== '' ? $whatsapp : null,
    'pp' => $phonePrimary !== '' ? $phonePrimary : null,
    'ps' => $phoneSecondary !== '' ? $phoneSecondary : null,
    'em' => $email !== '' ? $email : null,
]);

$id = (string)db()->lastInsertId();
audit_log('create', 'patients', $id, null, ['full_name' => $fullName]);

flash_set('success', 'Paciente criado.');
header('Location: /patients_view.php?id=' . urlencode($id));
exit;
