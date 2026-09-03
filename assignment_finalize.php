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
            completed_at = COALESCE(completed_at, :ended_at2)
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

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('[ASSIGNMENT_FINALIZE] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
