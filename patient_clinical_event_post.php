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

if ($patientId <= 0 || $eventType === '' || $eventDate === '') {
    flash_set('error', 'Dados do evento inválidos.');
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

$db = db();

// Tipos de evento configuráveis (tela: clinical_event_types.php).
// Validamos o tipo contra os tipos ATIVOS e descobrimos se ele encerra o paciente.
$triggersClosure = false;
$typeIsValid = false;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS clinical_event_types (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(120) NOT NULL,
        triggers_closure TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_clinical_event_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $tStmt = $db->prepare("SELECT triggers_closure FROM clinical_event_types WHERE slug = :slug AND is_active = 1 LIMIT 1");
    $tStmt->execute(['slug' => $eventType]);
    $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
    if ($tRow) {
        $typeIsValid = true;
        $triggersClosure = ((int)$tRow['triggers_closure'] === 1);
    }
} catch (Throwable $e) {}

// Fallback: se a tabela de tipos ainda não existir/estiver vazia, aceita os tipos base.
if (!$typeIsValid) {
    $baseTypes = ['internacao', 'obito', 'alta', 'retorno', 'transferencia', 'outro'];
    if (in_array($eventType, $baseTypes, true)) {
        $typeIsValid = true;
        $triggersClosure = ($eventType === 'obito');
    }
}

if (!$typeIsValid) {
    flash_set('error', 'Tipo de evento inválido ou inativo.');
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

// IMPORTANTE: todos os comandos DDL (CREATE/ALTER) devem rodar ANTES de beginTransaction().
// No MySQL, DDL causa commit implícito e encerraria a transação, gerando o erro
// "There is no active transaction" no commit final.
try {
    // Garantir tabela de eventos
    $db->exec("CREATE TABLE IF NOT EXISTS patient_clinical_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        patient_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(120) NOT NULL,
        event_date DATE NOT NULL,
        event_time TIME NULL,
        notes TEXT NULL,
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pce_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Garantir colunas em patients, patient_clinical_events e patient_assignments (tudo antes da transação)
    foreach ([
        "ALTER TABLE patients ADD COLUMN is_closed TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE patients ADD COLUMN closed_at DATETIME NULL",
        "ALTER TABLE patients ADD COLUMN closed_reason VARCHAR(60) NULL",
        // event_type passa a aceitar tipos configuráveis (slug livre), não só o ENUM fixo.
        "ALTER TABLE patient_clinical_events MODIFY COLUMN event_type VARCHAR(120) NOT NULL",
        "ALTER TABLE patient_assignments ADD COLUMN ended_at DATETIME NULL",
        "ALTER TABLE patient_assignments ADD COLUMN end_reason_id INT UNSIGNED NULL",
        "ALTER TABLE patient_assignments ADD COLUMN end_notes TEXT NULL",
        "ALTER TABLE patient_assignments ADD COLUMN ended_by_user_id INT UNSIGNED NULL",
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

    // Tipos configurados para encerrar (ex.: Óbito): encerra o paciente e finaliza atendimentos ativos.
    if ($triggersClosure) {
        $endedAt = $eventDate . ' ' . ($eventTime !== '' ? $eventTime . ':00' : '00:00:00');

        // Marcar paciente como encerrado (guarda o slug do tipo como motivo do encerramento)
        $closedReason = substr($eventType, 0, 60);
        $adminStatus = ($eventType === 'obito') ? 'Óbito' : 'Encerrado';
        $db->prepare("
            UPDATE patients
            SET is_closed = 1, closed_at = :closed_at, closed_reason = :reason, admin_status = :adm
            WHERE id = :pid
        ")->execute(['closed_at' => $endedAt, 'reason' => $closedReason, 'adm' => $adminStatus, 'pid' => $patientId]);

        // Buscar motivo correspondente em treatment_end_reasons (tenta pelo slug do tipo, senão 'obito')
        $reasonId = null;
        try {
            $rid = $db->prepare("SELECT id FROM treatment_end_reasons WHERE slug = :slug LIMIT 1");
            $rid->execute(['slug' => $eventType]);
            $rrow = $rid->fetch(PDO::FETCH_ASSOC);
            if (!$rrow) {
                $rrow = $db->query("SELECT id FROM treatment_end_reasons WHERE slug = 'obito' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            }
            $reasonId = $rrow ? (int)$rrow['id'] : null;
        } catch (Throwable $e) {}

        // (As colunas de encerramento já foram garantidas antes da transação.)
        $closureNote = 'Encerrado automaticamente por registro de evento clínico (' . $eventType . ')';
        $db->prepare("
            UPDATE patient_assignments
            SET status = 'completed',
                ended_at = COALESCE(ended_at, :ended_at),
                end_reason_id = COALESCE(end_reason_id, :reason_id),
                end_notes = COALESCE(end_notes, :closure_note),
                ended_by_user_id = COALESCE(ended_by_user_id, :uid),
                completed_at = COALESCE(completed_at, :ended_at2)
            WHERE patient_id = :pid
              AND status NOT IN ('completed', 'cancelled')
        ")->execute([
            'ended_at' => $endedAt,
            'reason_id' => $reasonId,
            'closure_note' => $closureNote,
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
