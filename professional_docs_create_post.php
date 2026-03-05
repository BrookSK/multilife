<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

$uid = (int)auth_user_id();

$patientId = (int)($_POST['patient_id'] ?? 0);
$patientRef = trim((string)($_POST['patient_ref'] ?? ''));
$sessions = (int)($_POST['sessions_count'] ?? 1);
$billing = trim((string)($_POST['billing_docs'] ?? ''));
$productivity = trim((string)($_POST['productivity_docs'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($patientId > 0) {
    $stmt = db()->prepare(
        'SELECT p.id, p.full_name\n'
        . 'FROM patients p\n'
        . 'INNER JOIN patient_professionals pp ON pp.patient_id = p.id\n'
        . 'WHERE p.deleted_at IS NULL AND pp.professional_user_id = :uid AND pp.is_active = 1 AND p.id = :pid\n'
        . 'LIMIT 1'
    );
    $stmt->execute(['uid' => $uid, 'pid' => $patientId]);
    $p = $stmt->fetch();
    if (!$p) {
        flash_set('error', 'Paciente inválido ou não vinculado ao seu perfil.');
        header('Location: /professional_docs_create.php');
        exit;
    }
    $patientRef = (string)$p['full_name'] . ' (#' . (int)$p['id'] . ')';
} else {
    if ($patientRef === '') {
        flash_set('error', 'Informe o paciente.');
        header('Location: /professional_docs_create.php');
        exit;
    }
}

if ($sessions < 1) {
    $sessions = 1;
}

$stmt = db()->prepare(
    'INSERT INTO professional_documentations (
        professional_user_id, patient_id, patient_ref, sessions_count, billing_docs, productivity_docs, notes, status, due_at
     ) VALUES (
        :uid, :patient_id, :patient_ref, :sessions_count, :billing_docs, :productivity_docs, :notes, \'draft\', DATE_ADD(NOW(), INTERVAL 48 HOUR)
     )'
);
$stmt->execute([
    'uid' => $uid,
    'patient_id' => $patientId > 0 ? $patientId : null,
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
