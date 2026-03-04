<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$birthDate = trim((string)($_POST['birth_date'] ?? ''));
$state = strtoupper(trim((string)($_POST['address_state'] ?? '')));

if ($fullName === '') {
    flash_set('error', 'Informe o nome completo.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    flash_set('error', 'Data de nascimento inválida.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
    flash_set('error', 'UF inválida.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

$fields = [
    'cpf','rg','birth_date','whatsapp','email','phone_primary','phone_secondary','preferred_contact',
    'address_zip','address_street','address_number','address_complement','address_neighborhood','address_city','address_state',
    'emergency_name','emergency_relationship','emergency_phone',
];

$set = ['full_name = :full_name'];
$params = ['id' => $id, 'full_name' => $fullName];

foreach ($fields as $f) {
    $v = trim((string)($_POST[$f] ?? ''));
    if ($f === 'address_state') {
        $v = $state;
    }
    if ($f === 'birth_date') {
        $v = $birthDate;
    }
    $set[] = $f . ' = :' . $f;
    $params[$f] = ($v !== '') ? $v : null;
}

$stmt = db()->prepare('UPDATE patients SET ' . implode(', ', $set) . ' WHERE id = :id');
$stmt->execute($params);

audit_log('update', 'patients', (string)$id, $old, ['full_name' => $fullName]);

flash_set('success', 'Paciente atualizado.');
header('Location: /patients_view.php?id=' . $id);
exit;
