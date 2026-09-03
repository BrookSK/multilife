<?php
/**
 * Registra um evento clínico datado do paciente (item 7).
 * Se o evento for óbito, encerra o paciente e finaliza os atendimentos ativos.
 * O histórico é preservado (nada é apagado).
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$patientId = (int)($_POST['patient_id'] ?? 0);
$eventType = trim((string)($_POST['event_type'] ?? ''));
$eventDate = trim((string)($_POST['event_date'] ?? ''));
$eventTime = trim((string)($_POST['event_time'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

$allowedTypes = ['internacao', 'obito', 'alta', 'retorno', 'transferencia', 'outro'];

if ($patientId <= 0 || !in_array($eventType, $allowedTypes, true) || $eventDate === '') {
    flash_set('error', 'Dados do evento inválidos.');
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

$db = db();

try {
    // Garantir tabela e colunas
    $db->exec("CREATE TABLE IF NOT EXISTS patient_clinical_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        patient_id BIGINT UNSIGNED NOT NULL,
        event_type ENUM('internacao','obito','alta','retorno','transferencia','outro') NOT NULL,
        event_date DATE NOT NULL,
        event_time TIME NULL,
        notes TEXT NULL,
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pce_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
        "ALTER TABLE patients ADD COLUMN is_closed TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE patients ADD COLUMN closed_at DATETIME NULL",
        "ALTER TABLE patients ADD COLUMN closed_reason VARCHAR(60) NULL",
    ] as $alter) {
        try { $db->exec($alter); } catch (Throwable $e) {}
    }

    $db->beginTransaction();

    // Registrar o evento (histórico preservado)
    $stmt = $db->prepare("
        INSERT INTO patient_clinical_events (patient_id, event_type, event_date, event_time, notes, created_by_user_id)
        VALUES (:pid, :type, :date, :time, :notes, :uid)
    ");
    $stmt->execute([
        'pid' => $patientId,
        'type' => $eventType,
        'date' => $eventDate,
        'time' => $eventTime !== '' ? $eventTime : null,
        'notes' => $notes !== '' ? $notes : null,
        'uid' => auth_user_id(),
    ]);

    // Se óbito: encerrar paciente e finalizar atendimentos ativos
    if ($eventType === 'obito') {
        $endedAt = $eventDate . ' ' . ($eventTime !== '' ? $eventTime . ':00' : '00:00:00');

        // Marcar paciente como encerrado
        $db->prepare("
            UPDATE patients
            SET is_closed = 1, closed_at = :closed_at, closed_reason = 'obito', admin_status = 'Óbito'
            WHERE id = :pid
        ")->execute(['closed_at' => $endedAt, 'pid' => $patientId]);

        // Buscar motivo 'obito' em treatment_end_reasons
        $reasonId = null;
        try {
            $rid = $db->query("SELECT id FROM treatment_end_reasons WHERE slug = 'obito' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $reasonId = $rid ? (int)$rid['id'] : null;
        } catch (Throwable $e) {}

        // Finalizar atendimentos ativos do paciente (garantir colunas antes)
        foreach ([
            "ALTER TABLE patient_assignments ADD COLUMN ended_at DATETIME NULL",
            "ALTER TABLE patient_assignments ADD COLUMN end_reason_id INT UNSIGNED NULL",
            "ALTER TABLE patient_assignments ADD COLUMN end_notes TEXT NULL",
            "ALTER TABLE patient_assignments ADD COLUMN ended_by_user_id INT UNSIGNED NULL",
        ] as $alter) {
            try { $db->exec($alter); } catch (Throwable $e) {}
        }

        $db->prepare("
            UPDATE patient_assignments
            SET status = 'completed',
                ended_at = COALESCE(ended_at, :ended_at),
                end_reason_id = COALESCE(end_reason_id, :reason_id),
                end_notes = COALESCE(end_notes, 'Encerrado automaticamente por registro de óbito'),
                ended_by_user_id = COALESCE(ended_by_user_id, :uid),
                completed_at = COALESCE(completed_at, :ended_at2)
            WHERE patient_id = :pid
              AND status NOT IN ('completed', 'cancelled')
        ")->execute([
            'ended_at' => $endedAt,
            'reason_id' => $reasonId,
            'uid' => auth_user_id(),
            'ended_at2' => $endedAt,
            'pid' => $patientId,
        ]);
    }

    $db->commit();

    audit_log('create', 'patient_clinical_events', (string)$patientId, null, [
        'event_type' => $eventType,
        'event_date' => $eventDate,
    ]);

    flash_set('success', 'Evento clínico registrado com sucesso.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[CLINICAL_EVENT] ' . $e->getMessage());
    flash_set('error', 'Erro ao registrar evento: ' . $e->getMessage());
}

header('Location: /patients_edit.php?id=' . $patientId);
exit;
