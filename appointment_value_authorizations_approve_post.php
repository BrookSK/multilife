<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = (int)($_POST['id'] ?? 0);

$db = db();

$stmt = $db->prepare('SELECT * FROM appointment_value_authorizations WHERE id = :id');
$stmt->execute(['id' => $id]);
$req = $stmt->fetch();

if (!$req) {
    flash_set('error', 'Solicitação não encontrada.');
    header('Location: /appointment_value_authorizations_list.php');
    exit;
}

if ((string)$req['status'] !== 'pending') {
    flash_set('error', 'Solicitação já resolvida.');
    header('Location: /appointment_value_authorizations_view.php?id=' . $id);
    exit;
}

$db->beginTransaction();
try {
    // cria agendamento com o valor solicitado
    $stmt = $db->prepare(
        'INSERT INTO appointments (demand_id, patient_id, professional_user_id, specialty, first_at, recurrence_type, recurrence_rule, value_per_session, status, created_by_user_id)'
        . ' VALUES (:demand_id, :patient_id, :professional_user_id, :specialty, :first_at, :recurrence_type, :recurrence_rule, :value_per_session, :status, :created_by_user_id)'
    );
    $stmt->execute([
        'demand_id' => $req['demand_id'],
        'patient_id' => (int)$req['patient_id'],
        'professional_user_id' => (int)$req['professional_user_id'],
        'specialty' => (string)$req['specialty'],
        'first_at' => (string)$req['first_at'],
        'recurrence_type' => (string)$req['recurrence_type'],
        'recurrence_rule' => $req['recurrence_rule'] !== null ? (string)$req['recurrence_rule'] : null,
        'value_per_session' => (string)$req['requested_value'],
        'status' => 'pendente_formulario',
        'created_by_user_id' => auth_user_id(),
    ]);

    $appointmentId = (int)$db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO appointment_status_logs (appointment_id, old_status, new_status, user_id, note) VALUES (:aid, NULL, :ns, :uid, :note)');
    $stmt->execute([
        'aid' => $appointmentId,
        'ns' => 'pendente_formulario',
        'uid' => auth_user_id(),
        'note' => 'criação (autorização valor mínimo)',
    ]);

    $stmt = $db->prepare(
        "INSERT INTO professional_documentations (professional_user_id, appointment_id, patient_ref, sessions_count, status, due_at)"
        . " VALUES (:uid, :appointment_id, :patient_ref, :sessions_count, 'draft', DATE_ADD(NOW(), INTERVAL 48 HOUR))"
    );
    $stmt->execute([
        'uid' => (int)$req['professional_user_id'],
        'appointment_id' => $appointmentId,
        'patient_ref' => 'Paciente #' . (int)$req['patient_id'],
        'sessions_count' => 1,
    ]);

    $stmt = $db->prepare(
        "INSERT INTO finance_accounts_receivable (appointment_id, patient_id, professional_user_id, amount, due_at, status)"
        . " VALUES (:aid, :pid, :puid, :amount, DATE_ADD(:first_at, INTERVAL 30 DAY), 'pendente')"
    );
    $stmt->execute([
        'aid' => $appointmentId,
        'pid' => (int)$req['patient_id'],
        'puid' => (int)$req['professional_user_id'],
        'amount' => (string)$req['requested_value'],
        'first_at' => (string)$req['first_at'],
    ]);

    if ($req['demand_id'] !== null) {
        $stmt = $db->prepare("UPDATE demands SET status = 'admitido' WHERE id = :id");
        $stmt->execute(['id' => (int)$req['demand_id']]);
    }

    $stmt = $db->prepare(
        "UPDATE appointment_value_authorizations\n"
        . "SET status = 'approved', reviewed_by_user_id = :uid, reviewed_at = NOW(), created_appointment_id = :aid\n"
        . "WHERE id = :id"
    );
    $stmt->execute(['uid' => auth_user_id(), 'aid' => $appointmentId, 'id' => $id]);

    audit_log('update', 'appointment_value_authorizations', (string)$id, $req, ['status' => 'approved', 'appointment_id' => $appointmentId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Autorizado. Agendamento criado.');
header('Location: /appointments_view.php?id=' . $appointmentId);
exit;
