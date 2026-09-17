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

// Novos dados do atendimento com o novo profissional (a frequência é mantida).
$newStartDate = trim((string)($_POST['start_date'] ?? ''));
$newStartTime = trim((string)($_POST['start_time'] ?? ''));
$newEndTime = trim((string)($_POST['end_time'] ?? ''));
$newAgreedValue = (float)str_replace(',', '.', (string)($_POST['agreed_value'] ?? '0'));

if ($assignmentId <= 0 || $newProfessionalId <= 0 || $reasonType === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}

// Validar os novos dados do atendimento (obrigatórios).
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newStartDate)) {
    flash_set('error', 'Informe a data de início.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}
if (!preg_match('/^\d{2}:\d{2}$/', $newStartTime) || !preg_match('/^\d{2}:\d{2}$/', $newEndTime)) {
    flash_set('error', 'Informe os horários de início e fim.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}
if ($newEndTime <= $newStartTime) {
    flash_set('error', 'O horário de fim deve ser maior que o de início.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}
if ($newAgreedValue <= 0) {
    flash_set('error', 'Informe o valor acordado com o novo profissional.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}
$newStartTimeSql = $newStartTime . ':00';
$newEndTimeSql = $newEndTime . ':00';

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
    // Atualizar o atendimento (novo profissional + novo valor acordado; frequência mantida)
    $upd = $db->prepare('UPDATE patient_assignments SET professional_user_id = :uid, professional_remote_jid = :jid, agreed_value = :av WHERE id = :id');
    $upd->execute([
        'uid' => $newProfessionalId,
        'jid' => $newProfJid,
        'av' => $newAgreedValue,
        'id' => $assignmentId,
    ]);

    // Frequência atual (mantida) para recalcular as datas das sessões futuras.
    $currentFrequency = (string)($assignment['session_frequency'] ?? '');

    // Atualizar a proposta/autorização vinculada com os novos dados (data/horário/valor).
    try {
        $updAuth = $db->prepare(
            "UPDATE authorization_requests
             SET start_date = :sd, start_time = :st, end_time = :et, agreed_value = :av,
                 professional_user_id = :uid
             WHERE (patient_assignment_id = :aid)
                OR (demand_id = :did AND patient_id = :pid)"
        );
        $updAuth->execute([
            'sd' => $newStartDate,
            'st' => $newStartTimeSql,
            'et' => $newEndTimeSql,
            'av' => $newAgreedValue,
            'uid' => $newProfessionalId,
            'aid' => $assignmentId,
            'did' => (int)($assignment['demand_id'] ?? 0),
            'pid' => $patientId,
        ]);
    } catch (Throwable $e) {
        error_log('[SUBSTITUICAO] Erro ao atualizar authorization_requests: ' . $e->getMessage());
    }

    // Atualizar o profissional das sessões futuras (pendentes) e recalcular as datas a
    // partir da nova data de início, MANTENDO a frequência atual.
    try {
        $selSessions = $db->prepare(
            "SELECT id, session_number FROM billing_document_requirements
             WHERE assignment_id = :aid AND status = 'pending'
               AND (session_date IS NULL OR session_date >= CURDATE())
             ORDER BY session_number ASC"
        );
        $selSessions->execute(['aid' => $assignmentId]);
        $pendingSessions = $selSessions->fetchAll(PDO::FETCH_ASSOC);

        if (count($pendingSessions) > 0) {
            // Vincular as sessões futuras ao novo profissional
            $updSessProf = $db->prepare(
                "UPDATE billing_document_requirements SET professional_user_id = :uid
                 WHERE assignment_id = :aid AND status = 'pending'
                   AND (session_date IS NULL OR session_date >= CURDATE())"
            );
            $updSessProf->execute(['uid' => $newProfessionalId, 'aid' => $assignmentId]);

            // Recalcular datas pela frequência mantida, a partir da nova data de início.
            $newDates = [];
            if (function_exists('frequency_normalize') && function_exists('frequency_generate_session_dates')) {
                $freqCode = $currentFrequency;
                if (!defined('FREQUENCY_WEEKDAYS_MAP') || !isset(FREQUENCY_WEEKDAYS_MAP[$freqCode])) {
                    $freqCode = frequency_normalize($currentFrequency);
                }
                if ($freqCode !== '') {
                    try {
                        $gen = frequency_generate_session_dates($freqCode, new DateTime($newStartDate), count($pendingSessions));
                        foreach ($gen as $dt) { $newDates[] = $dt->format('Y-m-d'); }
                    } catch (Throwable $e) { $newDates = []; }
                }
            }

            if (count($newDates) > 0) {
                $updDate = $db->prepare('UPDATE billing_document_requirements SET session_date = :sd WHERE id = :id');
                foreach ($pendingSessions as $idx => $sess) {
                    $updDate->execute([
                        'sd' => $newDates[$idx] ?? null,
                        'id' => (int)$sess['id'],
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[SUBSTITUICAO] Erro ao recalcular sessões: ' . $e->getMessage());
    }

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
            "UPDATE patient_assignments SET professional_user_id = :new_uid, professional_remote_jid = :new_jid, agreed_value = :av
             WHERE patient_id = :pid AND professional_user_id = :old_uid AND id != :aid
             AND status IN ('admitted','awaiting_documents','awaiting_financial_approval','confirmed','approved')"
        );
        $updAll->execute([
            'new_uid' => $newProfessionalId,
            'new_jid' => $newProfJid,
            'av' => $newAgreedValue,
            'pid' => $patientId,
            'old_uid' => $oldProfId,
            'aid' => $assignmentId,
        ]);

        // Vincular sessões futuras dos demais atendimentos ao novo profissional
        try {
            $db->prepare(
                "UPDATE billing_document_requirements bdr
                 INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
                 SET bdr.professional_user_id = :new_uid
                 WHERE pa.patient_id = :pid AND pa.professional_user_id = :new_uid2 AND pa.id != :aid
                   AND bdr.status = 'pending' AND (bdr.session_date IS NULL OR bdr.session_date >= CURDATE())"
            )->execute([
                'new_uid' => $newProfessionalId,
                'new_uid2' => $newProfessionalId,
                'pid' => $patientId,
                'aid' => $assignmentId,
            ]);
        } catch (Throwable $e) {
            error_log('[SUBSTITUICAO] Erro ao atualizar sessões dos demais atendimentos: ' . $e->getMessage());
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao salvar: ' . $e->getMessage());
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}

// Garantir que o evento "professional_removed" (aviso ao profissional ANTERIOR) exista e esteja
// ativo, com um template padrão. Assim o antigo é notificado sem depender de configuração manual.
// A equipe pode editar o texto depois em Integrações > Eventos WhatsApp.
try {
    $chkEvt = db()->prepare("SELECT id FROM whatsapp_events WHERE system_event = 'professional_removed' LIMIT 1");
    $chkEvt->execute();
    if (!$chkEvt->fetch()) {
        $tplRemovido = "Olá, {{profissional_nome}}!\n\n"
            . "🔄 *Substituição de Profissional*\n\n"
            . "Informamos que você foi substituído no atendimento abaixo e não é mais o responsável por ele:\n\n"
            . "• Paciente: {{paciente_nome}}\n"
            . "• Atendimento: #{{id_atendimento}}\n"
            . "• Especialidade: {{especialidade}}\n\n"
            . "Agradecemos o seu trabalho.\n\nEquipe MultiLife Care";
        db()->prepare(
            "INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient)
             VALUES (?, 'professional_removed', 'active', 1, 0, ?, NULL)"
        )->execute(['Substituição - Profissional anterior', $tplRemovido]);
    }
} catch (Throwable $e) {
    error_log('[SUBSTITUICAO] Erro ao garantir evento professional_removed: ' . $e->getMessage());
}

// Notificações via WhatsApp
// O template do evento "professional_substituted" usa {{id_atendimento}} e {{link_atendimento}}
// (e afins). O canal "professional" do dispatcher é usado para avisar o NOVO profissional
// ("Você foi designado como novo profissional"), então enviamos os dados do NOVO nele.
try {
    $baseUrl = rtrim((string)admin_setting_get('app.base_url', 'https://multilife.onsolutionsbrasil.com.br'), '/');
    // Rota limpa (sem .php) — o .htaccess reescreve /monitoramento -> /monitoramento.php
    $attendanceLink = $baseUrl . '/monitoramento';

    $dispatcher = new WhatsAppEventDispatcher();
    $eventData = [
        // Identificação do atendimento (resolve "ID: #" vazio e o link ausente)
        'attendance_id' => $assignmentId,
        'attendance_link' => $attendanceLink,
        'appointment_link' => $attendanceLink,
        'attendance_date' => date('d/m/Y'),

        // Paciente
        'patient_id' => $patientId,
        'patient_name' => (string)$assignment['patient_name'],
        'patient_phone' => $notifyPatient ? (string)($assignment['patient_phone'] ?? '') : '',

        // Profissional destinatário da notificação = NOVO profissional (o que foi designado)
        'professional_id' => $notifyNewProf ? $newProfessionalId : 0,
        'professional_name' => (string)$newProf['name'],
        'professional_phone' => $notifyNewProf ? (string)($newProf['phone'] ?? '') : '',

        // Dados adicionais úteis para os templates
        'new_professional_name' => (string)$newProf['name'],
        'new_professional_phone' => (string)($newProf['phone'] ?? ''),
        'old_professional_name' => (string)($assignment['old_professional_name'] ?? ''),
        'old_professional_phone' => (string)($assignment['old_professional_phone'] ?? ''),
        'specialty' => (string)($assignment['specialty'] ?? $assignment['service_type'] ?? ''),
        'service_type' => (string)($assignment['service_type'] ?? ''),
        'session_frequency' => (string)($assignment['session_frequency'] ?? ''),
        'start_date' => $newStartDate,
        'appointment_date' => $newStartDate,
        'appointment_time' => $newStartTime,
        'agreed_value' => number_format($newAgreedValue, 2, ',', '.'),
        'reason' => $reason,
    ];
    $dispatcher->dispatch('professional_substituted', $eventData);

    // Notificar o profissional ANTERIOR (evento separado, template próprio).
    // Usa o canal "professional" do dispatcher com os dados do profissional antigo.
    if ($notifyOldProf && $oldProfId > 0 && !empty($assignment['old_professional_phone'])) {
        $eventDataOld = $eventData;
        $eventDataOld['professional_id'] = $oldProfId;
        $eventDataOld['professional_name'] = (string)($assignment['old_professional_name'] ?? '');
        $eventDataOld['professional_phone'] = (string)($assignment['old_professional_phone'] ?? '');
        // Não reenviar ao paciente neste disparo (já foi no evento anterior).
        $eventDataOld['patient_phone'] = '';
        $dispatcher->dispatch('professional_removed', $eventDataOld);
    }
} catch (Throwable $e) {
    error_log('[SUBSTITUICAO] Erro ao notificar: ' . $e->getMessage());
}

// Atualizar lista de profissionais interessados (captação)
$demandIdSubst = (int)($assignment['demand_id'] ?? 0);
if ($demandIdSubst > 0) {
    try {
        require_once __DIR__ . '/app/demand_captation_handler.php';
        demand_substitute_professional($demandIdSubst, $newProfessionalId, $reason);
    } catch (Throwable $e) {
        error_log('[SUBSTITUICAO] Erro ao atualizar captação: ' . $e->getMessage());
    }
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
