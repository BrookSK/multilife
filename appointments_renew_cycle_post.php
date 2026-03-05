<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$firstAt = trim((string)($_POST['first_at'] ?? ''));
$recurrenceType = (string)($_POST['recurrence_type'] ?? 'single');
$recurrenceRule = trim((string)($_POST['recurrence_rule'] ?? ''));
$valuePerSession = (string)($_POST['value_per_session'] ?? '0');

if ($appointmentId <= 0 || $firstAt === '') {
    flash_set('error', 'Dados inválidos.');
    header('Location: /appointments_list.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT a.*, p.full_name AS patient_name
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     WHERE a.id = :id AND p.deleted_at IS NULL'
);
$stmt->execute(['id' => $appointmentId]);
$oldAppt = $stmt->fetch();

if (!$oldAppt) {
    flash_set('error', 'Agendamento não encontrado.');
    header('Location: /appointments_list.php');
    exit;
}

$allowedRec = ['single','weekly','monthly','custom'];
if (!in_array($recurrenceType, $allowedRec, true)) {
    $recurrenceType = 'single';
}

$dt = DateTime::createFromFormat('Y-m-d\TH:i', $firstAt);
if (!$dt) {
    flash_set('error', 'Data/hora inválida.');
    header('Location: /appointments_renew_cycle.php?appointment_id=' . $appointmentId);
    exit;
}

$firstAtDb = $dt->format('Y-m-d H:i:00');

if (!is_numeric($valuePerSession)) {
    $valuePerSession = '0';
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO appointments (demand_id, patient_id, professional_user_id, specialty, first_at, recurrence_type, recurrence_rule, value_per_session, status, created_by_user_id)
         VALUES (:demand_id, :patient_id, :professional_user_id, :specialty, :first_at, :recurrence_type, :recurrence_rule, :value_per_session, :status, :created_by_user_id)'
    );
    $stmt->execute([
        'demand_id' => $oldAppt['demand_id'],
        'patient_id' => (int)$oldAppt['patient_id'],
        'professional_user_id' => (int)$oldAppt['professional_user_id'],
        'specialty' => (string)$oldAppt['specialty'],
        'first_at' => $firstAtDb,
        'recurrence_type' => $recurrenceType,
        'recurrence_rule' => $recurrenceRule !== '' ? $recurrenceRule : null,
        'value_per_session' => $valuePerSession,
        'status' => 'pendente_formulario',
        'created_by_user_id' => auth_user_id(),
    ]);

    $newAppointmentId = (int)$db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO appointment_status_logs (appointment_id, old_status, new_status, user_id, note) VALUES (:aid, NULL, :ns, :uid, :note)');
    $stmt->execute([
        'aid' => $newAppointmentId,
        'ns' => 'pendente_formulario',
        'uid' => auth_user_id(),
        'note' => 'renovação de ciclo (origem: #' . $appointmentId . ')',
    ]);

    $patientRef = (string)$oldAppt['patient_name'] . ' (#' . (int)$oldAppt['patient_id'] . ')';
    $stmt = $db->prepare(
        "INSERT INTO professional_documentations (professional_user_id, appointment_id, patient_ref, sessions_count, status, due_at)
         VALUES (:uid, :appointment_id, :patient_ref, :sessions_count, 'draft', DATE_ADD(NOW(), INTERVAL 48 HOUR))"
    );
    $stmt->execute([
        'uid' => (int)$oldAppt['professional_user_id'],
        'appointment_id' => $newAppointmentId,
        'patient_ref' => $patientRef,
        'sessions_count' => 1,
    ]);

    $stmt = $db->prepare(
        "INSERT INTO finance_accounts_receivable (appointment_id, patient_id, professional_user_id, amount, due_at, status)
         VALUES (:aid, :pid, :puid, :amount, DATE_ADD(:first_at, INTERVAL 30 DAY), 'pendente')"
    );
    $stmt->execute([
        'aid' => $newAppointmentId,
        'pid' => (int)$oldAppt['patient_id'],
        'puid' => (int)$oldAppt['professional_user_id'],
        'amount' => $valuePerSession,
        'first_at' => $firstAtDb,
    ]);

    $stmt = $db->prepare(
        "UPDATE pending_items SET status = 'completed', completed_at = NOW()
         WHERE type = 'appointment_cycle_renewal'
           AND related_table = 'appointments'
           AND related_id = :old_id
           AND status = 'open'"
    );
    $stmt->execute(['old_id' => $appointmentId]);

    audit_log('create', 'appointments', (string)$newAppointmentId, null, ['renewed_from' => $appointmentId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Ciclo renovado com sucesso. Novo agendamento #' . $newAppointmentId . ' criado.');
header('Location: /appointments_view.php?id=' . $newAppointmentId);
exit;
