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
// VALIDAÇÃO CRÍTICA: PATIENT_ID
// ============================================================================
error_log("=== VALIDAÇÃO CRÍTICA: PATIENT_ID ===");
error_log("Patient ID recebido do formulário: " . var_export($patientId, true));
error_log("Tipo do Patient ID: " . gettype($patientId));

if ($patientId <= 0) {
    error_log("❌ ERRO CRÍTICO: Patient ID inválido ou não informado");
    error_log("Valor recebido: $patientId");
    flash_set('error', 'Paciente não selecionado. Por favor, selecione um paciente válido.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}

error_log("✓ Patient ID validado: $patientId (tipo: " . gettype($patientId) . ")");

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
    error_log("❌ ERRO: Demanda não encontrada - ID: $demandId");
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}
error_log("✓ Demanda encontrada: " . $demand['title'] . " (Status: " . $demand['status'] . ")");

// Buscar dados do paciente (VALIDAÇÃO CRÍTICA)
error_log("=== VALIDAÇÃO CRÍTICA: VERIFICANDO SE PACIENTE EXISTE NO BANCO ===");
error_log("Buscando paciente ID: $patientId");
$stmt = $db->prepare('SELECT id, full_name, email, phone_primary, whatsapp FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    error_log("❌ ERRO CRÍTICO: Paciente #$patientId NÃO EXISTE no banco de dados");
    error_log("Query executada: SELECT id, full_name FROM patients WHERE id = $patientId AND deleted_at IS NULL");
    error_log("Resultado: NULL (paciente não encontrado ou foi deletado)");
    flash_set('error', 'Paciente não encontrado no sistema. Por favor, verifique se o paciente está cadastrado.');
    header('Location: /chat_web.php?chat=' . urlencode($chatJid));
    exit;
}
error_log("✓ Paciente VALIDADO e ENCONTRADO no banco: " . $patient['full_name'] . " (ID: " . $patient['id'] . ")");

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
        (demand_id, patient_id, professional_user_id, proposal_value, agreed_value, 
         start_date, start_time, end_time, frequency, frequency_details, 
         sessions_per_week, total_sessions, duration_weeks, operator_email, operator_name,
         status, created_by_user_id) 
        VALUES 
        (:demand_id, :patient_id, :professional_user_id, :proposal_value, :agreed_value,
         :start_date, :start_time, :end_time, :frequency, :frequency_details,
         :sessions_per_week, :total_sessions, :duration_weeks, :operator_email, :operator_name,
         :status, :created_by_user_id)'
    );
    
    $operatorName = explode('@', $operatorEmail)[0];
    
    error_log("=== SALVANDO AUTHORIZATION_REQUEST ===");
    error_log("Dados a serem salvos:");
    error_log("  - demand_id: $demandId");
    error_log("  - patient_id: $patientId (CRÍTICO)");
    error_log("  - professional_user_id: $professionalUserId");
    error_log("  - proposal_value: $proposalValue");
    error_log("  - agreed_value: $agreedValue");
    
    $stmt->execute([
        'demand_id' => $demandId,
        'patient_id' => $patientId,
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
    error_log("✓ Patient ID salvo na autorização: $patientId");
    
    // Verificar se patient_id foi realmente salvo
    $verifyStmt = $db->prepare("SELECT patient_id FROM authorization_requests WHERE id = :id");
    $verifyStmt->execute(['id' => $authRequestId]);
    $verifyResult = $verifyStmt->fetch();
    $savedPatientId = $verifyResult ? (int)$verifyResult['patient_id'] : 0;
    
    if ($savedPatientId === $patientId) {
        error_log("✓✓✓ CONFIRMADO: Patient ID $patientId foi salvo corretamente na autorização #$authRequestId");
    } else {
        error_log("❌❌❌ ERRO: Patient ID NÃO foi salvo corretamente!");
        error_log("Esperado: $patientId | Salvo: $savedPatientId");
        throw new Exception("Erro ao salvar patient_id na autorização");
    }
    
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
    
    // Disparar evento WhatsApp para notificar profissional
    try {
        $dispatcher = new WhatsAppEventDispatcher();
        $dispatcher->dispatch('attendance_assigned', [
            'professional_id' => $professionalUserId,
            'professional_name' => $professional['name'] ?? '',
            'professional_phone' => preg_replace('/\D+/', '', (string)($professional['phone'] ?? '')),
            'patient_id' => $patientId,
            'patient_name' => $patient['full_name'] ?? '',
            'patient_phone' => preg_replace('/\D+/', '', (string)($patient['whatsapp'] ?? $patient['phone_primary'] ?? '')),
            'attendance_id' => (string)$authRequestId,
            'attendance_date' => date('d/m/Y'),
        ]);
        error_log("✓ Evento attendance_assigned disparado");
    } catch (Throwable $evtErr) {
        error_log('Erro ao disparar evento attendance_assigned: ' . $evtErr->getMessage());
    }
    
    // ============================================================================
    // ENVIAR E-MAIL PARA OPERADORA COM TEMPLATE HTML
    // ============================================================================
    error_log("=== PREPARANDO E-MAIL COM TEMPLATE ===");
    
    require_once __DIR__ . '/app/email_template_processor.php';
    
    // Preparar variáveis para o template
    $totalProposal = $proposalValue * $totalSessions;
    $location = trim(($demand['location_city'] ?? '') . '/' . ($demand['location_state'] ?? ''));
    $patientPhone = $patient['whatsapp'] ?? $patient['phone_primary'] ?? '';
    
    $frequencyText = match($frequency) {
        'single' => 'Atendimento único',
        'daily' => 'Diário',
        'weekly' => 'Semanal',
        'biweekly' => 'Quinzenal',
        'monthly' => 'Mensal',
        'custom' => 'Personalizado',
        default => 'Semanal'
    };
    
    $professionalCouncilFull = '';
    if (!empty($professionalCouncil)) {
        $professionalCouncilFull = $professionalCouncil;
        if (!empty($professionalCouncilState)) {
            $professionalCouncilFull .= '/' . $professionalCouncilState;
        }
    }
    
    // Gerar cronograma de sessões em HTML
    $sessionScheduleHtml = email_generate_session_schedule($sessionDates);
    
    // Gerar seção de observações em HTML
    $notesSection = email_generate_notes_section($notes);
    
    $variables = [
        'patient_name' => $patient['full_name'],
        'patient_email' => $patient['email'] ?? '',
        'patient_phone' => $patientPhone,
        'professional_name' => $professional['name'],
        'professional_email' => $professional['email'] ?? '',
        'professional_phone' => $professional['phone'] ?? '',
        'professional_council' => $professionalCouncilFull,
        'specialty' => $specialtyName,
        'service_name' => $serviceName,
        'location' => $location,
        'start_date' => email_format_date($startDate),
        'start_time' => email_format_time($startTime),
        'end_time' => email_format_time($endTime),
        'frequency_text' => $frequencyText,
        'sessions_per_week' => (string)$sessionsPerWeek,
        'duration_weeks' => (string)$durationWeeks,
        'total_sessions' => (string)$totalSessions,
        'value_per_session' => email_format_currency($proposalValue),
        'total_value' => email_format_currency($totalProposal),
        'session_schedule' => $sessionScheduleHtml,
        'notes_section' => $notesSection,
    ];
    
    error_log("Destinatário: $operatorEmail");
    error_log("Usando template HTML para envio de proposta");
    
    try {
        error_log("Iniciando envio de e-mail com template...");
        
        // Buscar operadora do paciente para template específico
        $stmt = $db->prepare('SELECT health_insurer_id FROM patients WHERE id = :pid');
        $stmt->execute(['pid' => $patientId]);
        $patientData = $stmt->fetch();
        $healthInsurerId = $patientData ? (int)$patientData['health_insurer_id'] : null;
        
        // Enviar com template
        $emailResult = email_send_with_template($operatorEmail, 'proposal_send', $variables, $healthInsurerId);
        
        if (!$emailResult['success']) {
            throw new Exception($emailResult['message']);
        }
        
        $messageId = $emailResult['message_id'] ?? '';
        error_log("✓ E-mail enviado com sucesso usando template #" . $emailResult['template_id']);
        error_log("✓ Message-ID: $messageId");
        
        // Atualizar registro com data de envio, prazo de resposta e Message-ID para rastreamento
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
