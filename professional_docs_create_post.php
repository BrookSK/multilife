<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

$uid = (int)auth_user_id();

$patientRef = trim((string)($_POST['patient_ref'] ?? ''));
$sessions = (int)($_POST['sessions_count'] ?? 1);
$billing = trim((string)($_POST['billing_docs'] ?? ''));
$productivity = trim((string)($_POST['productivity_docs'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($patientRef === '') {
    flash_set('error', 'Informe o paciente.');
    header('Location: /professional_docs_create.php');
    exit;
}

if ($sessions < 1) {
    $sessions = 1;
}

$stmt = db()->prepare(
    'INSERT INTO professional_documentations (
        professional_user_id, patient_ref, sessions_count, billing_docs, productivity_docs, notes, status, due_at
     ) VALUES (
        :uid, :patient_ref, :sessions_count, :billing_docs, :productivity_docs, :notes, \'draft\', DATE_ADD(NOW(), INTERVAL 48 HOUR)
     )'
);
$stmt->execute([
    'uid' => $uid,
    'patient_ref' => $patientRef,
    'sessions_count' => $sessions,
    'billing_docs' => $billing !== '' ? $billing : null,
    'productivity_docs' => $productivity !== '' ? $productivity : null,
    'notes' => $notes !== '' ? $notes : null,
]);

$id = (string)db()->lastInsertId();
audit_log('create', 'professional_documentations', $id, null, ['patient_ref' => $patientRef, 'status' => 'draft']);

flash_set('success', 'Rascunho criado.');
header('Location: /professional_docs_edit.php?id=' . urlencode($id));
exit;
