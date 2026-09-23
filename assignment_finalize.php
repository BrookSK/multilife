<?php
/**
 * Finaliza um atendimento (patient_assignment) com motivo, data e hora.
 * Itens 5 e 11 do escopo.
 *
 * POST:
 *   - assignment_id (obrigatório)
 *   - end_reason_id (obrigatório)
 *   - ended_date (YYYY-MM-DD)
 *   - ended_time (HH:MM)
 *   - end_notes (opcional)
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$endReasonId = (int)($_POST['end_reason_id'] ?? 0);
$endedDate = trim((string)($_POST['ended_date'] ?? ''));
$endedTime = trim((string)($_POST['ended_time'] ?? ''));
$endNotes = trim((string)($_POST['end_notes'] ?? ''));

if ($assignmentId <= 0 || $endReasonId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Dados obrigatórios ausentes.']);
    exit;
}

// Montar datetime de encerramento
$endedAt = null;
if ($endedDate !== '') {
    $time = $endedTime !== '' ? $endedTime . ':00' : '00:00:00';
    $endedAt = $endedDate . ' ' . $time;
} else {
    $endedAt = date('Y-m-d H:i:s');
}

try {
    $db = db();

    // Garantir colunas (fallback caso migration não tenha rodado)
    foreach ([
        "ALTER TABLE patient_assignments ADD COLUMN ended_at DATETIME NULL",
        "ALTER TABLE patient_assignments ADD COLUMN end_reason_id INT UNSIGNED NULL",
        "ALTER TABLE patient_assignments ADD COLUMN end_notes TEXT NULL",
        "ALTER TABLE patient_assignments ADD COLUMN ended_by_user_id INT UNSIGNED NULL",
        "ALTER TABLE patient_assignments ADD COLUMN resumed_at DATETIME NULL",
        "ALTER TABLE patient_assignments ADD COLUMN resumed_by_user_id INT UNSIGNED NULL",
        "ALTER TABLE patient_assignments ADD COLUMN resumed_to_demand_id INT UNSIGNED NULL",
    ] as $alter) {
        try { $db->exec($alter); } catch (Throwable $e) { /* já existe */ }
    }

    $stmt = $db->prepare("
        UPDATE patient_assignments
        SET status = 'completed',
            ended_at = :ended_at,
            end_reason_id = :reason_id,
            end_notes = :notes,
            ended_by_user_id = :uid,
            completed_at = COALESCE(completed_at, :ended_at2),
            resumed_at = NULL,
            resumed_by_user_id = NULL,
            resumed_to_demand_id = NULL
        WHERE id = :id
    ");
    $stmt->execute([
        'ended_at' => $endedAt,
        'reason_id' => $endReasonId,
        'notes' => $endNotes !== '' ? $endNotes : null,
        'uid' => auth_user_id(),
        'ended_at2' => $endedAt,
        'id' => $assignmentId,
    ]);

    audit_log('update', 'patient_assignments', (string)$assignmentId, null, [
        'action' => 'finalizado',
        'end_reason_id' => $endReasonId,
        'ended_at' => $endedAt,
    ]);

    // Notificar o profissional conforme o MOTIVO de encerramento (templates oficiais).
    // Mapeia o slug do motivo para o evento WhatsApp correspondente.
    try {
        error_log('[ASSIGNMENT_FINALIZE] Bloco de notificação iniciado. assignment=' . $assignmentId . ' end_reason_id=' . $endReasonId);
        $reasonSlug = '';
        try {
            $rStmt = $db->prepare('SELECT slug FROM treatment_end_reasons WHERE id = :id LIMIT 1');
            $rStmt->execute(['id' => $endReasonId]);
            $reasonSlug = (string)($rStmt->fetchColumn() ?: '');
        } catch (Throwable $e) { $reasonSlug = ''; }

        $eventBySlug = [
            'hospitalizacao' => 'attendance_hospitalization',
            'termino_periodo_autorizado' => 'attendance_authorized_period_ended',
        ];

        error_log('[ASSIGNMENT_FINALIZE] slug=' . $reasonSlug . ' evento=' . ($eventBySlug[$reasonSlug] ?? 'NENHUM'));

        if (isset($eventBySlug[$reasonSlug])) {
            // Buscar dados do atendimento para preencher o template.
            $infoStmt = $db->prepare(
                "SELECT p.full_name AS patient_name, p.whatsapp AS patient_phone, p.phone_primary,
                        u.id AS professional_user_id, u.name AS professional_name, u.phone AS professional_phone
                 FROM patient_assignments pa
                 INNER JOIN patients p ON p.id = pa.patient_id
                 LEFT JOIN users u ON u.id = pa.professional_user_id
                 WHERE pa.id = :id LIMIT 1"
            );
            $infoStmt->execute(['id' => $assignmentId]);
            $info = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $endedDateBr = date('d/m/Y', strtotime($endedAt));

            error_log('[ASSIGNMENT_FINALIZE] Enviando para profissional phone=' . (string)($info['professional_phone'] ?? '') . ' id=' . (int)($info['professional_user_id'] ?? 0));

            $dispatcher = new WhatsAppEventDispatcher();
            $dispatchResult = $dispatcher->dispatch($eventBySlug[$reasonSlug], [
                'patient_id' => 0,
                'patient_name' => (string)($info['patient_name'] ?? ''),
                'patient_phone' => '', // mensagem é para o PROFISSIONAL
                'professional_id' => (int)($info['professional_user_id'] ?? 0),
                'professional_name' => (string)($info['professional_name'] ?? ''),
                'professional_phone' => (string)($info['professional_phone'] ?? ''),
                'attendance_id' => (string)$assignmentId,
                'hospitalization_date' => $endedDateBr,
            ]);
            error_log('[ASSIGNMENT_FINALIZE] Resultado dispatch: ' . json_encode($dispatchResult, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $notifyErr) {
        error_log('[ASSIGNMENT_FINALIZE] Erro ao notificar profissional: ' . $notifyErr->getMessage());
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('[ASSIGNMENT_FINALIZE] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
