<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$chatId = (int)($_POST['chat_id'] ?? 0);
$patientId = (int)($_POST['patient_id'] ?? 0);
$professionalUserId = (int)($_POST['professional_user_id'] ?? 0);
$specialty = trim((string)($_POST['specialty'] ?? ''));
$firstAt = trim((string)($_POST['first_at'] ?? ''));
$recurrenceType = (string)($_POST['recurrence_type'] ?? 'single');
$recurrenceRule = trim((string)($_POST['recurrence_rule'] ?? ''));
$valuePerSession = (string)($_POST['value_per_session'] ?? '0');
$note = trim((string)($_POST['note'] ?? ''));
$demandIdRaw = trim((string)($_POST['demand_id'] ?? ''));
$demandId = $demandIdRaw !== '' ? (int)$demandIdRaw : 0;

if ($chatId <= 0) {
    flash_set('error', 'Conversa inválida.');
    header('Location: /chat_web.php');
    exit;
}

if ($patientId <= 0 || $professionalUserId <= 0 || $specialty === '' || $firstAt === '') {
    flash_set('error', 'Preencha paciente, profissional e data/hora.');
    header('Location: /chat_confirm_admission.php?chat_id=' . $chatId);
    exit;
}

$allowedRec = ['single','weekly','monthly','custom'];
if (!in_array($recurrenceType, $allowedRec, true)) {
    $recurrenceType = 'single';
}

$dt = DateTime::createFromFormat('Y-m-d\TH:i', $firstAt);
if (!$dt) {
    flash_set('error', 'Data/hora inválida.');
    header('Location: /chat_confirm_admission.php?chat_id=' . $chatId);
    exit;
}

$firstAtDb = $dt->format('Y-m-d H:i:00');

$demandIdDb = null;
if ($demandId > 0) {
    $stmt = db()->prepare('SELECT id FROM demands WHERE id = :id');
    $stmt->execute(['id' => $demandId]);
    if ($stmt->fetch()) {
        $demandIdDb = $demandId;
    }
}

$stmt = db()->prepare('SELECT id, external_phone FROM chat_conversations WHERE id = :id');
$stmt->execute(['id' => $chatId]);
$chat = $stmt->fetch();
if (!$chat) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_web.php');
    exit;
}

$stmt = db()->prepare('SELECT id, full_name, email FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();
if (!$patient) {
    flash_set('error', 'Paciente inválido.');
    header('Location: /chat_confirm_admission.php?chat_id=' . $chatId);
    exit;
}

$stmt = db()->prepare(
    "SELECT u.id, u.name FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.id = :id AND u.status='active' AND r.slug='profissional' LIMIT 1"
);
$stmt->execute(['id' => $professionalUserId]);
$prof = $stmt->fetch();
if (!$prof) {
    flash_set('error', 'Profissional inválido.');
    header('Location: /chat_confirm_admission.php?chat_id=' . $chatId);
    exit;
}

// Bloqueio por documento vencido (MD 5.4 / 10.3)
$requiredCsv = trim((string)admin_setting_get('professional.required_doc_categories', ''));
$required = [];
if ($requiredCsv !== '') {
    foreach (preg_split('/\s*,\s*/', $requiredCsv) as $c) {
        $c = trim((string)$c);
        if ($c !== '') {
            $required[] = $c;
        }
    }
}

$sql = "SELECT 1
        FROM document_versions v
        INNER JOIN documents d ON d.id = v.document_id
        WHERE d.entity_type='professional'
          AND d.entity_id = :uid
          AND d.status='active'
          AND v.valid_until IS NOT NULL
          AND v.valid_until < CURDATE()";
$params = ['uid' => $professionalUserId];
if (count($required) > 0) {
    $in = [];
    foreach ($required as $i => $cat) {
        $k = 'c' . $i;
        $in[] = ':' . $k;
        $params[$k] = $cat;
    }
    $sql .= ' AND d.category IN (' . implode(',', $in) . ')';
}
$sql .= ' LIMIT 1';

$stmt = db()->prepare($sql);
$stmt->execute($params);
if ($stmt->fetch()) {
    flash_set('error', 'Profissional com documento vencido. Regularize para permitir novos agendamentos.');
    header('Location: /chat_confirm_admission.php?chat_id=' . $chatId);
    exit;
}

if (!is_numeric($valuePerSession)) {
    $valuePerSession = '0';
}

$valueFloat = (float)$valuePerSession;

// Valor mínimo por especialidade (MD 6.3 / 9.3)
$stmt = db()->prepare("SELECT minimum_value FROM specialty_minimums WHERE specialty = :sp AND status = 'active' LIMIT 1");
$stmt->execute(['sp' => $specialty]);
$minRow = $stmt->fetch();

if ($minRow) {
    $minValue = (float)$minRow['minimum_value'];
    if ($valueFloat < $minValue) {
        $db = db();
        $db->beginTransaction();
        try {
            $stmt2 = $db->prepare(
                'INSERT INTO appointment_value_authorizations (status, demand_id, patient_id, professional_user_id, specialty, first_at, recurrence_type, recurrence_rule, requested_value, minimum_value, requested_by_user_id)'
                . ' VALUES (\'pending\', :demand_id, :patient_id, :professional_user_id, :specialty, :first_at, :recurrence_type, :recurrence_rule, :requested_value, :minimum_value, :requested_by_user_id)'
            );
            $stmt2->execute([
                'demand_id' => $demandIdDb,
                'patient_id' => $patientId,
                'professional_user_id' => $professionalUserId,
                'specialty' => $specialty,
                'first_at' => $firstAtDb,
                'recurrence_type' => $recurrenceType,
                'recurrence_rule' => $recurrenceRule !== '' ? $recurrenceRule : null,
                'requested_value' => $valuePerSession,
                'minimum_value' => (string)$minValue,
                'requested_by_user_id' => auth_user_id(),
            ]);

            $reqId = (int)$db->lastInsertId();

            $stmt2 = $db->prepare(
                "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)"
                . " VALUES ('value_authorization','open',:title,:detail,'appointment_value_authorizations',:rid,NULL)"
            );
            $stmt2->execute([
                'title' => 'Autorizar valor abaixo do mínimo (Solicitação #' . $reqId . ')',
                'detail' => 'Especialidade: ' . $specialty . ' | Solicitado: ' . $valuePerSession . ' | Mínimo: ' . (string)$minValue,
                'rid' => $reqId,
            ]);

            audit_log('create', 'appointment_value_authorizations', (string)$reqId, null, ['specialty' => $specialty, 'requested_value' => $valuePerSession, 'minimum_value' => $minValue]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        flash_set('error', 'Valor abaixo do mínimo. Solicitação de autorização criada para o Admin.');
        header('Location: /appointment_value_authorizations_view.php?id=' . $reqId);
        exit;
    }
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO appointments (demand_id, patient_id, professional_user_id, specialty, first_at, recurrence_type, recurrence_rule, value_per_session, status, created_by_user_id)'
        . ' VALUES (:demand_id, :patient_id, :professional_user_id, :specialty, :first_at, :recurrence_type, :recurrence_rule, :value_per_session, :status, :created_by_user_id)'
    );
    $stmt->execute([
        'demand_id' => $demandIdDb,
        'patient_id' => $patientId,
        'professional_user_id' => $professionalUserId,
        'specialty' => $specialty,
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
        'note' => $note !== '' ? $note : 'criação (via chat)',
    ]);

    $patientRef = (string)$patient['full_name'] . ' (#' . (int)$patient['id'] . ')';
    $stmt = $db->prepare(
        "INSERT INTO professional_documentations (professional_user_id, appointment_id, patient_ref, sessions_count, status, due_at)"
        . " VALUES (:uid, :appointment_id, :patient_ref, :sessions_count, 'draft', DATE_ADD(NOW(), INTERVAL 48 HOUR))"
    );
    $stmt->execute([
        'uid' => $professionalUserId,
        'appointment_id' => $appointmentId,
        'patient_ref' => $patientRef,
        'sessions_count' => 1,
    ]);

    $stmt = $db->prepare(
        "INSERT INTO finance_accounts_receivable (appointment_id, patient_id, professional_user_id, amount, due_at, status)"
        . " VALUES (:aid, :pid, :puid, :amount, DATE_ADD(:first_at, INTERVAL 30 DAY), 'pendente')"
    );
    $stmt->execute([
        'aid' => $appointmentId,
        'pid' => $patientId,
        'puid' => $professionalUserId,
        'amount' => $valuePerSession,
        'first_at' => $firstAtDb,
    ]);

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
            'note' => 'admissão confirmada via chat (agendamento criado)',
        ]);
    }

    // Jobs de notificação (serão executados via CRON runner)
    $payload = [
        'appointment_id' => $appointmentId,
        'patient_id' => $patientId,
        'professional_user_id' => $professionalUserId,
        'first_at' => $firstAtDb,
        'patient_email' => (string)($patient['email'] ?? ''),
        'patient_phone' => (string)($chat['external_phone'] ?? ''),
    ];

    integration_job_enqueue('evolution', 'patient_notify_appointment', $payload, null);
    integration_job_enqueue('smtp', 'send_email_confirmation', $payload, null);

    // Pendência operacional visível
    $stmt = $db->prepare(
        "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)"
        . " VALUES ('appointment_confirmed','open',:title,:detail,'appointments',:rid,:uid)"
    );
    $stmt->execute([
        'title' => 'Notificar paciente do agendamento #' . $appointmentId,
        'detail' => 'Agendamento criado via chat. Notificações (WhatsApp/e-mail) foram enfileiradas.',
        'rid' => $appointmentId,
        'uid' => auth_user_id(),
    ]);

    audit_log('create', 'appointments', (string)$appointmentId, null, ['patient_id' => $patientId, 'professional_user_id' => $professionalUserId, 'chat_id' => $chatId]);

    $db->commit();

    flash_set('success', 'Admissão confirmada: agendamento criado e notificações enfileiradas.');
    header('Location: /appointments_view.php?id=' . $appointmentId);
    exit;
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
