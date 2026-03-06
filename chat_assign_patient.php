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
$patientIdInput = $input['patient_id'] ?? '';
$professionalJid = $input['professional_jid'] ?? '';
$specialty = $input['specialty'] ?? '';
$serviceType = $input['service_type'] ?? '';
$sessionQuantity = isset($input['session_quantity']) ? (int)$input['session_quantity'] : 1;
$sessionFrequency = $input['session_frequency'] ?? '';
$paymentValue = isset($input['payment_value']) ? (float)$input['payment_value'] : 0.0;
$notes = $input['notes'] ?? '';

// Dados de novo paciente (se aplicável)
$newPatientName = $input['new_patient_name'] ?? '';
$newPatientPhone = $input['new_patient_phone'] ?? '';
$newPatientEmail = $input['new_patient_email'] ?? '';
$newPatientCpf = $input['new_patient_cpf'] ?? '';
$newPatientBirthdate = $input['new_patient_birthdate'] ?? '';
$newPatientAddress = $input['new_patient_address'] ?? '';

// Validações
if ($demandId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Card de captação inválido']);
    exit;
}

$patientId = 0;

// Se selecionou "Cadastrar Novo Paciente"
if ($patientIdInput === 'new') {
    if (empty($newPatientName) || empty($newPatientPhone)) {
        echo json_encode(['success' => false, 'error' => 'Nome e telefone do paciente são obrigatórios']);
        exit;
    }
    
    try {
        $db = db();
        
        // Criar novo paciente
        $insertPatientStmt = $db->prepare("
            INSERT INTO users (name, phone, email, cpf, birthdate, address, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $insertPatientStmt->execute([
            $newPatientName,
            $newPatientPhone,
            $newPatientEmail ?: null,
            $newPatientCpf ?: null,
            $newPatientBirthdate ?: null,
            $newPatientAddress ?: null
        ]);
        
        $patientId = (int)$db->lastInsertId();
        
        // Atribuir role 'paciente'
        $roleStmt = $db->prepare("SELECT id FROM roles WHERE slug = 'paciente' LIMIT 1");
        $roleStmt->execute();
        $roleId = $roleStmt->fetchColumn();
        
        if ($roleId) {
            $insertRoleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            $insertRoleStmt->execute([$patientId, $roleId]);
        }
        
        error_log("[ASSIGN_PATIENT] Novo paciente criado - ID: $patientId, Nome: $newPatientName");
        
    } catch (Exception $e) {
        error_log("Erro ao criar novo paciente: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erro ao criar paciente: ' . $e->getMessage()]);
        exit;
    }
} else {
    $patientId = (int)$patientIdInput;
}

if ($patientId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Paciente não selecionado ou não criado']);
    exit;
}

if (empty($professionalJid)) {
    echo json_encode(['success' => false, 'error' => 'Profissional inválido']);
    exit;
}

if (empty($specialty) || empty($serviceType) || empty($sessionFrequency)) {
    echo json_encode(['success' => false, 'error' => 'Preencha todos os campos obrigatórios']);
    exit;
}

if ($paymentValue <= 0) {
    echo json_encode(['success' => false, 'error' => 'Valor de pagamento inválido']);
    exit;
}

try {
    $db = db();
    
    // Verificar se demand existe e pertence ao usuário logado
    $demandStmt = $db->prepare("SELECT id, title, specialty, location_city, location_state FROM demands WHERE id = ? AND assumed_by_user_id = ?");
    $demandStmt->execute([$demandId, auth_user_id()]);
    $demand = $demandStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$demand) {
        echo json_encode(['success' => false, 'error' => 'Card de captação não encontrado ou não pertence a você']);
        exit;
    }
    
    // Verificar se paciente existe
    $patientStmt = $db->prepare("SELECT id, name, phone FROM users WHERE id = ? AND status = 'active'");
    $patientStmt->execute([$patientId]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'error' => 'Paciente não encontrado']);
        exit;
    }
    
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
    
    // Inserir atribuição
    $insertStmt = $db->prepare("
        INSERT INTO patient_assignments (
            demand_id, patient_id, professional_remote_jid, professional_user_id,
            assigned_by_user_id, specialty, service_type, session_quantity,
            session_frequency, payment_value, notes, status, confirmed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', NOW())
    ");
    
    $insertStmt->execute([
        $demandId,
        $patientId,
        $professionalJid,
        $professionalUserId,
        auth_user_id(),
        $specialty,
        $serviceType,
        $sessionQuantity,
        $sessionFrequency,
        $paymentValue,
        $notes
    ]);
    
    $assignmentId = (int)$db->lastInsertId();
    
    // Buscar mensagem padrão das configurações
    $settingStmt = $db->prepare("SELECT setting_value FROM operational_settings WHERE setting_key = 'assignment_message_template'");
    $settingStmt->execute();
    $messageTemplate = $settingStmt->fetchColumn();
    
    if (!$messageTemplate) {
        $messageTemplate = "Olá! 👋\n\nTemos uma ótima notícia! Um novo paciente foi atribuído para você.\n\n📋 *Informações do Atendimento:*\n• Paciente: {patient_name}\n• Especialidade: {specialty}\n• Serviço: {service_type}\n• Quantidade de sessões: {session_quantity}\n• Frequência: {session_frequency}\n• Valor por sessão: R$ {payment_value}\n\nPor favor, entre em contato com o paciente o mais breve possível para agendar a primeira sessão.\n\nEm caso de dúvidas, estamos à disposição!\n\nAtenciosamente,\nEquipe MultiLife";
    }
    
    // Substituir variáveis na mensagem
    $message = str_replace(
        ['{patient_name}', '{specialty}', '{service_type}', '{session_quantity}', '{session_frequency}', '{payment_value}'],
        [$patient['name'], $specialty, $serviceType, $sessionQuantity, $sessionFrequency, number_format($paymentValue, 2, ',', '.')],
        $messageTemplate
    );
    
    // Enviar mensagem via Evolution API
    try {
        require_once __DIR__ . '/app/EvolutionApiV1.php';
        $api = new EvolutionApiV1();
        $result = $api->sendText($professionalJid, $message);
        
        if (!isset($result['status']) || (int)$result['status'] < 200 || (int)$result['status'] >= 300) {
            error_log("Erro ao enviar mensagem de atribuição: " . json_encode($result));
        }
    } catch (Exception $e) {
        error_log("Erro ao enviar mensagem via Evolution API: " . $e->getMessage());
    }
    
    // Registrar no prontuário do paciente
    try {
        $patientRecordStmt = $db->prepare("
            INSERT INTO medical_records (patient_id, record_type, record_date, description, created_by_user_id)
            VALUES (?, 'assignment', NOW(), ?, ?)
        ");
        $recordDescription = "Atribuído ao profissional: {$professionalName}\nEspecialidade: {$specialty}\nServiço: {$serviceType}\nSessões: {$sessionQuantity}x ({$sessionFrequency})\nValor: R$ " . number_format($paymentValue, 2, ',', '.');
        if ($notes) {
            $recordDescription .= "\nObservações: {$notes}";
        }
        $patientRecordStmt->execute([$patientId, $recordDescription, auth_user_id()]);
    } catch (Exception $e) {
        error_log("Erro ao registrar no prontuário do paciente: " . $e->getMessage());
    }
    
    // Registrar no prontuário do profissional (se existir)
    if ($professionalUserId) {
        try {
            $profRecordStmt = $db->prepare("
                INSERT INTO medical_records (patient_id, record_type, record_date, description, created_by_user_id)
                VALUES (?, 'assignment', NOW(), ?, ?)
            ");
            $profRecordDescription = "Paciente atribuído: {$patient['name']}\nEspecialidade: {$specialty}\nServiço: {$serviceType}\nSessões: {$sessionQuantity}x ({$sessionFrequency})\nValor: R$ " . number_format($paymentValue, 2, ',', '.');
            if ($notes) {
                $profRecordDescription .= "\nObservações: {$notes}";
            }
            $profRecordStmt->execute([$professionalUserId, $profRecordDescription, auth_user_id()]);
        } catch (Exception $e) {
            error_log("Erro ao registrar no prontuário do profissional: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'assignment_id' => $assignmentId,
        'message' => 'Paciente atribuído com sucesso'
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao processar atribuição: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao processar atribuição: ' . $e->getMessage()]);
}
