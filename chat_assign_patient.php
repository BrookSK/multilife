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
$specialty = $input['specialty'] ?? '';
$serviceType = $input['service_type'] ?? '';
$sessionQuantity = isset($input['session_quantity']) ? (int)$input['session_quantity'] : 1;
$sessionFrequency = $input['session_frequency'] ?? '';
$paymentValue = isset($input['payment_value']) ? (float)$input['payment_value'] : 0.0;
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
    
    // Verificar se paciente existe na tabela patients
    $patientStmt = $db->prepare("SELECT id, full_name, phone_primary, whatsapp FROM patients WHERE id = ? AND deleted_at IS NULL");
    $patientStmt->execute([$patientId]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'error' => 'Paciente não encontrado']);
        exit;
    }
    
    $patientName = $patient['full_name'];
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
        [$patientName, $specialty, $serviceType, $sessionQuantity, $sessionFrequency, number_format($paymentValue, 2, ',', '.')],
        $messageTemplate
    );
    
    // Enviar mensagem via Evolution API
    try {
        require_once __DIR__ . '/app/evolution_api_v1.php';
        $api = new EvolutionApiV1();
        $result = $api->sendText($professionalJid, $message);
        
        if (!isset($result['status']) || (int)$result['status'] < 200 || (int)$result['status'] >= 300) {
            error_log("Erro ao enviar mensagem de atribuição: " . json_encode($result));
        } else {
            // Salvar mensagem enviada no banco de dados local
            try {
                $timestamp = time();
                $saveMessageStmt = $db->prepare("
                    INSERT INTO chat_messages (remote_jid, message_text, from_me, message_timestamp, created_at)
                    VALUES (?, ?, 1, ?, NOW())
                ");
                $saveMessageStmt->execute([$professionalJid, $message, $timestamp]);
                
                // Atualizar last_message_timestamp do contato
                $updateContactStmt = $db->prepare("
                    UPDATE chat_contacts 
                    SET last_message_timestamp = ?, updated_at = NOW()
                    WHERE remote_jid = ?
                ");
                $updateContactStmt->execute([$timestamp, $professionalJid]);
            } catch (Exception $e) {
                error_log("Erro ao salvar mensagem no banco local: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao enviar mensagem via Evolution API: " . $e->getMessage());
    }
    
    // Registrar no prontuário do paciente (usando tabela existente)
    try {
        $recordNotes = "📋 ATENDIMENTO ATRIBUÍDO\n\n";
        $recordNotes .= "Profissional: {$professionalName}\n";
        $recordNotes .= "Especialidade: {$specialty}\n";
        $recordNotes .= "Serviço: {$serviceType}\n";
        $recordNotes .= "Sessões: {$sessionQuantity}x ({$sessionFrequency})\n";
        $recordNotes .= "Valor: R$ " . number_format($paymentValue, 2, ',', '.');
        if ($notes) {
            $recordNotes .= "\n\nObservações: {$notes}";
        }
        
        $prontuarioStmt = $db->prepare("
            INSERT INTO patient_prontuario_entries 
            (patient_id, professional_user_id, origin, occurred_at, sessions_count, notes)
            VALUES (?, ?, 'atribuicao_captacao', NOW(), ?, ?)
        ");
        $prontuarioStmt->execute([$patientId, $professionalUserId, $sessionQuantity, $recordNotes]);
    } catch (Exception $e) {
        error_log("Erro ao registrar no prontuário do paciente: " . $e->getMessage());
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
