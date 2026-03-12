<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$chatId = (int)($_POST['chat_id'] ?? 0);
$demandId = (int)($_POST['demand_id'] ?? 0);
$patientId = (int)($_POST['patient_id'] ?? 0);
$professionalUserId = (int)($_POST['professional_user_id'] ?? 0);
$specialty = trim((string)($_POST['specialty'] ?? ''));
$operatorEmail = trim((string)($_POST['operator_email'] ?? ''));

// Dados do agendamento
$startDate = trim((string)($_POST['start_date'] ?? ''));
$startTime = trim((string)($_POST['start_time'] ?? ''));
$endTime = trim((string)($_POST['end_time'] ?? ''));
$frequency = trim((string)($_POST['frequency'] ?? 'weekly'));
$frequencyDetails = trim((string)($_POST['frequency_details'] ?? ''));
$durationWeeks = (int)($_POST['duration_weeks'] ?? 0);
$totalSessions = (int)($_POST['total_sessions'] ?? 0);

// Valores
$agreedValue = (float)($_POST['agreed_value'] ?? 0);
$proposalValue = (float)($_POST['proposal_value'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));

// Validações básicas
if ($chatId <= 0) {
    flash_set('error', 'Conversa inválida.');
    header('Location: /chat_web.php');
    exit;
}

if ($demandId <= 0) {
    flash_set('error', 'Selecione uma demanda.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

if ($patientId <= 0 || $professionalUserId <= 0 || $specialty === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

if (!filter_var($operatorEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail da operadora inválido.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

if ($startDate === '' || $startTime === '' || $endTime === '') {
    flash_set('error', 'Preencha data e horários do agendamento.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

if ($totalSessions <= 0 || $durationWeeks <= 0) {
    flash_set('error', 'Informe duração e total de sessões.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

if ($proposalValue <= 0) {
    flash_set('error', 'Valor de proposta deve ser maior que zero.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

$allowedFreq = ['single', 'daily', 'weekly', 'biweekly', 'monthly', 'custom'];
if (!in_array($frequency, $allowedFreq, true)) {
    $frequency = 'weekly';
}

$db = db();

// Buscar dados da demanda
$stmt = $db->prepare('SELECT id, title, specialty, location_city, location_state, origin_email FROM demands WHERE id = :id');
$stmt->execute(['id' => $demandId]);
$demand = $stmt->fetch();

if (!$demand) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

// Buscar dados do paciente
$stmt = $db->prepare('SELECT id, full_name, email, phone FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

// Buscar dados do profissional
$stmt = $db->prepare(
    "SELECT u.id, u.name, u.email, u.phone 
     FROM users u 
     INNER JOIN user_roles ur ON ur.user_id = u.id 
     INNER JOIN roles r ON r.id = ur.role_id 
     WHERE u.id = :id AND u.status='active' AND r.slug='profissional' 
     LIMIT 1"
);
$stmt->execute(['id' => $professionalUserId]);
$professional = $stmt->fetch();

if (!$professional) {
    flash_set('error', 'Profissional não encontrado.');
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}

// Buscar dados adicionais do profissional (registro, especialidade)
$stmt = $db->prepare("SELECT specialty, council_number, council_state FROM professional_applications WHERE created_user_id = :uid AND status = 'approved' LIMIT 1");
$stmt->execute(['uid' => $professionalUserId]);
$profDetails = $stmt->fetch();

$professionalSpecialty = $profDetails ? (string)$profDetails['specialty'] : $specialty;
$professionalCouncil = $profDetails ? (string)$profDetails['council_number'] : '';
$professionalCouncilState = $profDetails ? (string)$profDetails['council_state'] : '';

// Preparar detalhes de frequência (JSON)
$frequencyDetailsJson = json_encode([
    'type' => $frequency,
    'description' => $frequencyDetails,
    'duration_weeks' => $durationWeeks,
    'total_sessions' => $totalSessions
]);

$userId = auth_user_id();

$db->beginTransaction();
try {
    // Criar solicitação de autorização
    $stmt = $db->prepare(
        'INSERT INTO authorization_requests 
        (demand_id, professional_user_id, proposal_value, agreed_value, 
         start_date, start_time, end_time, frequency, frequency_details, 
         total_sessions, duration_weeks, operator_email, operator_name,
         status, created_by_user_id) 
        VALUES 
        (:demand_id, :prof_id, :proposal, :agreed, 
         :start_date, :start_time, :end_time, :frequency, :freq_details,
         :total_sessions, :duration_weeks, :op_email, :op_name,
         :status, :created_by)'
    );
    
    $operatorName = explode('@', $operatorEmail)[0];
    
    $stmt->execute([
        'demand_id' => $demandId,
        'prof_id' => $professionalUserId,
        'proposal' => $proposalValue,
        'agreed' => $agreedValue,
        'start_date' => $startDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'frequency' => $frequency,
        'freq_details' => $frequencyDetailsJson,
        'total_sessions' => $totalSessions,
        'duration_weeks' => $durationWeeks,
        'op_email' => $operatorEmail,
        'op_name' => $operatorName,
        'status' => 'aguardando_autorizacao',
        'created_by' => $userId
    ]);
    
    $authRequestId = (int)$db->lastInsertId();
    
    // Atualizar status da demanda
    $stmt = $db->prepare('UPDATE demands SET status = :status WHERE id = :id');
    $stmt->execute(['status' => 'aguardando_autorizacao', 'id' => $demandId]);
    
    // Registrar log de status da demanda
    $stmt = $db->prepare(
        'INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) 
         VALUES (:did, :old, :new, :uid, :note)'
    );
    $stmt->execute([
        'did' => $demandId,
        'old' => $demand['status'],
        'new' => 'aguardando_autorizacao',
        'uid' => $userId,
        'note' => 'Proposta enviada para operadora - Aguardando autorização'
    ]);
    
    // Registrar histórico da autorização
    $stmt = $db->prepare(
        'INSERT INTO authorization_request_history 
        (authorization_request_id, action, proposal_value, notes, user_id) 
        VALUES (:auth_id, :action, :proposal, :notes, :uid)'
    );
    $stmt->execute([
        'auth_id' => $authRequestId,
        'action' => 'created',
        'proposal' => $proposalValue,
        'notes' => 'Solicitação de autorização criada',
        'uid' => $userId
    ]);
    
    $db->commit();
    
    // Preparar e enviar e-mail para a operadora
    $totalProposal = $proposalValue * $totalSessions;
    $location = trim(($demand['location_city'] ?? '') . '/' . ($demand['location_state'] ?? ''));
    
    $frequencyText = match($frequency) {
        'single' => 'Atendimento único',
        'daily' => 'Diário',
        'weekly' => 'Semanal',
        'biweekly' => 'Quinzenal',
        'monthly' => 'Mensal',
        'custom' => 'Personalizado',
        default => 'Semanal'
    };
    
    $emailSubject = "Proposta de Atendimento - {$patient['full_name']} - {$specialty}";
    
    $emailBody = "Prezado(a),\n\n";
    $emailBody .= "Segue proposta de atendimento domiciliar:\n\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "DADOS DO PACIENTE\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "Nome: {$patient['full_name']}\n";
    if (!empty($patient['email'])) $emailBody .= "E-mail: {$patient['email']}\n";
    if (!empty($patient['phone'])) $emailBody .= "Telefone: {$patient['phone']}\n";
    if (!empty($location)) $emailBody .= "Localização: {$location}\n";
    $emailBody .= "\n";
    
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "PROFISSIONAL DESIGNADO\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "Nome: {$professional['name']}\n";
    $emailBody .= "Especialidade: {$professionalSpecialty}\n";
    if (!empty($professionalCouncil)) {
        $emailBody .= "Registro: {$professionalCouncil}";
        if (!empty($professionalCouncilState)) $emailBody .= "/{$professionalCouncilState}";
        $emailBody .= "\n";
    }
    if (!empty($professional['email'])) $emailBody .= "E-mail: {$professional['email']}\n";
    if (!empty($professional['phone'])) $emailBody .= "Telefone: {$professional['phone']}\n";
    $emailBody .= "\n";
    
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "DADOS DO ATENDIMENTO PROPOSTO\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "Data de Início: " . date('d/m/Y', strtotime($startDate)) . "\n";
    $emailBody .= "Horário: {$startTime} às {$endTime}\n";
    $emailBody .= "Frequência: {$frequencyText}\n";
    $emailBody .= "Duração: {$durationWeeks} semanas\n";
    $emailBody .= "Total de Sessões: {$totalSessions}\n";
    if (!empty($frequencyDetails)) {
        $emailBody .= "Detalhes: {$frequencyDetails}\n";
    }
    $emailBody .= "\n";
    
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "VALORES\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "Valor por Sessão: R$ " . number_format($proposalValue, 2, ',', '.') . "\n";
    $emailBody .= "Total de Sessões: {$totalSessions}\n";
    $emailBody .= "VALOR TOTAL DA PROPOSTA: R$ " . number_format($totalProposal, 2, ',', '.') . "\n";
    $emailBody .= "\n";
    
    if (!empty($notes)) {
        $emailBody .= "═══════════════════════════════════════════════════════\n";
        $emailBody .= "OBSERVAÇÕES\n";
        $emailBody .= "═══════════════════════════════════════════════════════\n";
        $emailBody .= "{$notes}\n\n";
    }
    
    $emailBody .= "═══════════════════════════════════════════════════════\n\n";
    $emailBody .= "Aguardamos retorno com a autorização ou eventuais ajustes necessários.\n\n";
    $emailBody .= "Atenciosamente,\n";
    $emailBody .= "MultiLife Care\n";
    $emailBody .= "Sistema de Gestão de Atendimentos\n";
    
    // Enviar e-mail
    try {
        $smtp = new SmtpClient();
        $fromEmail = admin_setting_get('smtp.out.from_email', 'noreply@multilife.com.br');
        $fromName = admin_setting_get('smtp.out.from_name', 'MultiLife Care');
        
        $smtp->send($fromEmail, $fromName, $operatorEmail, $emailSubject, $emailBody);
        
        // Atualizar registro com data de envio e prazo de resposta
        $sentAt = date('Y-m-d H:i:s');
        $responseDeadline = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        
        $stmt = $db->prepare(
            'UPDATE authorization_requests 
             SET sent_at = :sent, response_deadline = :deadline 
             WHERE id = :id'
        );
        $stmt->execute([
            'sent' => $sentAt,
            'deadline' => $responseDeadline,
            'id' => $authRequestId
        ]);
        
        // Registrar histórico de envio
        $stmt = $db->prepare(
            'INSERT INTO authorization_request_history 
            (authorization_request_id, action, proposal_value, notes, user_id) 
            VALUES (:auth_id, :action, :proposal, :notes, :uid)'
        );
        $stmt->execute([
            'auth_id' => $authRequestId,
            'action' => 'sent',
            'proposal' => $proposalValue,
            'notes' => "E-mail enviado para {$operatorEmail}",
            'uid' => $userId
        ]);
        
        flash_set('success', 'Proposta enviada com sucesso! Aguardando resposta da operadora.');
        header('Location: /authorization_list.php?status=aguardando_autorizacao');
        exit;
        
    } catch (Exception $e) {
        error_log('Erro ao enviar e-mail de proposta: ' . $e->getMessage());
        flash_set('warning', 'Solicitação criada, mas houve erro ao enviar e-mail: ' . $e->getMessage());
        header('Location: /authorization_list.php?status=aguardando_autorizacao');
        exit;
    }
    
} catch (Exception $e) {
    $db->rollBack();
    error_log('Erro ao criar solicitação de autorização: ' . $e->getMessage());
    flash_set('error', 'Erro ao criar solicitação: ' . $e->getMessage());
    header('Location: /chat_select_professional.php?chat_id=' . $chatId);
    exit;
}
