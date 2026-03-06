<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$patientId = (int)($_POST['patient_id'] ?? 0);
$professionalUserId = (int)($_POST['professional_user_id'] ?? 0);
$specialty = trim((string)($_POST['specialty'] ?? ''));
$firstAt = trim((string)($_POST['first_at'] ?? ''));
$recurrenceType = (string)($_POST['recurrence_type'] ?? 'single');
$recurrenceRule = trim((string)($_POST['recurrence_rule'] ?? ''));
$valuePerSession = (string)($_POST['value_per_session'] ?? '0');
$demandId = (int)($_POST['demand_id'] ?? 0);

if ($patientId <= 0 || $professionalUserId <= 0 || $specialty === '' || $firstAt === '') {
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
    header('Location: /appointments_create.php');
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
        // cria solicitação de autorização e não cria agendamento ainda
        $db = db();
        $db->beginTransaction();
        try {
            $demandIdDbTmp = null;
            if ($demandId > 0) {
                $stmt2 = $db->prepare('SELECT id FROM demands WHERE id = :id');
                $stmt2->execute(['id' => $demandId]);
                if ($stmt2->fetch()) {
                    $demandIdDbTmp = $demandId;
                }
            }

            $stmt2 = $db->prepare(
                'INSERT INTO appointment_value_authorizations (status, demand_id, patient_id, professional_user_id, specialty, first_at, recurrence_type, recurrence_rule, requested_value, minimum_value, requested_by_user_id)'
                . ' VALUES (\'pending\', :demand_id, :patient_id, :professional_user_id, :specialty, :first_at, :recurrence_type, :recurrence_rule, :requested_value, :minimum_value, :requested_by_user_id)'
            );
            $stmt2->execute([
                'demand_id' => $demandIdDbTmp,
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
        'INSERT INTO appointments (demand_id, patient_id, professional_user_id, specialty, first_at, recurrence_type, recurrence_rule, value_per_session, status, created_by_user_id)
         VALUES (:demand_id, :patient_id, :professional_user_id, :specialty, :first_at, :recurrence_type, :recurrence_rule, :value_per_session, :status, :created_by_user_id)'
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

page_history_log(
    '/appointments_list.php',
    'Agendamentos',
    'create',
    'Criou novo agendamento',
    'appointment',
    (int)$appointmentId
);

flash_set('success', 'Agendamento criado e pendência gerada para o profissional.');
header('Location: /appointments_view.php?id=' . $appointmentId);
exit;
