<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

$sessionId = (int)($input['session_id'] ?? 0);
$assignmentId = (int)($input['assignment_id'] ?? 0);
$professionalId = (int)($input['professional_id'] ?? 0);
$patientName = trim((string)($input['patient_name'] ?? ''));
$specialty = trim((string)($input['specialty'] ?? ''));
$sessionDate = trim((string)($input['session_date'] ?? ''));

if ($professionalId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Profissional não identificado']);
    exit;
}

$db = db();

// Buscar dados do profissional
$profStmt = $db->prepare("SELECT id, name, email, phone FROM users WHERE id = ? AND status = 'active'");
$profStmt->execute([$professionalId]);
$prof = $profStmt->fetch(PDO::FETCH_ASSOC);

if (!$prof) {
    echo json_encode(['success' => false, 'error' => 'Profissional não encontrado']);
    exit;
}

$profName = $prof['name'] ?? '';
$profEmail = trim((string)($prof['email'] ?? ''));
$profPhone = preg_replace('/\D+/', '', (string)($prof['phone'] ?? ''));

// Buscar dados adicionais do atendimento se necessário
if ($patientName === '' || $specialty === '') {
    $aptStmt = $db->prepare("
        SELECT pa.specialty, pa.service_type, p.full_name as patient_name, 
               p.address_street, p.address_city, p.address_state
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        WHERE pa.id = ?
    ");
    $aptStmt->execute([$assignmentId]);
    $aptData = $aptStmt->fetch(PDO::FETCH_ASSOC);
    if ($aptData) {
        if ($patientName === '') $patientName = $aptData['patient_name'] ?? '';
        if ($specialty === '') $specialty = $aptData['specialty'] ?? '';
    }
}

// Formatar data para exibição
$dateFormatted = $sessionDate;
if ($sessionDate !== '') {
    try {
        $dt = new DateTime($sessionDate);
        $dias = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
        $dateFormatted = $dias[(int)$dt->format('w')] . ', ' . $dt->format('d/m/Y');
    } catch (Exception $e) {}
}

$sent = false;
$methods = [];

// 1. Enviar WhatsApp (se tiver telefone e instância conectada)
if ($profPhone !== '' && strlen($profPhone) >= 10) {
    try {
        if (strlen($profPhone) === 10 || strlen($profPhone) === 11) {
            $profPhone = '55' . $profPhone;
        }
        
        $whatsappMsg = "✅ *Confirmação de Atendimento*\n\n"
            . "Olá, *{$profName}*!\n\n"
            . "Gostaríamos de confirmar seu atendimento agendado:\n\n"
            . "📅 *Data:* {$dateFormatted}\n"
            . "👤 *Paciente:* {$patientName}\n"
            . "🏥 *Especialidade:* {$specialty}\n\n"
            . "Por favor, confirme sua disponibilidade.\n"
            . "Em caso de qualquer imprevisto, avise-nos com antecedência.\n\n"
            . "Obrigado!\n"
            . "_Equipe MultiLife Care_";
        
        $api = new EvolutionApiV1();
        $res = $api->sendText($profPhone, $whatsappMsg);
        $httpCode = (int)($res['status'] ?? 0);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $sent = true;
            $methods[] = 'WhatsApp';
            error_log("[CONFIRM_PROF] WhatsApp enviado para $profPhone");
        } else {
            error_log("[CONFIRM_PROF] Falha WhatsApp: HTTP $httpCode");
        }
    } catch (Throwable $e) {
        error_log("[CONFIRM_PROF] Erro WhatsApp: " . $e->getMessage());
    }
}

// 2. Enviar e-mail (se tiver e-mail válido)
if ($profEmail !== '' && filter_var($profEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        require_once __DIR__ . '/app/email_base_template.php';
        
        $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
        $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
        
        if ($fromEmail !== '') {
            $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($profName) . '</strong>!</p>';
            $body .= '<p style="font-size:14px;color:#4b5563">Gostaríamos de confirmar seu atendimento agendado para hoje:</p>';
            
            $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
            $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Detalhes do Atendimento</h3>';
            $body .= email_data_row('Data', $dateFormatted);
            $body .= email_data_row('Paciente', $patientName);
            $body .= email_data_row('Especialidade', $specialty);
            $body .= '</div>';
            
            $body .= email_divider();
            $body .= '<p style="font-size:14px;color:#374151">Por favor, confirme sua disponibilidade. Em caso de imprevisto, avise-nos com antecedência.</p>';
            $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
            
            $htmlBody = email_base_layout('Confirmação de Atendimento', $body);
            
            $smtp = new SmtpClient();
            $smtp->send($fromEmail, $fromName, $profEmail, 'Confirmação de Atendimento - ' . $patientName . ' - ' . $dateFormatted, $htmlBody);
            
            $sent = true;
            $methods[] = 'E-mail';
            error_log("[CONFIRM_PROF] E-mail enviado para $profEmail");
        }
    } catch (Throwable $e) {
        error_log("[CONFIRM_PROF] Erro e-mail: " . $e->getMessage());
    }
}

if ($sent) {
    // Registrar log de confirmação
    try {
        audit_log('confirm_professional', 'monitoramento', (string)$assignmentId, null, [
            'professional_id' => $professionalId,
            'session_id' => $sessionId,
            'session_date' => $sessionDate,
            'methods' => $methods
        ]);
    } catch (Throwable $e) {}
    
    echo json_encode([
        'success' => true, 
        'message' => 'Confirmação enviada via ' . implode(' e ', $methods)
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'error' => 'Não foi possível enviar a confirmação. Verifique se o profissional tem WhatsApp ou e-mail cadastrado.'
    ]);
}
