<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$patientId = (int)($_POST['patient_id'] ?? 0);
$origin = trim((string)($_POST['origin'] ?? ''));
$occurredAtRaw = trim((string)($_POST['occurred_at'] ?? ''));
$professionalUserIdRaw = trim((string)($_POST['professional_user_id'] ?? ''));
$sessionsRaw = trim((string)($_POST['sessions_count'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$attachmentsJson = trim((string)($_POST['attachments_json'] ?? ''));

$stmt = db()->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

if ($origin === '') {
    flash_set('error', 'Informe a origem.');
    header('Location: /patient_prontuario_create.php?patient_id=' . (int)$patientId);
    exit;
}

if ($occurredAtRaw === '') {
    flash_set('error', 'Informe a data/hora.');
    header('Location: /patient_prontuario_create.php?patient_id=' . (int)$patientId);
    exit;
}

$occurredAtDb = null;
try {
    $dt = new DateTime($occurredAtRaw);
    $occurredAtDb = $dt->format('Y-m-d H:i:s');
} catch (Throwable $e) {
    $occurredAtDb = null;
}

if ($occurredAtDb === null) {
    flash_set('error', 'Data/hora inválida.');
    header('Location: /patient_prontuario_create.php?patient_id=' . (int)$patientId);
    exit;
}

$professionalUserId = null;
if ($professionalUserIdRaw !== '') {
    if (!ctype_digit($professionalUserIdRaw)) {
        flash_set('error', 'Profissional inválido.');
        header('Location: /patient_prontuario_create.php?patient_id=' . (int)$patientId);
        exit;
    }
    $professionalUserId = (int)$professionalUserIdRaw;
}

$sessionsCount = null;
if ($sessionsRaw !== '') {
    if (!ctype_digit($sessionsRaw) || (int)$sessionsRaw < 1) {
        flash_set('error', 'Quantidade de sessões inválida.');
        header('Location: /patient_prontuario_create.php?patient_id=' . (int)$patientId);
        exit;
    }
    $sessionsCount = (int)$sessionsRaw;
}

if ($attachmentsJson !== '') {
    $decoded = json_decode($attachmentsJson, true);
    if ($decoded === null && strtolower($attachmentsJson) !== 'null') {
        flash_set('error', 'Anexos (JSON) inválido.');
        header('Location: /patient_prontuario_create.php?patient_id=' . (int)$patientId);
        exit;
    }
}

$stmt = db()->prepare(
    'INSERT INTO patient_prontuario_entries (patient_id, professional_user_id, origin, occurred_at, sessions_count, notes, attachments_json)'
    . ' VALUES (:pid, :puid, :origin, :occurred_at, :sessions_count, :notes, :attachments_json)'
);
$stmt->execute([
    'pid' => $patientId,
    'puid' => $professionalUserId,
    'origin' => $origin,
    'occurred_at' => $occurredAtDb,
    'sessions_count' => $sessionsCount,
    'notes' => $notes !== '' ? $notes : null,
    'attachments_json' => $attachmentsJson !== '' ? $attachmentsJson : null,
]);

$entryId = (string)db()->lastInsertId();
audit_log('create', 'patient_prontuario_entries', $entryId, null, ['patient_id' => $patientId, 'origin' => $origin]);

flash_set('success', 'Registro criado.');
header('Location: /patients_view.php?id=' . (int)$patientId);
exit;
