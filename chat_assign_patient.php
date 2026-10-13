<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$demandId = isset($input['demand_id']) ? (int)$input['demand_id'] : 0;
$patientId = isset($input['patient_id']) ? (int)$input['patient_id'] : 0;
$professionalJid = $input['professional_jid'] ?? '';
$specialtyId = isset($input['specialty_id']) ? (int)$input['specialty_id'] : 0;
$specialty = $input['specialty'] ?? '';
$serviceTypeId = isset($input['service_type_id']) ? (int)$input['service_type_id'] : 0;
$sessionQuantity = isset($input['session_quantity']) ? (int)$input['session_quantity'] : 1;
$sessionFrequency = $input['session_frequency'] ?? '';
$agreedValue = isset($input['agreed_value']) ? (float)$input['agreed_value'] : 0.0;
$authorizedValue = isset($input['authorized_value']) ? (float)$input['authorized_value'] : 0.0;
$healthInsurerId = isset($input['health_insurer_id']) ? (int)$input['health_insurer_id'] : null;
$notes = $input['notes'] ?? '';

// Validações
if ($demandId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Card de captação inválido']);
    exit;
}

if ($patientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Paciente não selecionado']);
    exit;
}

if (empty($professionalJid)) {
    echo json_encode(['success' => false, 'error' => 'Profissional inválido']);
    exit;
}

if (empty($specialty) || $serviceTypeId <= 0 || empty($sessionFrequency)) {
    echo json_encode(['success' => false, 'error' => 'Preencha todos os campos obrigatórios']);
    exit;
}

if ($agreedValue <= 0 || $authorizedValue <= 0) {
    echo json_encode(['success' => false, 'error' => 'Valores acordado e autorizado são obrigatórios']);
    exit;
}

$db = db();

// Validar valor mínimo do serviço
if ($serviceTypeId > 0) {
    $serviceStmt = $db->prepare("SELECT service_name, base_value FROM specialty_services WHERE id = ?");
    $serviceStmt->execute([$serviceTypeId]);
    $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($service) {
        $serviceTypeName = $service['service_name'];
        $minValue = (float)$service['base_value'];
        
        if ($agreedValue < $minValue) {
            echo json_encode(['success' => false, 'error' => 'Valor Acordado (R$ ' . number_format($agreedValue, 2, ',', '.') . ') não pode ser menor que o valor mínimo do serviço (R$ ' . number_format($minValue, 2, ',', '.') . ')']);
            exit;
        }
        
        if ($authorizedValue < $minValue) {
            echo json_encode(['success' => false, 'error' => 'Valor Autorizado (R$ ' . number_format($authorizedValue, 2, ',', '.') . ') não pode ser menor que o valor mínimo do serviço (R$ ' . number_format($minValue, 2, ',', '.') . ')']);
            exit;
        }
    } else {
        $serviceTypeName = 'Serviço não encontrado';
    }
} else {
    $serviceTypeName = '';
}

try {
    // Verificar se demand existe e pertence ao usuário logado
    $demandStmt = $db->prepare("SELECT id, title, specialty, location_city, location_state FROM demands WHERE id = ? AND assumed_by_user_id = ?");
    $demandStmt->execute([$demandId, auth_user_id()]);
    $demand = $demandStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$demand) {
        echo json_encode(['success' => false, 'error' => 'Card de captação não encontrado ou não pertence a você']);
        exit;
    }
    
    // Verificar se paciente existe na tabela patients
    $patientStmt = $db->prepare("SELECT id, full_name, phone_primary, phone_secondary, whatsapp,
            address_street, address_number, address_complement, address_neighborhood, address_city, address_state
        FROM patients WHERE id = ? AND deleted_at IS NULL");
    $patientStmt->execute([$patientId]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'error' => 'Paciente não encontrado']);
        exit;
    }
    
    $patientName = $patient['full_name'];

    // Endereço e contatos formatados para o template oficial "novo paciente autorizado".
    $addrParts = array_filter([
        trim((string)($patient['address_street'] ?? '')),
        trim((string)($patient['address_number'] ?? '')),
        trim((string)($patient['address_complement'] ?? '')),
        trim((string)($patient['address_neighborhood'] ?? '')),
        trim((string)($patient['address_city'] ?? '')) . (trim((string)($patient['address_state'] ?? '')) !== '' ? '/' . trim((string)$patient['address_state']) : ''),
    ], fn($p) => $p !== '' && $p !== '/');
    $patientAddress = implode(', ', $addrParts);
    $contactParts = array_filter([
        trim((string)($patient['whatsapp'] ?? '')),
        trim((string)($patient['phone_primary'] ?? '')),
        trim((string)($patient['phone_secondary'] ?? '')),
    ], fn($c) => $c !== '');
    $patientContacts = implode(' / ', array_unique($contactParts));
    $patientPhone = $patient['whatsapp'] ?: $patient['phone_primary'];
    
    // Buscar professional_user_id se existir
    $professionalUserId = null;
    $professionalName = '';
    $phoneNumber = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us)$/', '', $professionalJid);
    
    $profStmt = $db->prepare("
        SELECT id, name FROM users 
        WHERE phone = ? OR phone = ? OR CONCAT('55', phone) = ? OR phone = CONCAT('55', ?)
        LIMIT 1
    ");
    $profStmt->execute([$phoneNumber, ltrim($phoneNumber, '55'), $phoneNumber, ltrim($phoneNumber, '55')]);
    $professional = $profStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($professional) {
        $professionalUserId = (int)$professional['id'];
        $professionalName = $professional['name'];
    } else {
        $professionalName = $phoneNumber;
    }
    
    // Resolver o cliente (contratante) a partir da operadora escolhida.
    $clientId = null;
    if ($healthInsurerId && function_exists('operator_client_id')) {
        $clientId = operator_client_id((int)$healthInsurerId);
    }

    // Inserir atribuição
    $insertStmt = $db->prepare("
        INSERT INTO patient_assignments (
            demand_id, patient_id, professional_remote_jid, professional_user_id,
            assigned_by_user_id, specialty, specialty_service_id, health_insurer_id, client_id,
            session_quantity, session_frequency, agreed_value, authorized_value, notes, status, confirmed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', NOW())
    ");
    
    $insertStmt->execute([
        $demandId,
        $patientId,
        $professionalJid,
        $professionalUserId,
        auth_user_id(),
        $specialty,
        $serviceTypeId,
        $healthInsurerId,
        $clientId,
        $sessionQuantity,
        $sessionFrequency,
        $agreedValue,
        $authorizedValue,
        $notes
    ]);
    
    $assignmentId = (int)$db->lastInsertId();
    
    // Disparar evento WhatsApp attendance_assigned
    try {
        $dispatcher = new WhatsAppEventDispatcher();
        $dispatcher->dispatch('attendance_assigned', [
            'professional_id' => $professionalUserId,
            'professional_name' => $professionalName,
            'professional_phone' => preg_replace('/\D+/', '', $professionalJid),
            'patient_id' => $patientId,
            'patient_name' => $patientName,
            'patient_phone' => '',
            'attendance_id' => (string)$assignmentId,
            'attendance_date' => date('d/m/Y'),
            // Link PÚBLICO por token (sem login). Só inclui se a flag de notificação permitir.
            'attendance_link' => notifications_should_include_portal_link() ? professional_registration_link((int)$professionalUserId) : '',
            'documents_link' => notifications_should_include_portal_link() ? professional_documents_link((int)$professionalUserId) : '',
            'specialty' => $specialty,
            'service_type' => $serviceTypeName ?? '',
            'session_quantity' => (string)$sessionQuantity,
            'session_frequency' => $sessionFrequency,
            'agreed_value' => number_format($agreedValue, 2, ',', '.'),
            'authorized_value' => number_format($authorizedValue, 2, ',', '.'),
            // Campos do template oficial "novo paciente autorizado"
            'patient_address' => $patientAddress,
            'patient_contacts' => $patientContacts,
            'schedule' => $sessionFrequency, // agendamento: usa a frequência acordada como base
        ]);
    } catch (Throwable $evtErr) {
        error_log('[DISPATCH_EVENT] Erro ao disparar attendance_assigned: ' . $evtErr->getMessage());
    }
    // Observação: a mensagem de atribuição ao profissional é enviada exclusivamente pelo
    // evento 'attendance_assigned' (template oficial editável em admin_whatsapp_events_edit.php).
    // A antiga mensagem duplicada (operational_settings.assignment_message_template) foi removida.
    
    // Registrar no prontuário do paciente (usando tabela existente)
    $lucro = $authorizedValue - $agreedValue;
    $recordNotes = "📋 ATENDIMENTO ATRIBUÍDO\n\n";
    $recordNotes .= "Profissional: {$professionalName}\n";
    $recordNotes .= "Especialidade: {$specialty}\n";
    $recordNotes .= "Serviço: {$serviceTypeName}\n";
    $recordNotes .= "Sessões: {$sessionQuantity}x ({$sessionFrequency})\n";
    $recordNotes .= "Valor Acordado: R$ " . number_format($agreedValue, 2, ',', '.') . "\n";
    $recordNotes .= "Valor Autorizado: R$ " . number_format($authorizedValue, 2, ',', '.') . "\n";
    $recordNotes .= "Lucro Real: R$ " . number_format($lucro, 2, ',', '.');
    if ($notes) {
        $recordNotes .= "\n\nObservações: {$notes}";
    }
    
    error_log("DEBUG: Registrando no prontuário - patient_id: {$patientId}, professional_user_id: {$professionalUserId}, sessions: {$sessionQuantity}");
    
    $prontuarioStmt = $db->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, sessions_count, notes)
        VALUES (?, ?, 'atribuicao_captacao', NOW(), ?, ?)
    ");
    $prontuarioStmt->execute([$patientId, $professionalUserId, $sessionQuantity, $recordNotes]);
    
    error_log("DEBUG: Prontuário registrado com sucesso! ID: " . $db->lastInsertId());
    
    echo json_encode([
        'success' => true,
        'assignment_id' => $assignmentId,
        'message' => 'Paciente atribuído com sucesso'
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao processar atribuição: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar atribuição: ' . $e->getMessage()]);
}
