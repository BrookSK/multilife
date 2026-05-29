<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$newProfessionalId = (int)($_POST['new_professional_id'] ?? 0);
$reasonType = trim((string)($_POST['reason_type'] ?? ''));
$reasonDetails = trim((string)($_POST['reason_details'] ?? ''));
$applyToAll = isset($_POST['apply_to_all']) && $_POST['apply_to_all'] === '1';
$notifyPatient = isset($_POST['notify_patient']);
$notifyOldProf = isset($_POST['notify_old_professional']);
$notifyNewProf = isset($_POST['notify_new_professional']);

if ($assignmentId <= 0 || $newProfessionalId <= 0 || $reasonType === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}

// Buscar atendimento atual
$stmt = db()->prepare(
    "SELECT pa.*, p.full_name as patient_name, p.whatsapp as patient_phone, p.id as patient_id,
            u.name as old_professional_name, u.phone as old_professional_phone
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

// Buscar novo profissional
$stmtNewProf = db()->prepare('SELECT id, name, phone, email FROM users WHERE id = :id AND status = \'active\'');
$stmtNewProf->execute(['id' => $newProfessionalId]);
$newProf = $stmtNewProf->fetch();

if (!$newProf) {
    flash_set('error', 'Profissional selecionado não encontrado ou inativo.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}

$oldProfId = (int)($assignment['professional_user_id'] ?? 0);
$oldProfJid = (string)($assignment['professional_remote_jid'] ?? '');
$patientId = (int)$assignment['patient_id'];

// Montar motivo completo
$reasonLabels = [
    'ferias' => 'Férias do profissional',
    'desligamento' => 'Desligamento do profissional',
    'pedido_paciente' => 'Pedido do paciente/família',
    'indisponibilidade' => 'Indisponibilidade de horário',
    'mudanca_regiao' => 'Mudança de região',
    'outro' => 'Outro',
];
$reason = ($reasonLabels[$reasonType] ?? $reasonType);
if ($reasonDetails !== '') {
    $reason .= ' - ' . $reasonDetails;
}

// Determinar JID do novo profissional
$newProfPhone = preg_replace('/\D+/', '', (string)($newProf['phone'] ?? ''));
$newProfJid = $newProfPhone !== '' ? $newProfPhone . '@s.whatsapp.net' : '';

$db = db();
$db->beginTransaction();
try {
    // Atualizar o atendimento
    $upd = $db->prepare('UPDATE patient_assignments SET professional_user_id = :uid, professional_remote_jid = :jid WHERE id = :id');
    $upd->execute([
        'uid' => $newProfessionalId,
        'jid' => $newProfJid,
        'id' => $assignmentId,
    ]);

    // Registrar no log
    $ins = $db->prepare(
        'INSERT INTO patient_professional_substitutions (assignment_id, patient_id, old_professional_user_id, new_professional_user_id, old_professional_jid, new_professional_jid, reason, apply_to_all, notify_patient, notify_old_professional, notify_new_professional, changed_by_user_id)
         VALUES (:aid, :pid, :old_uid, :new_uid, :old_jid, :new_jid, :reason, :ata, :np, :nop, :nnp, :uid)'
    );
    $ins->execute([
        'aid' => $assignmentId,
        'pid' => $patientId,
        'old_uid' => $oldProfId > 0 ? $oldProfId : null,
        'new_uid' => $newProfessionalId,
        'old_jid' => $oldProfJid !== '' ? $oldProfJid : null,
        'new_jid' => $newProfJid !== '' ? $newProfJid : null,
        'reason' => $reason,
        'ata' => $applyToAll ? 1 : 0,
        'np' => $notifyPatient ? 1 : 0,
        'nop' => $notifyOldProf ? 1 : 0,
        'nnp' => $notifyNewProf ? 1 : 0,
        'uid' => auth_user_id(),
    ]);

    // Se aplicar a todos, atualizar outros atendimentos do paciente com o mesmo profissional
    if ($applyToAll && $oldProfId > 0) {
        $updAll = $db->prepare(
            "UPDATE patient_assignments SET professional_user_id = :new_uid, professional_remote_jid = :new_jid
             WHERE patient_id = :pid AND professional_user_id = :old_uid AND id != :aid
             AND status IN ('admitted','awaiting_documents','awaiting_financial_approval','confirmed','approved')"
        );
        $updAll->execute([
            'new_uid' => $newProfessionalId,
            'new_jid' => $newProfJid,
            'pid' => $patientId,
            'old_uid' => $oldProfId,
            'aid' => $assignmentId,
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao salvar: ' . $e->getMessage());
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}

// Notificações via WhatsApp
try {
    $dispatcher = new WhatsAppEventDispatcher();
    $eventData = [
        'patient_id' => $patientId,
        'patient_name' => (string)$assignment['patient_name'],
        'patient_phone' => $notifyPatient ? (string)($assignment['patient_phone'] ?? '') : '',
        'professional_id' => $notifyOldProf ? $oldProfId : 0,
        'professional_name' => (string)($assignment['old_professional_name'] ?? ''),
        'professional_phone' => $notifyOldProf ? (string)($assignment['old_professional_phone'] ?? '') : '',
        'new_professional_name' => (string)$newProf['name'],
        'new_professional_phone' => $notifyNewProf ? (string)($newProf['phone'] ?? '') : '',
        'reason' => $reason,
    ];
    $dispatcher->dispatch('professional_substituted', $eventData);
} catch (Throwable $e) {
    error_log('[SUBSTITUICAO] Erro ao notificar: ' . $e->getMessage());
}

audit_log('update', 'patient_assignment_substitution', (string)$assignmentId, [
    'professional_user_id' => $oldProfId,
], [
    'professional_user_id' => $newProfessionalId,
    'reason' => $reason,
]);

flash_set('success', 'Profissional substituído com sucesso! De "' . ($assignment['old_professional_name'] ?? '-') . '" para "' . $newProf['name'] . '".');
header('Location: /monitoramento.php');
exit;
