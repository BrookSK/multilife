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

$stmt = db()->prepare('UPDATE patients SET deleted_at = NOW() WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('delete', 'patients', (string)$id, $old, null);

page_history_log(
    '/patients_list.php',
    'Pacientes',
    'delete',
    'Excluiu paciente: ' . ($old['full_name'] ?? 'ID ' . $id),
    'patient',
    $id
);

flash_set('success', 'Paciente excluído (lógico).');
header('Location: /patients_list.php');
exit;
