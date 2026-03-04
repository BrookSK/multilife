<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM appointments WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Agendamento não encontrado.');
    header('Location: /appointments_list.php');
    exit;
}

$patientId = (int)($_POST['patient_id'] ?? 0);
$professionalUserId = (int)($_POST['professional_user_id'] ?? 0);
$firstAt = trim((string)($_POST['first_at'] ?? ''));
$recurrenceType = (string)($_POST['recurrence_type'] ?? 'single');
$recurrenceRule = trim((string)($_POST['recurrence_rule'] ?? ''));
$valuePerSession = (string)($_POST['value_per_session'] ?? '0');

if ($patientId <= 0 || $professionalUserId <= 0 || $firstAt === '') {
    flash_set('error', 'Preencha paciente, profissional e data/hora.');
    header('Location: /appointments_edit.php?id=' . $id);
    exit;
}

$allowedRec = ['single','weekly','monthly','custom'];
if (!in_array($recurrenceType, $allowedRec, true)) {
    $recurrenceType = 'single';
}

$dt = DateTime::createFromFormat('Y-m-d\TH:i', $firstAt);
if (!$dt) {
    flash_set('error', 'Data/hora inválida.');
    header('Location: /appointments_edit.php?id=' . $id);
    exit;
}
$firstAtDb = $dt->format('Y-m-d H:i:00');

$stmt = db()->prepare('SELECT id FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
if (!$stmt->fetch()) {
    flash_set('error', 'Paciente inválido.');
    header('Location: /appointments_edit.php?id=' . $id);
    exit;
}

$stmt = db()->prepare(
    "SELECT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.id = :id AND u.status='active' AND r.slug='profissional' LIMIT 1"
);
$stmt->execute(['id' => $professionalUserId]);
if (!$stmt->fetch()) {
    flash_set('error', 'Profissional inválido.');
    header('Location: /appointments_edit.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('UPDATE appointments SET patient_id = :pid, professional_user_id = :uid, first_at = :fa, recurrence_type = :rt, recurrence_rule = :rr, value_per_session = :v WHERE id = :id');
$stmt->execute([
    'pid' => $patientId,
    'uid' => $professionalUserId,
    'fa' => $firstAtDb,
    'rt' => $recurrenceType,
    'rr' => $recurrenceRule !== '' ? $recurrenceRule : null,
    'v' => $valuePerSession,
    'id' => $id,
]);

audit_log('update', 'appointments', (string)$id, $old, ['patient_id' => $patientId, 'professional_user_id' => $professionalUserId, 'first_at' => $firstAtDb]);

flash_set('success', 'Agendamento atualizado.');
header('Location: /appointments_view.php?id=' . $id);
exit;
