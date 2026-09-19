<?php

declare(strict_types=1);

/**
 * Retoma um atendimento (patient_assignment) que foi suspenso/finalizado.
 *
 * Regra (reunião 15/09):
 *   - Internação/hospitalização até 3 dias  -> volta ao MONITORAMENTO (reativa o mesmo atendimento);
 *   - Internação/hospitalização > 3 dias    -> volta à CAPTAÇÃO (novo card, reinicia o fluxo);
 *   - Qualquer outro motivo                  -> volta ao MONITORAMENTO.
 *
 * A data-base para os dias é patient_assignments.ended_at (data da suspensão).
 *
 * POST:
 *   - assignment_id (obrigatório)
 *
 * Retorno: JSON { success, destination, days, message }.
 */

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Atendimento inválido.']);
    exit;
}

$db = db();

// Garantir colunas de rastreabilidade do retorno (idempotente).
foreach ([
    "ALTER TABLE patient_assignments ADD COLUMN resumed_at DATETIME NULL",
    "ALTER TABLE patient_assignments ADD COLUMN resumed_by_user_id INT UNSIGNED NULL",
    "ALTER TABLE patient_assignments ADD COLUMN resumed_to_demand_id INT UNSIGNED NULL",
] as $alter) {
    try { $db->exec($alter); } catch (Throwable $e) { /* já existe */ }
}

// Carregar o atendimento + motivo de suspensão + dados para clonar/notificar.
$stmt = $db->prepare(
    "SELECT pa.id, pa.status, pa.ended_at, pa.end_reason_id, pa.admitted_at,
            pa.demand_id, pa.patient_id, pa.professional_user_id,
            pa.specialty AS pa_specialty, pa.service_type,
            ter.slug AS reason_slug, ter.name AS reason_name,
            p.full_name AS patient_name, p.whatsapp AS patient_phone, p.phone_primary,
            u.name AS professional_name, u.phone AS professional_phone,
            d.title AS demand_title, d.specialty AS demand_specialty,
            d.location_city, d.location_state, d.location_street,
            d.location_neighborhood, d.location_number, d.location_complement,
            d.frequency AS demand_frequency
     FROM patient_assignments pa
     LEFT JOIN treatment_end_reasons ter ON ter.id = pa.end_reason_id
     INNER JOIN patients p ON p.id = pa.patient_id
     LEFT JOIN users u ON u.id = pa.professional_user_id
     LEFT JOIN demands d ON d.id = pa.demand_id
     WHERE pa.id = :id
     LIMIT 1"
);
$stmt->execute(['id' => $assignmentId]);
$pa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pa) {
    echo json_encode(['success' => false, 'error' => 'Atendimento não encontrado.']);
    exit;
}

// Só faz sentido retomar um atendimento que esteja suspenso/finalizado.
if ((string)$pa['status'] !== 'completed') {
    echo json_encode(['success' => false, 'error' => 'Este atendimento não está suspenso (status atual: ' . (string)$pa['status'] . ').']);
    exit;
}

// Decidir o destino com base no motivo e nos dias suspensos.
$decision = resume_decide_destination(
    $pa['reason_slug'] ?? null,
    $pa['ended_at'] ?? null
);
$destination = $decision['destination'];
$days = $decision['days'];

$resumeDateBr = date('d/m/Y');

try {
    if ($destination === RESUME_DEST_CAPTATION) {
        // -------- Ramo > 3 dias (hospitalização): NOVO card em Captação --------
        $db->beginTransaction();

        // Título do novo card: reaproveita o da demanda original, sinalizando o retorno.
        $baseTitle = trim((string)($pa['demand_title'] ?? ''));
        if ($baseTitle === '') {
            $baseTitle = 'Retorno de atendimento — ' . (string)($pa['patient_name'] ?? 'Paciente');
        }
        $newTitle = '[Retorno pós-hospitalização] ' . $baseTitle;

        // Especialidade: prioriza a da demanda, cai para a do atendimento.
        $specialty = trim((string)($pa['demand_specialty'] ?? ''));
        if ($specialty === '') {
            $specialty = trim((string)($pa['pa_specialty'] ?? ''));
        }

        $descr = 'Novo card gerado automaticamente pelo retorno do paciente após hospitalização '
            . 'de ' . $days . ' dia(s). Atendimento de origem: #' . $assignmentId
            . (($pa['demand_id'] ?? null) ? ' (card original #' . (int)$pa['demand_id'] . ')' : '') . '.';

        $ins = $db->prepare(
            'INSERT INTO demands (title, location_city, location_state, location_street, location_neighborhood, location_number, location_complement, specialty, frequency, description, status)'
            . ' VALUES (:t,:c,:s,:street,:neighborhood,:number,:complement,:sp,:freq,:d,:st)'
        );
        $ins->execute([
            't' => $newTitle,
            'c' => ($pa['location_city'] ?? '') !== '' ? $pa['location_city'] : null,
            's' => ($pa['location_state'] ?? '') !== '' ? $pa['location_state'] : null,
            'street' => ($pa['location_street'] ?? '') !== '' ? $pa['location_street'] : null,
            'neighborhood' => ($pa['location_neighborhood'] ?? '') !== '' ? $pa['location_neighborhood'] : null,
            'number' => ($pa['location_number'] ?? '') !== '' ? $pa['location_number'] : null,
            'complement' => ($pa['location_complement'] ?? '') !== '' ? $pa['location_complement'] : null,
            'sp' => $specialty !== '' ? $specialty : null,
            'freq' => ($pa['demand_frequency'] ?? '') !== '' ? $pa['demand_frequency'] : null,
            'd' => $descr,
            'st' => 'aguardando_captacao',
        ]);
        $newDemandId = (int)$db->lastInsertId();

        // Log da criação do card (mesmo padrão de demands_create_post.php).
        try {
            $db->prepare(
                'INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, NULL, :ns, :uid, :note)'
            )->execute([
                'did' => $newDemandId,
                'ns' => 'aguardando_captacao',
                'uid' => auth_user_id(),
                'note' => 'Retorno pós-hospitalização (> ' . RESUME_HOSPITALIZATION_MAX_DAYS . ' dias): novo card de captação a partir do atendimento #' . $assignmentId,
            ]);
        } catch (Throwable $e) { /* log é best-effort */ }

        // Marcar no atendimento de origem para onde ele foi retomado (histórico).
        $db->prepare(
            'UPDATE patient_assignments SET resumed_at = NOW(), resumed_by_user_id = :uid, resumed_to_demand_id = :did WHERE id = :id'
        )->execute([
            'uid' => auth_user_id(),
            'did' => $newDemandId,
            'id' => $assignmentId,
        ]);

        audit_log('resume', 'patient_assignments', (string)$assignmentId, null, [
            'destination' => 'captacao',
            'days' => $days,
            'new_demand_id' => $newDemandId,
        ]);

        $db->commit();

        echo json_encode([
            'success' => true,
            'destination' => 'captacao',
            'days' => $days,
            'new_demand_id' => $newDemandId,
            'message' => 'Paciente ficou ' . $days . ' dias hospitalizado (acima de ' . RESUME_HOSPITALIZATION_MAX_DAYS . '). Novo card criado na Captação.',
        ]);
        exit;
    }

    // -------- Ramo <= 3 dias OU outro motivo: volta ao MONITORAMENTO --------
    // Reativa o MESMO atendimento: limpa a finalização e volta para 'admitted'
    // (a query do monitoramento.php lista status IN admitted/awaiting_* AND admitted_at IS NOT NULL).
    $db->beginTransaction();

    $db->prepare(
        "UPDATE patient_assignments
         SET status = 'admitted',
             ended_at = NULL,
             end_reason_id = NULL,
             end_notes = NULL,
             ended_by_user_id = NULL,
             completed_at = NULL,
             admitted_at = COALESCE(admitted_at, NOW()),
             resumed_at = NOW(),
             resumed_by_user_id = :uid
         WHERE id = :id"
    )->execute([
        'uid' => auth_user_id(),
        'id' => $assignmentId,
    ]);

    // Recolocar a demanda vinculada como 'admitido' (sai de 'concluido' no kanban).
    if (!empty($pa['demand_id'])) {
        try {
            $db->prepare("UPDATE demands SET status = 'admitido', updated_at = NOW() WHERE id = :id")
                ->execute(['id' => (int)$pa['demand_id']]);
            $db->prepare(
                'INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, NULL, :ns, :uid, :note)'
            )->execute([
                'did' => (int)$pa['demand_id'],
                'ns' => 'admitido',
                'uid' => auth_user_id(),
                'note' => 'Retorno ao monitoramento do atendimento #' . $assignmentId . ' (' . $days . ' dia(s) suspenso).',
            ]);
        } catch (Throwable $e) { /* best-effort */ }
    }

    audit_log('resume', 'patient_assignments', (string)$assignmentId, null, [
        'destination' => 'monitoramento',
        'days' => $days,
        'is_hospitalization' => $decision['is_hospitalization'],
    ]);

    $db->commit();

    // Notificar o profissional que o monitoramento foi retomado (template oficial).
    try {
        $dispatcher = new WhatsAppEventDispatcher();
        $dispatcher->dispatch('attendance_resumed', [
            'patient_id' => (int)($pa['patient_id'] ?? 0),
            'patient_name' => (string)($pa['patient_name'] ?? ''),
            'patient_phone' => '', // mensagem é para o PROFISSIONAL
            'professional_id' => (int)($pa['professional_user_id'] ?? 0),
            'professional_name' => (string)($pa['professional_name'] ?? ''),
            'professional_phone' => (string)($pa['professional_phone'] ?? ''),
            'attendance_id' => (string)$assignmentId,
            'resume_date' => $resumeDateBr,
        ]);
    } catch (Throwable $notifyErr) {
        error_log('[ASSIGNMENT_RESUME] Erro ao notificar retomada (assignment ' . $assignmentId . '): ' . $notifyErr->getMessage());
    }

    echo json_encode([
        'success' => true,
        'destination' => 'monitoramento',
        'days' => $days,
        'message' => 'Atendimento retomado no Monitoramento.',
    ]);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[ASSIGNMENT_RESUME] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao retomar atendimento: ' . $e->getMessage()]);
    exit;
}
