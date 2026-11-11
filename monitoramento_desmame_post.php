<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$newFrequency = trim((string)($_POST['new_frequency'] ?? ''));
$isIndefinite = isset($_POST['is_indefinite']) && (string)$_POST['is_indefinite'] === '1' ? 1 : 0;
// Em tempo indeterminado não há total de sessões definido; ignora o campo de quantidade.
$newSessionQty = $isIndefinite ? 0 : (int)($_POST['new_session_quantity'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$applyToAll = isset($_POST['apply_to_all']) && $_POST['apply_to_all'] === '1';
$newWeekdays = isset($_POST['weekdays']) && is_array($_POST['weekdays']) ? array_map('intval', $_POST['weekdays']) : [];
// Dias do mês (para quinzenal/mensal): valores 1..31
$newMonthDays = isset($_POST['month_days']) && is_array($_POST['month_days'])
    ? array_values(array_unique(array_filter(array_map('intval', $_POST['month_days']), fn($d) => $d >= 1 && $d <= 31)))
    : [];

// Modo "posição + dia da semana" (para quinzenal/mensal): ex.: 1ª quinta-feira do mês
$monthMode = isset($_POST['month_mode']) ? (string)$_POST['month_mode'] : 'fixed_day';
$weekPositions = isset($_POST['week_position']) && is_array($_POST['week_position']) ? $_POST['week_position'] : [];
$weekWeekdays = isset($_POST['week_weekday']) && is_array($_POST['week_weekday']) ? array_map('intval', $_POST['week_weekday']) : [];

// Se não veio weekdays do formulário mas temos frequência padronizada, usar a tabela
if (count($newWeekdays) === 0 && $newFrequency !== '' && function_exists('frequency_get_weekdays')) {
    $freqCode = $newFrequency;
    if (!isset(FREQUENCY_WEEKDAYS_MAP[$freqCode])) {
        $freqCode = frequency_normalize($newFrequency);
    }
    if ($freqCode !== '') {
        $newWeekdays = frequency_get_weekdays($freqCode);
    }
}

// Garantir coluna month_days (fallback)
try { db()->exec("ALTER TABLE patient_assignments ADD COLUMN month_days VARCHAR(120) NULL"); } catch (Throwable $e) {}
// Garantir coluna is_indefinite (tempo indeterminado)
try { db()->exec("ALTER TABLE patient_assignments ADD COLUMN is_indefinite TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// Frequências mensais (usam dia do mês em vez de dia da semana)
$isMonthlyFreq = in_array($newFrequency, ['quinzenal', 'biweekly', 'mensal', 'monthly'], true);

if ($assignmentId <= 0 || $newFrequency === '' || $reason === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /monitoramento_desmame.php?assignment_id=' . $assignmentId);
    exit;
}

$stmt = db()->prepare(
    "SELECT pa.*, p.full_name as patient_name, p.whatsapp as patient_phone, p.id as patient_id,
            u.name as professional_name, u.phone as professional_phone
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     LEFT JOIN users u ON u.id = pa.professional_user_id
     WHERE pa.id = :id"
);
$stmt->execute(['id' => $assignmentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    flash_set('error', 'Atendimento não encontrado.');
    header('Location: /monitoramento.php');
    exit;
}

$oldFrequency = (string)($assignment['session_frequency'] ?? '');
$oldSessionQty = (int)($assignment['session_quantity'] ?? 0);
$patientId = (int)$assignment['patient_id'];

$db = db();
clients_ensure_schema(); // coluna weaning_at (DDL fora da transação)
$db->beginTransaction();
try {
    // Data do desmame (exibida no Monitoramento e nos fechamentos).
    try {
        $db->prepare('UPDATE patient_assignments SET weaning_at = CURDATE() WHERE id = :id')->execute(['id' => $assignmentId]);
    } catch (Throwable $e) {
        error_log('[DESMAME] weaning_at: ' . $e->getMessage());
    }

    // Atualizar o atendimento e remarcar as sessões pendentes futuras
    // (regra única em app/assignment_schedule_helper.php, também usada pelo Fechamento).
    $monthCfg = null;
    if ($isMonthlyFreq) {
        if ($monthMode === 'weekday_position' && count($weekPositions) > 0) {
            $pairs = [];
            for ($wp = 0; $wp < count($weekPositions); $wp++) {
                if (isset($weekWeekdays[$wp])) {
                    $pairs[] = ['pos' => (string)$weekPositions[$wp], 'weekday' => (int)$weekWeekdays[$wp]];
                }
            }
            $monthCfg = ['mode' => 'weekday_position', 'days' => [], 'pairs' => $pairs];
        } elseif (count($newMonthDays) > 0) {
            $monthCfg = ['mode' => 'fixed_day', 'days' => array_values($newMonthDays), 'pairs' => []];
        }
    }
    assignment_reschedule($db, $assignment, $newFrequency, $isIndefinite, $newSessionQty, $newWeekdays, $monthCfg);

    // Registrar no log
    $ins = $db->prepare(
        'INSERT INTO patient_frequency_changes (assignment_id, patient_id, old_frequency, new_frequency, old_session_quantity, new_session_quantity, reason, apply_to_all, changed_by_user_id)
         VALUES (:aid, :pid, :of, :nf, :oq, :nq, :reason, :ata, :uid)'
    );
    $ins->execute([
        'aid' => $assignmentId,
        'pid' => $patientId,
        'of' => $oldFrequency !== '' ? $oldFrequency : null,
        'nf' => $newFrequency,
        'oq' => $oldSessionQty > 0 ? $oldSessionQty : null,
        'nq' => $newSessionQty > 0 ? $newSessionQty : null,
        'reason' => $reason,
        'ata' => $applyToAll ? 1 : 0,
        'uid' => auth_user_id(),
    ]);

    // Se aplicar a todos, atualizar outros atendimentos do paciente
    if ($applyToAll) {
        $updAll = $db->prepare(
            "UPDATE patient_assignments SET session_frequency = :freq, session_quantity = :qty, is_indefinite = :indef
             WHERE patient_id = :pid AND id != :aid
             AND status IN ('admitted','awaiting_documents','awaiting_financial_approval','confirmed','approved')"
        );
        $updAll->execute([
            'freq' => $newFrequency,
            'qty' => $isIndefinite ? $oldSessionQty : ($newSessionQty > 0 ? $newSessionQty : $oldSessionQty),
            'indef' => $isIndefinite,
            'pid' => $patientId,
            'aid' => $assignmentId,
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao salvar: ' . $e->getMessage());
    header('Location: /monitoramento_desmame.php?assignment_id=' . $assignmentId);
    exit;
}

// Notificar via WhatsApp (profissional e paciente)
try {
    $dispatcher = new WhatsAppEventDispatcher();
    $dispatcher->dispatch('frequency_changed', [
        'patient_id' => $patientId,
        'patient_name' => (string)$assignment['patient_name'],
        'patient_phone' => (string)($assignment['patient_phone'] ?? ''),
        'professional_id' => (int)($assignment['professional_user_id'] ?? 0),
        'professional_name' => (string)($assignment['professional_name'] ?? ''),
        'professional_phone' => (string)($assignment['professional_phone'] ?? ''),
        'old_frequency' => $oldFrequency,
        'new_frequency' => $newFrequency,
        'reason' => $reason,
    ]);
} catch (Throwable $e) {
    error_log('[DESMAME] Erro ao notificar: ' . $e->getMessage());
}

audit_log('update', 'patient_assignment_frequency', (string)$assignmentId, [
    'frequency' => $oldFrequency,
    'session_quantity' => $oldSessionQty,
], [
    'frequency' => $newFrequency,
    'session_quantity' => $newSessionQty,
]);

flash_set('success', 'Frequência alterada com sucesso! De "' . $oldFrequency . '" para "' . $newFrequency . '".');
header('Location: /monitoramento.php');
exit;
