<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Grupo não encontrado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$specialty = trim((string)($_POST['specialty'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$state = strtoupper(trim((string)($_POST['state'] ?? '')));
$status = (string)($_POST['status'] ?? 'active');

if ($name === '') {
    flash_set('error', 'Informe o nome.');
    header('Location: /whatsapp_groups_edit.php?id=' . $id);
    exit;
}

if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
    flash_set('error', 'UF inválida.');
    header('Location: /whatsapp_groups_edit.php?id=' . $id);
    exit;
}

if (!in_array($status, ['active','inactive'], true)) {
    $status = 'active';
}

$stmt = db()->prepare('UPDATE whatsapp_groups SET name = :n, specialty = :sp, city = :c, state = :s, status = :st WHERE id = :id');
$stmt->execute([
    'n' => $name,
    'sp' => $specialty !== '' ? $specialty : null,
    'c' => $city !== '' ? $city : null,
    's' => $state !== '' ? $state : null,
    'st' => $status,
    'id' => $id,
]);

audit_log('update', 'whatsapp_groups', (string)$id, $old, ['name' => $name, 'status' => $status, 'specialty' => $specialty, 'city' => $city, 'state' => $state]);

flash_set('success', 'Grupo atualizado.');
header('Location: /whatsapp_groups_list.php');
exit;
