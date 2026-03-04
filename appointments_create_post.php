<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$patientId = (int)($_POST['patient_id'] ?? 0);
$professionalUserId = (int)($_POST['professional_user_id'] ?? 0);
$firstAt = trim((string)($_POST['first_at'] ?? ''));
$recurrenceType = (string)($_POST['recurrence_type'] ?? 'single');
$recurrenceRule = trim((string)($_POST['recurrence_rule'] ?? ''));
$valuePerSession = (string)($_POST['value_per_session'] ?? '0');
$demandId = (int)($_POST['demand_id'] ?? 0);

if ($patientId <= 0 || $professionalUserId <= 0 || $firstAt === '') {
    flash_set('error', 'Preencha paciente, profissional e data/hora.');
    header('Location: /appointments_create.php');
    exit;
}

$allowedRec = ['single','weekly','monthly','custom'];
if (!in_array($recurrenceType, $allowedRec, true)) {
    $recurrenceType = 'single';
}

$dt = DateTime::createFromFormat('Y-m-d\TH:i', $firstAt);
if (!$dt) {
    flash_set('error', 'Data/hora inválida.');
    header('Location: /appointments_create.php');
    exit;
}

$firstAtDb = $dt->format('Y-m-d H:i:00');

$stmt = db()->prepare('SELECT id, full_name FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();
if (!$patient) {
    flash_set('error', 'Paciente inválido.');
    header('Location: /appointments_create.php');
    exit;
}

// Valida profissional (role profissional)
$stmt = db()->prepare(
    "SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.id = :id AND u.status='active' AND r.slug='profissional' LIMIT 1"
);
$stmt->execute(['id' => $professionalUserId]);
$prof = $stmt->fetch();
if (!$prof) {
    flash_set('error', 'Profissional inválido.');
    header('Location: /appointments_create.php');
    exit;
}

if (!is_numeric($valuePerSession)) {
    $valuePerSession = '0';
}

// Demand optional
$demandIdDb = null;
if ($demandId > 0) {
    $stmt = db()->prepare('SELECT id, status FROM demands WHERE id = :id');
    $stmt->execute(['id' => $demandId]);
    $demand = $stmt->fetch();
    if ($demand) {
        $demandIdDb = $demandId;
    }
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO appointments (demand_id, patient_id, professional_user_id, first_at, recurrence_type, recurrence_rule, value_per_session, status, created_by_user_id)
         VALUES (:demand_id, :patient_id, :professional_user_id, :first_at, :recurrence_type, :recurrence_rule, :value_per_session, :status, :created_by_user_id)'
    );
    $stmt->execute([
        'demand_id' => $demandIdDb,
        'patient_id' => $patientId,
        'professional_user_id' => $professionalUserId,
        'first_at' => $firstAtDb,
        'recurrence_type' => $recurrenceType,
        'recurrence_rule' => $recurrenceRule !== '' ? $recurrenceRule : null,
        'value_per_session' => $valuePerSession,
        'status' => 'pendente_formulario',
        'created_by_user_id' => auth_user_id(),
    ]);

    $appointmentId = (int)$db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO appointment_status_logs (appointment_id, old_status, new_status, user_id, note) VALUES (:aid, NULL, :ns, :uid, :note)');
    $stmt->execute([
        'aid' => $appointmentId,
        'ns' => 'pendente_formulario',
        'uid' => auth_user_id(),
        'note' => 'criação',
    ]);

    // Gera pendência do formulário (Módulo 6) vinculada ao agendamento
    $patientRef = (string)$patient['full_name'] . ' (#' . (int)$patient['id'] . ')';
    $stmt = $db->prepare(
        "INSERT INTO professional_documentations (professional_user_id, appointment_id, patient_ref, sessions_count, status, due_at)
         VALUES (:uid, :appointment_id, :patient_ref, :sessions_count, 'draft', DATE_ADD(NOW(), INTERVAL 48 HOUR))"
    );
    $stmt->execute([
        'uid' => $professionalUserId,
        'appointment_id' => $appointmentId,
        'patient_ref' => $patientRef,
        'sessions_count' => 1,
    ]);

    // Financeiro: cria Conta a Receber vinculada ao agendamento
    $stmt = $db->prepare(
        "INSERT INTO finance_accounts_receivable (appointment_id, patient_id, professional_user_id, amount, due_at, status)
         VALUES (:aid, :pid, :puid, :amount, DATE_ADD(:first_at, INTERVAL 30 DAY), 'pendente')"
    );
    $stmt->execute([
        'aid' => $appointmentId,
        'pid' => $patientId,
        'puid' => $professionalUserId,
        'amount' => $valuePerSession,
        'first_at' => $firstAtDb,
    ]);

    // Atualiza card/demanda para admitido (se vinculado)
    if ($demandIdDb !== null) {
        $stmt = $db->prepare('SELECT status FROM demands WHERE id = :id');
        $stmt->execute(['id' => $demandIdDb]);
        $old = $stmt->fetch();
        $oldStatus = $old ? (string)$old['status'] : null;

        $stmt = $db->prepare("UPDATE demands SET status = 'admitido' WHERE id = :id");
        $stmt->execute(['id' => $demandIdDb]);

        $stmt = $db->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, :os, :ns, :uid, :note)');
        $stmt->execute([
            'did' => $demandIdDb,
            'os' => $oldStatus,
            'ns' => 'admitido',
            'uid' => auth_user_id(),
            'note' => 'agendamento criado',
        ]);
    }

    audit_log('create', 'appointments', (string)$appointmentId, null, ['patient_id' => $patientId, 'professional_user_id' => $professionalUserId]);
    audit_log('create', 'finance_accounts_receivable', (string)$appointmentId, null, ['appointment_id' => $appointmentId, 'amount' => $valuePerSession]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Agendamento criado e pendência gerada para o profissional.');
header('Location: /appointments_view.php?id=' . $appointmentId);
exit;
