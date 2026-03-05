<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$id = (int)($_POST['id'] ?? 0);
$origin = trim((string)($_POST['origin'] ?? ''));
$occurredAtRaw = trim((string)($_POST['occurred_at'] ?? ''));
$professionalUserIdRaw = trim((string)($_POST['professional_user_id'] ?? ''));
$sessionsRaw = trim((string)($_POST['sessions_count'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$attachmentsJson = trim((string)($_POST['attachments_json'] ?? ''));

$stmt = db()->prepare('SELECT * FROM patient_prontuario_entries WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

$patientId = (int)$old['patient_id'];

if ($origin === '') {
    flash_set('error', 'Informe a origem.');
    header('Location: /patient_prontuario_edit.php?id=' . $id);
    exit;
}

if ($occurredAtRaw === '') {
    flash_set('error', 'Informe a data/hora.');
    header('Location: /patient_prontuario_edit.php?id=' . $id);
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
    header('Location: /patient_prontuario_edit.php?id=' . $id);
    exit;
}

$professionalUserId = null;
if ($professionalUserIdRaw !== '') {
    if (!ctype_digit($professionalUserIdRaw)) {
        flash_set('error', 'Profissional inválido.');
        header('Location: /patient_prontuario_edit.php?id=' . $id);
        exit;
    }
    $professionalUserId = (int)$professionalUserIdRaw;
}

$sessionsCount = null;
if ($sessionsRaw !== '') {
    if (!ctype_digit($sessionsRaw) || (int)$sessionsRaw < 1) {
        flash_set('error', 'Quantidade de sessões inválida.');
        header('Location: /patient_prontuario_edit.php?id=' . $id);
        exit;
    }
    $sessionsCount = (int)$sessionsRaw;
}

if ($attachmentsJson !== '') {
    $decoded = json_decode($attachmentsJson, true);
    if ($decoded === null && strtolower($attachmentsJson) !== 'null') {
        flash_set('error', 'Anexos (JSON) inválido.');
        header('Location: /patient_prontuario_edit.php?id=' . $id);
        exit;
    }
}

$stmt = db()->prepare(
    'UPDATE patient_prontuario_entries'
    . ' SET professional_user_id = :puid, origin = :origin, occurred_at = :occurred_at, sessions_count = :sessions_count, notes = :notes, attachments_json = :attachments_json'
    . ' WHERE id = :id'
);
$stmt->execute([
    'id' => $id,
    'puid' => $professionalUserId,
    'origin' => $origin,
    'occurred_at' => $occurredAtDb,
    'sessions_count' => $sessionsCount,
    'notes' => $notes !== '' ? $notes : null,
    'attachments_json' => $attachmentsJson !== '' ? $attachmentsJson : null,
]);

audit_log('update', 'patient_prontuario_entries', (string)$id, $old, ['origin' => $origin, 'occurred_at' => $occurredAtDb]);

flash_set('success', 'Registro atualizado.');
header('Location: /patients_view.php?id=' . $patientId);
exit;
