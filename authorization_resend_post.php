<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$authId = (int)($_POST['auth_id'] ?? 0);
$newProposalValue = (float)($_POST['new_proposal_value'] ?? 0);
$newAgreedValue = (float)($_POST['new_agreed_value'] ?? 0);
$resendNotes = trim((string)($_POST['resend_notes'] ?? ''));

if ($authId <= 0) {
    flash_set('error', 'Autorização inválida.');
    header('Location: /authorization_list.php');
    exit;
}

$db = db();

$stmt = $db->prepare(
    'SELECT ar.*, d.title as demand_title, d.specialty as demand_specialty, d.location_city, d.location_state,
     u.name as professional_name, u.email as professional_email, u.phone as professional_phone,
     p.full_name as patient_name, p.email as patient_email, p.whatsapp as patient_phone
     FROM authorization_requests ar
     INNER JOIN demands d ON d.id = ar.demand_id
     INNER JOIN users u ON u.id = ar.professional_user_id
     LEFT JOIN patients p ON p.id = (SELECT patient_id FROM patient_assignments WHERE demand_id = d.id LIMIT 1)
     WHERE ar.id = :id'
);
$stmt->execute(['id' => $authId]);
$auth = $stmt->fetch();

if (!$auth) {
    flash_set('error', 'Autorização não encontrada.');
    header('Location: /authorization_list.php');
    exit;
}

// Permitir reenvio de qualquer proposta (removida restrição de status)

// Se novos valores não foram fornecidos, usar os valores existentes
if ($newProposalValue <= 0) {
    $newProposalValue = (float)$auth['proposal_value'];
}
if ($newAgreedValue <= 0) {
    $newAgreedValue = (float)$auth['agreed_value'];
}
if ($resendNotes === '') {
    $resendNotes = 'Reenvio manual da proposta';
}

$userId = auth_user_id();

$db->beginTransaction();
try {
    // Criar nova solicitação de autorização (cópia da anterior com valores atualizados ou existentes)
    $stmt = $db->prepare(
        'INSERT INTO authorization_requests 
        (demand_id, professional_user_id, proposal_value, agreed_value, 
         start_date, start_time, end_time, frequency, frequency_details, 
         sessions_per_week, total_sessions, duration_weeks, operator_email, operator_name,
         status, created_by_user_id, resend_count, previous_request_id) 
        VALUES 
        (:demand_id, :prof_id, :proposal, :agreed, 
         :start_date, :start_time, :end_time, :frequency, :freq_details,
         :sessions_per_week, :total_sessions, :duration_weeks, :op_email, :op_name,
         :status, :created_by, :resend_count, :previous_id)'
    );
    
    $resendCount = (int)($auth['resend_count'] ?? 0) + 1;
    
    $stmt->execute([
        'demand_id' => $auth['demand_id'],
        'prof_id' => $auth['professional_user_id'],
        'proposal' => $newProposalValue,
        'agreed' => $newAgreedValue,
        'start_date' => $auth['start_date'],
        'start_time' => $auth['start_time'],
        'end_time' => $auth['end_time'],
        'frequency' => $auth['frequency'],
        'freq_details' => $auth['frequency_details'],
        'sessions_per_week' => (int)($auth['sessions_per_week'] ?? 1),
        'total_sessions' => $auth['total_sessions'],
        'duration_weeks' => $auth['duration_weeks'],
        'op_email' => $auth['operator_email'],
        'op_name' => $auth['operator_name'],
        'status' => 'aguardando_autorizacao',
        'created_by' => $userId,
        'resend_count' => $resendCount,
        'previous_id' => $authId
    ]);
    
    $newAuthId = (int)$db->lastInsertId();
    
    // Atualizar autorização anterior para cancelada
    $stmt = $db->prepare('UPDATE authorization_requests SET status = :status WHERE id = :id');
    $stmt->execute(['status' => 'cancelada', 'id' => $authId]);
    
    // Atualizar demanda para aguardando_autorizacao
    $stmt = $db->prepare('UPDATE demands SET status = :status WHERE id = :id');
    $stmt->execute(['status' => 'aguardando_autorizacao', 'id' => $auth['demand_id']]);
    
    // Registrar histórico na autorização anterior
    $stmt = $db->prepare(
        'INSERT INTO authorization_request_history 
        (authorization_request_id, action, proposal_value, notes, user_id) 
        VALUES (:auth_id, :action, :proposal, :notes, :uid)'
    );
    $stmt->execute([
        'auth_id' => $authId,
        'action' => 'resent',
        'proposal' => $newProposalValue,
        'notes' => "Proposta reenviada com novo valor. Nova solicitação: #$newAuthId",
        'uid' => $userId
    ]);
    
    // Registrar histórico na nova autorização
    $stmt->execute([
        'auth_id' => $newAuthId,
        'action' => 'created',
        'proposal' => $newProposalValue,
        'notes' => "Reenvio da proposta #$authId. Justificativa: $resendNotes",
        'uid' => $userId
    ]);
    
    $db->commit();
    
    // Preparar e enviar e-mail com template HTML
    require_once __DIR__ . '/app/email_template_processor.php';
    
    $totalSessions = (int)$auth['total_sessions'];
    $totalProposal = $newProposalValue * $totalSessions;
    $location = trim(($auth['location_city'] ?? '') . '/' . ($auth['location_state'] ?? ''));
    
    $frequencyText = match((string)$auth['frequency']) {
        'single' => 'Atendimento único',
        'daily' => 'Diário',
        'weekly' => 'Semanal',
        'biweekly' => 'Quinzenal',
        'monthly' => 'Mensal',
        'custom' => 'Personalizado',
        default => 'Semanal'
    };
    
    // Buscar dados adicionais do profissional
    $stmt = $db->prepare("SELECT specialty, council_number, council_state FROM professional_applications WHERE created_user_id = :uid AND status = 'approved' LIMIT 1");
    $stmt->execute(['uid' => $auth['professional_user_id']]);
    $profDetails = $stmt->fetch();
    
    $professionalSpecialty = $profDetails ? (string)$profDetails['specialty'] : (string)$auth['demand_specialty'];
    $professionalCouncil = $profDetails ? (string)$profDetails['council_number'] : '';
    $professionalCouncilState = $profDetails ? (string)$profDetails['council_state'] : '';
    
    $professionalCouncilFull = '';
    if (!empty($professionalCouncil)) {
        $professionalCouncilFull = $professionalCouncil;
        if (!empty($professionalCouncilState)) {
            $professionalCouncilFull .= '/' . $professionalCouncilState;
        }
    }
    
    // Calcular valores anteriores
    $previousProposal = (float)$auth['proposal_value'];
    $previousTotal = $previousProposal * $totalSessions;
    
    // Preparar variáveis para o template
    $variables = [
        'patient_name' => $auth['patient_name'],
        'patient_email' => $auth['patient_email'] ?? '',
        'patient_phone' => $auth['patient_phone'] ?? '',
        'professional_name' => $auth['professional_name'],
        'professional_email' => $auth['professional_email'] ?? '',
        'professional_phone' => $auth['professional_phone'] ?? '',
        'professional_council' => $professionalCouncilFull,
        'specialty' => $professionalSpecialty,
        'location' => $location,
        'start_date' => email_format_date((string)$auth['start_date']),
        'start_time' => email_format_time((string)$auth['start_time']),
        'end_time' => email_format_time((string)$auth['end_time']),
        'frequency_text' => $frequencyText,
        'duration_weeks' => (string)$auth['duration_weeks'],
        'total_sessions' => (string)$totalSessions,
        'value_per_session' => email_format_currency($newProposalValue),
        'total_value' => email_format_currency($totalProposal),
        'previous_value_per_session' => email_format_currency($previousProposal),
        'previous_total_value' => email_format_currency($previousTotal),
        'resend_notes' => $resendNotes,
    ];
    
    // Enviar e-mail com template
    try {
        // Buscar operadora do paciente para template específico
        $stmt = $db->prepare('SELECT health_insurer_id FROM patients WHERE full_name = :name LIMIT 1');
        $stmt->execute(['name' => $auth['patient_name']]);
        $patientData = $stmt->fetch();
        $healthInsurerId = $patientData ? (int)$patientData['health_insurer_id'] : null;
        
        $emailResult = email_send_with_template((string)$auth['operator_email'], 'proposal_resend', $variables, $healthInsurerId);
        
        if (!$emailResult['success']) {
            throw new Exception($emailResult['message']);
        }
        
        $messageId = $emailResult['message_id'] ?? '';
        
        // Atualizar registro com data de envio e prazo de resposta
        $sentAt = date('Y-m-d H:i:s');
        $responseDeadline = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        
        $stmt = $db->prepare(
            'UPDATE authorization_requests 
             SET sent_at = :sent, response_deadline = :deadline, sent_message_id = :msg_id
             WHERE id = :id'
        );
        $stmt->execute([
            'sent' => $sentAt,
            'deadline' => $responseDeadline,
            'msg_id' => $messageId,
            'id' => $newAuthId
        ]);
        
        // Registrar histórico de envio
        $stmt = $db->prepare(
            'INSERT INTO authorization_request_history 
            (authorization_request_id, action, proposal_value, notes, user_id) 
            VALUES (:auth_id, :action, :proposal, :notes, :uid)'
        );
        $stmt->execute([
            'auth_id' => $newAuthId,
            'action' => 'sent',
            'proposal' => $newProposalValue,
            'notes' => "E-mail de reenvio enviado para {$auth['operator_email']}",
            'uid' => $userId
        ]);
        
        flash_set('success', 'Proposta reenviada com sucesso! Aguardando nova resposta da operadora.');
        header('Location: /authorization_view.php?id=' . $newAuthId);
        exit;
        
    } catch (Exception $e) {
        error_log('Erro ao enviar e-mail de reenvio: ' . $e->getMessage());
        flash_set('warning', 'Solicitação criada, mas houve erro ao enviar e-mail: ' . $e->getMessage());
        header('Location: /authorization_view.php?id=' . $newAuthId);
        exit;
    }
    
} catch (Exception $e) {
    $db->rollBack();
    error_log('Erro ao reenviar proposta: ' . $e->getMessage());
    flash_set('error', 'Erro ao reenviar proposta: ' . $e->getMessage());
    header('Location: /authorization_resend.php?id=' . $authId);
    exit;
}
