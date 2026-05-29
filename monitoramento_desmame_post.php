<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$newFrequency = trim((string)($_POST['new_frequency'] ?? ''));
$newSessionQty = (int)($_POST['new_session_quantity'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$applyToAll = isset($_POST['apply_to_all']) && $_POST['apply_to_all'] === '1';

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
$db->beginTransaction();
try {
    // Atualizar o atendimento principal
    $upd = $db->prepare('UPDATE patient_assignments SET session_frequency = :freq, session_quantity = :qty WHERE id = :id');
    $upd->execute([
        'freq' => $newFrequency,
        'qty' => $newSessionQty > 0 ? $newSessionQty : $oldSessionQty,
        'id' => $assignmentId,
    ]);

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
            "UPDATE patient_assignments SET session_frequency = :freq, session_quantity = :qty
             WHERE patient_id = :pid AND id != :aid
             AND status IN ('admitted','awaiting_documents','awaiting_financial_approval','confirmed','approved')"
        );
        $updAll->execute([
            'freq' => $newFrequency,
            'qty' => $newSessionQty > 0 ? $newSessionQty : $oldSessionQty,
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
