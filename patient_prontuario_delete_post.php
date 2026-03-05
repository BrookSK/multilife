<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM patient_prontuario_entries WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

$patientId = (int)$old['patient_id'];

$stmt = db()->prepare('DELETE FROM patient_prontuario_entries WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('delete', 'patient_prontuario_entries', (string)$id, $old, null);

flash_set('success', 'Registro excluído.');
header('Location: /patients_view.php?id=' . $patientId);
exit;
