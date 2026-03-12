<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

// ============================================================================
// DEBUG DETALHADO - INÍCIO
// ============================================================================
error_log("=== CHAT_SELECT_PROFESSIONAL_POST - INÍCIO ===");
error_log("POST DATA: " . print_r($_POST, true));
error_log("USER ID: " . auth_user_id());

$chatJid = trim((string)($_POST['chat_jid'] ?? ''));
$demandId = (int)($_POST['demand_id'] ?? 0);
$patientId = (int)($_POST['patient_id'] ?? 0);
$professionalUserId = (int)($_POST['professional_user_id'] ?? 0);
$specialtyId = (int)($_POST['specialty_id'] ?? 0);
$specialtyServiceId = (int)($_POST['specialty_service_id'] ?? 0);
$operatorEmail = trim((string)($_POST['operator_email'] ?? ''));

error_log("Parâmetros extraídos:");
error_log("  chatJid: $chatJid");
error_log("  demandId: $demandId");
error_log("  patientId: $patientId");
error_log("  professionalUserId: $professionalUserId");
error_log("  specialtyId: $specialtyId");
error_log("  specialtyServiceId: $specialtyServiceId");
error_log("  operatorEmail: $operatorEmail");

// Dados do agendamento
$startDate = trim((string)($_POST['start_date'] ?? ''));
$startTime = trim((string)($_POST['start_time'] ?? ''));
$endTime = trim((string)($_POST['end_time'] ?? ''));
$frequency = trim((string)($_POST['frequency'] ?? 'weekly'));
$frequencyDetails = trim((string)($_POST['frequency_details'] ?? ''));
$sessionsPerWeek = (int)($_POST['sessions_per_week'] ?? 1);
$durationWeeks = (int)($_POST['duration_weeks'] ?? 0);
$totalSessions = (int)($_POST['total_sessions'] ?? 0);

// Valores
$agreedValue = (float)($_POST['agreed_value'] ?? 0);
$proposalValue = (float)($_POST['proposal_value'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));

// ============================================================================
// VALIDAÇÕES BÁSICAS
// ============================================================================
error_log("=== VALIDAÇÕES ===");

if ($chatJid === '') {
    error_log("ERRO: chatJid vazio");
    flash_set('error', 'Conversa inválida.');
    header('Location: /chat_web.php');
    exit;
}
error_log("✓ chatJid válido");

if ($demandId <= 0) {
    error_log("ERRO: demandId inválido: $demandId");
    flash_set('error', 'Selecione uma demanda.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}
error_log("✓ demandId válido");

if ($patientId <= 0 || $professionalUserId <= 0 || $specialtyId <= 0 || $specialtyServiceId <= 0) {
    error_log("ERRO: Campos obrigatórios faltando - Patient: $patientId, Prof: $professionalUserId, Spec: $specialtyId, Service: $specialtyServiceId");
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}
error_log("✓ Todos os campos obrigatórios preenchidos");

if (!filter_var($operatorEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail da operadora inválido.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}

if ($startDate === '' || $startTime === '' || $endTime === '') {
    flash_set('error', 'Preencha data e horários do agendamento.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}

if ($totalSessions <= 0 || $durationWeeks <= 0) {
    flash_set('error', 'Informe duração e total de sessões.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}

if ($proposalValue <= 0) {
    flash_set('error', 'Valor de proposta deve ser maior que zero.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}

$allowedFreq = ['single', 'daily', 'weekly', 'biweekly', 'monthly', 'custom'];
if (!in_array($frequency, $allowedFreq, true)) {
    $frequency = 'weekly';
}

$db = db();

// ============================================================================
// BUSCAR DADOS NO BANCO
// ============================================================================
error_log("=== BUSCANDO DADOS NO BANCO ===");

// Buscar dados da demanda
error_log("Buscando demanda ID: $demandId");
$stmt = $db->prepare('SELECT id, title, specialty, location_city, location_state, origin_email, status FROM demands WHERE id = :id');
$stmt->execute(['id' => $demandId]);
$demand = $stmt->fetch();

if (!$demand) {
    error_log("ERRO: Demanda não encontrada - ID: $demandId");
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}
error_log("✓ Demanda encontrada: " . $demand['title'] . " (Status: " . $demand['status'] . ")");

// Buscar dados do paciente
error_log("Buscando paciente ID: $patientId");
$stmt = $db->prepare('SELECT id, full_name, email, phone_primary, whatsapp FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    error_log("ERRO: Paciente não encontrado - ID: $patientId");
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}
error_log("✓ Paciente encontrado: " . $patient['full_name']);

// Buscar dados do profissional
error_log("Buscando profissional ID: $professionalUserId");
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
    error_log("ERRO: Profissional não encontrado - ID: $professionalUserId");
    flash_set('error', 'Profissional não encontrado.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}
error_log("✓ Profissional encontrado: " . $professional['name']);

// Buscar dados da especialidade e serviço selecionados
error_log("Buscando especialidade ID: $specialtyId");
$stmt = $db->prepare("SELECT name FROM specialties WHERE id = :id");
$stmt->execute(['id' => $specialtyId]);
$specialtyData = $stmt->fetch();
$specialtyName = $specialtyData ? (string)$specialtyData['name'] : '';

if (!$specialtyName) {
    error_log("ERRO: Especialidade não encontrada - ID: $specialtyId");
}
error_log("✓ Especialidade: $specialtyName");

error_log("Buscando serviço ID: $specialtyServiceId");
$stmt = $db->prepare("SELECT service_name, base_value FROM specialty_services WHERE id = :id");
$stmt->execute(['id' => $specialtyServiceId]);
$serviceData = $stmt->fetch();
$serviceName = $serviceData ? (string)$serviceData['service_name'] : '';

if (!$serviceName) {
    error_log("ERRO: Serviço não encontrado - ID: $specialtyServiceId");
}
error_log("✓ Serviço: $serviceName");

// Buscar dados adicionais do profissional (registro)
$stmt = $db->prepare("SELECT council_number, council_state FROM professional_applications WHERE created_user_id = :uid AND status = 'approved' LIMIT 1");
$stmt->execute(['uid' => $professionalUserId]);
$profDetails = $stmt->fetch();

$professionalCouncil = $profDetails ? (string)$profDetails['council_number'] : '';
$professionalCouncilState = $profDetails ? (string)$profDetails['council_state'] : '';

// Preparar detalhes de frequência (JSON)
$frequencyDetailsJson = json_encode([
    'type' => $frequency,
    'description' => $frequencyDetails,
    'sessions_per_week' => $sessionsPerWeek,
    'duration_weeks' => $durationWeeks,
    'total_sessions' => $totalSessions
]);

$userId = auth_user_id();

// ============================================================================
// CALCULAR DATAS DAS SESSÕES
// ============================================================================
function calculateSessionDates($startDate, $startTime, $endTime, $frequency, $sessionsPerWeek, $totalSessions) {
    $dates = [];
    $currentDate = new DateTime($startDate);
    $sessionsAdded = 0;
    $weekCounter = 0;
    
    while ($sessionsAdded < $totalSessions) {
        $weekStart = clone $currentDate;
        $weekStart->modify('+' . $weekCounter . ' weeks');
        
        // Adicionar sessões para esta semana
        $sessionsThisWeek = min($sessionsPerWeek, $totalSessions - $sessionsAdded);
        
        for ($i = 0; $i < $sessionsThisWeek; $i++) {
            $sessionDate = clone $weekStart;
            
            // Distribuir sessões ao longo da semana
            if ($sessionsPerWeek > 1) {
                // Calcular intervalo entre sessões (em dias)
                $interval = floor(7 / $sessionsPerWeek);
                $sessionDate->modify('+' . ($i * $interval) . ' days');
            }
            
            // Pular finais de semana se necessário
            while ($sessionDate->format('N') >= 6) { // 6=sábado, 7=domingo
                $sessionDate->modify('+1 day');
            }
            
            $dates[] = [
                'date' => $sessionDate->format('Y-m-d'),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'formatted' => $sessionDate->format('d/m/Y') . ' às ' . substr($startTime, 0, 5)
            ];
            
            $sessionsAdded++;
            if ($sessionsAdded >= $totalSessions) break;
        }
        
        $weekCounter++;
        
        // Proteção contra loop infinito
        if ($weekCounter > 100) break;
    }
    
    return $dates;
}

$sessionDates = calculateSessionDates($startDate, $startTime, $endTime, $frequency, $sessionsPerWeek, $totalSessions);

// ============================================================================
// CRIAR REGISTROS NO BANCO
// ============================================================================
error_log("=== INICIANDO TRANSAÇÃO ===");

$db->beginTransaction();
try {
    // Criar solicitação de autorização
    error_log("Criando authorization_request...");
    $stmt = $db->prepare(
        'INSERT INTO authorization_requests 
        (demand_id, professional_user_id, proposal_value, agreed_value, 
         start_date, start_time, end_time, frequency, frequency_details, 
         sessions_per_week, total_sessions, duration_weeks, operator_email, operator_name,
         status, created_by_user_id) 
        VALUES 
        (:demand_id, :professional_user_id, :proposal_value, :agreed_value,
         :start_date, :start_time, :end_time, :frequency, :frequency_details,
         :sessions_per_week, :total_sessions, :duration_weeks, :operator_email, :operator_name,
         :status, :created_by_user_id)'
    );
    
    $operatorName = explode('@', $operatorEmail)[0];
    
    $stmt->execute([
        'demand_id' => $demandId,
        'professional_user_id' => $professionalUserId,
        'proposal_value' => $proposalValue,
        'agreed_value' => $agreedValue,
        'start_date' => $startDate,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'frequency' => $frequency,
        'frequency_details' => $frequencyDetailsJson,
        'sessions_per_week' => $sessionsPerWeek,
        'total_sessions' => $totalSessions,
        'duration_weeks' => $durationWeeks,
        'operator_email' => $operatorEmail,
        'operator_name' => $operatorName,
        'status' => 'aguardando_autorizacao',
        'created_by_user_id' => $userId
    ]);
    
    $authRequestId = (int)$db->lastInsertId();
    error_log("✓ Authorization request criado - ID: $authRequestId");
    
    // Atualizar status da demanda
    error_log("Atualizando status da demanda...");
    $stmt = $db->prepare('UPDATE demands SET status = :status WHERE id = :id');
    $stmt->execute(['status' => 'aguardando_autorizacao', 'id' => $demandId]);
    error_log("✓ Status da demanda atualizado");
    
    // Registrar log de status da demanda
    error_log("Registrando log de status...");
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
    error_log("✓ Log de status registrado");
    
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
    error_log("✓ Histórico de autorização registrado");
    
    error_log("=== COMMIT DA TRANSAÇÃO ===");
    $db->commit();
    error_log("✓ Transação commitada com sucesso");
    
    // ============================================================================
    // ENVIAR E-MAIL PARA OPERADORA
    // ============================================================================
    error_log("=== PREPARANDO E-MAIL ===");
    
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
    
    $emailSubject = "Proposta de Atendimento - {$patient['full_name']} - {$specialtyName}";
    
    $emailBody = "Prezado(a),\n\n";
    $emailBody .= "Segue proposta de atendimento domiciliar:\n\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "DADOS DO PACIENTE\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "Nome: {$patient['full_name']}\n";
    if (!empty($patient['email'])) $emailBody .= "E-mail: {$patient['email']}\n";
    $patientPhone = $patient['whatsapp'] ?? $patient['phone_primary'] ?? '';
    if (!empty($patientPhone)) $emailBody .= "Telefone: {$patientPhone}\n";
    if (!empty($location)) $emailBody .= "Localização: {$location}\n";
    $emailBody .= "\n";
    
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "PROFISSIONAL DESIGNADO\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "Nome: {$professional['name']}\n";
    $emailBody .= "Especialidade: {$specialtyName}\n";
    $emailBody .= "Serviço: {$serviceName}\n";
    if (!empty($professionalCouncil)) {
        $emailBody .= "Registro: {$professionalCouncil}";
        if (!empty($professionalCouncilState)) $emailBody .= "/{$professionalCouncilState}";
        $emailBody .= "\n";
    }
    if (!empty($professional['email'])) $emailBody .= "E-mail: {$professional['email']}\n";
    if (!empty($professional['phone'])) $emailBody .= "Telefone: {$professional['phone']}\n";
    $emailBody .= "\n";
    
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "AGENDAMENTO PROPOSTO\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "Data de Início: " . date('d/m/Y', strtotime($startDate)) . "\n";
    $emailBody .= "Horário: " . substr($startTime, 0, 5) . " às " . substr($endTime, 0, 5) . "\n";
    $emailBody .= "Frequência: {$frequencyText}\n";
    $emailBody .= "Sessões por Semana: {$sessionsPerWeek}x\n";
    $emailBody .= "Duração: {$durationWeeks} semanas\n";
    $emailBody .= "Total de Sessões: {$totalSessions}\n";
    $emailBody .= "\n";
    
    // Adicionar cronograma de sessões
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    $emailBody .= "CRONOGRAMA DE SESSÕES PREVISTAS\n";
    $emailBody .= "═══════════════════════════════════════════════════════\n";
    
    foreach ($sessionDates as $index => $session) {
        $sessionNumber = $index + 1;
        $emailBody .= "Sessão {$sessionNumber}: {$session['formatted']}\n";
    }
    $emailBody .= "\n";
    
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
    error_log("Destinatário: $operatorEmail");
    error_log("Assunto: $emailSubject");
    
    try {
        error_log("Iniciando envio de e-mail...");
        $smtp = new SmtpClient();
        $fromEmail = admin_setting_get('smtp.out.from_email', 'noreply@multilife.com.br');
        $fromName = admin_setting_get('smtp.out.from_name', 'MultiLife Care');
        
        error_log("From: $fromEmail ($fromName)");
        error_log("To: $operatorEmail");
        
        $smtp->send($fromEmail, $fromName, $operatorEmail, $emailSubject, $emailBody);
        error_log("✓ E-mail enviado com sucesso");
        
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
        error_log('❌ ERRO AO ENVIAR E-MAIL: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        flash_set('warning', 'Solicitação criada, mas houve erro ao enviar e-mail: ' . $e->getMessage());
        header('Location: /authorization_list.php?status=aguardando_autorizacao');
        exit;
    }
    
} catch (Exception $e) {
    error_log('❌ ERRO FATAL NA TRANSAÇÃO: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    error_log('Fazendo rollback...');
    $db->rollBack();
    error_log('✓ Rollback executado');
    flash_set('error', 'Erro ao criar solicitação: ' . $e->getMessage());
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}

error_log("=== CHAT_SELECT_PROFESSIONAL_POST - FIM ===");
