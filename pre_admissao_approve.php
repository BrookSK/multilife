<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
$demandId = isset($_POST['demand_id']) ? (int)$_POST['demand_id'] : 0;
$weekdaysRaw = trim((string)($_POST['weekdays'] ?? ''));

if ($assignmentId <= 0 || $demandId <= 0) {
    flash_set('error', 'Dados inválidos.');
    header('Location: /pre_admissao.php');
    exit;
}

$db = db();

try {
    $db->beginTransaction();
    
    // Verificar se atribuição existe e está confirmada
    $assignmentStmt = $db->prepare("
        SELECT pa.id, pa.patient_id, pa.professional_user_id, pa.specialty, pa.service_type, 
               pa.session_quantity, pa.session_frequency, 
               COALESCE(pa.agreed_value, pa.payment_value) as payment_value,
               pa.agreed_value, pa.authorized_value,
               p.full_name as patient_name, u.name as professional_name
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN users u ON u.id = pa.professional_user_id
        WHERE pa.id = ? AND pa.demand_id = ? AND pa.status = 'confirmed'
    ");
    $assignmentStmt->execute([$assignmentId, $demandId]);
    $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assignment) {
        throw new Exception('Atribuição não encontrada ou já processada.');
    }
    
    // Atualizar status da atribuição para 'admitted' (aguardando documentos)
    $updateAssignmentStmt = $db->prepare("
        UPDATE patient_assignments 
        SET status = 'admitted', approved_at = NOW(), approved_by_user_id = ?, admitted_at = NOW(), weekdays = ?
        WHERE id = ?
    ");
    $weekdaysJson = null;
    if ($weekdaysRaw !== '') {
        $weekdaysArr = array_filter(array_map('intval', explode(',', $weekdaysRaw)));
        if (count($weekdaysArr) > 0) {
            sort($weekdaysArr);
            $weekdaysJson = json_encode(array_values($weekdaysArr));
        }
    }
    $updateAssignmentStmt->execute([auth_user_id(), $weekdaysJson, $assignmentId]);
    
    // Atualizar status do card de captação para 'admitido'
    $updateDemandStmt = $db->prepare("
        UPDATE demands 
        SET status = 'admitido', updated_at = NOW()
        WHERE id = ?
    ");
    $updateDemandStmt->execute([$demandId]);
    
    // Criar pendências de documentos para cada sessão COM datas calculadas
    $createRequirementsStmt = $db->prepare("
        INSERT INTO billing_document_requirements (
            assignment_id,
            patient_id,
            professional_user_id,
            session_number,
            session_date,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    // Calcular datas das sessões baseado nos dias da semana selecionados
    $sessionDates = [];
    $totalSessions = (int)$assignment['session_quantity'];
    $startDate = new DateTime(); // Hoje (data da aprovação)
    
    if ($weekdaysJson !== null) {
        $weekdays = json_decode($weekdaysJson, true);
        if (is_array($weekdays) && count($weekdays) > 0) {
            // Gerar datas baseado nos dias da semana selecionados
            $currentDate = clone $startDate;
            while (count($sessionDates) < $totalSessions) {
                // PHP: 1=Monday ... 7=Sunday (ISO 8601, mesmo formato que usamos)
                $dayOfWeek = (int)$currentDate->format('N');
                if (in_array($dayOfWeek, $weekdays, true)) {
                    $sessionDates[] = $currentDate->format('Y-m-d');
                }
                $currentDate->modify('+1 day');
                // Proteção contra loop infinito
                if (count($sessionDates) === 0 && $currentDate->diff($startDate)->days > 14) {
                    break;
                }
                if (count($sessionDates) > 0 && $currentDate->diff($startDate)->days > 365) {
                    break;
                }
            }
        }
    }
    
    // Se não conseguiu calcular datas (sem weekdays), usar fallback semanal
    if (count($sessionDates) === 0) {
        for ($i = 0; $i < $totalSessions; $i++) {
            $date = clone $startDate;
            $date->modify('+' . ($i * 7) . ' days');
            $sessionDates[] = $date->format('Y-m-d');
        }
    }
    
    for ($i = 0; $i < $totalSessions; $i++) {
        $sessionDate = isset($sessionDates[$i]) ? $sessionDates[$i] : null;
        $createRequirementsStmt->execute([
            $assignmentId,
            $assignment['patient_id'],
            $assignment['professional_user_id'],
            $i + 1,
            $sessionDate
        ]);
    }
    
    error_log("DEBUG APROVAÇÃO: Criadas " . $assignment['session_quantity'] . " pendências de documentos");
    
    // Registrar no prontuário do paciente (usando tabela existente)
    $currentUser = auth_user();
    $approvedByName = $currentUser ? $currentUser['name'] : 'Sistema';
    
    $recordNotes = "✅ ATENDIMENTO APROVADO\n\n";
    $recordNotes .= "Profissional: " . ($assignment['professional_name'] ?? 'Não informado') . "\n";
    $recordNotes .= "Especialidade: " . $assignment['specialty'] . "\n";
    $recordNotes .= "Tipo de Serviço: " . $assignment['service_type'] . "\n";
    $recordNotes .= "Quantidade de Sessões: " . $assignment['session_quantity'] . "x\n";
    $recordNotes .= "Frequência: " . $assignment['session_frequency'] . "\n";
    $recordNotes .= "Valor por Sessão: R$ " . number_format((float)$assignment['payment_value'], 2, ',', '.') . "\n";
    if (!empty($assignment['notes'])) {
        $recordNotes .= "\nObservações: " . $assignment['notes'] . "\n";
    }
    $recordNotes .= "\nAprovado por: " . $approvedByName . "\n";
    $recordNotes .= "Data de Aprovação: " . date('d/m/Y H:i:s');
    
    error_log("DEBUG APROVAÇÃO: Registrando no prontuário - patient_id: {$assignment['patient_id']}, professional_user_id: {$assignment['professional_user_id']}, sessions: {$assignment['session_quantity']}");
    
    $prontuarioStmt = $db->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, sessions_count, notes)
        VALUES (?, ?, 'pre_admissao_aprovacao', NOW(), ?, ?)
    ");
    $prontuarioStmt->execute([
        $assignment['patient_id'],
        $assignment['professional_user_id'],
        $assignment['session_quantity'],
        $recordNotes
    ]);
    
    error_log("DEBUG APROVAÇÃO: Prontuário registrado com sucesso! ID: " . $db->lastInsertId());
    
    // Log de auditoria
    audit_log('update', 'patient_assignments', (string)$assignmentId, 
        ['status' => 'confirmed'], 
        ['status' => 'approved']
    );
    
    audit_log('update', 'demands', (string)$demandId, 
        ['status' => 'old'], 
        ['status' => 'admitido']
    );
    
    $db->commit();
    
    // Disparar evento WhatsApp preadmission_approved
    try {
        $profStmt = db()->prepare('SELECT phone FROM users WHERE id = :id');
        $profStmt->execute(['id' => $assignment['professional_user_id']]);
        $profPhone = preg_replace('/\D+/', '', (string)($profStmt->fetchColumn() ?: ''));
        
        $patStmt = db()->prepare('SELECT whatsapp, phone_primary FROM patients WHERE id = :id');
        $patStmt->execute(['id' => $assignment['patient_id']]);
        $patData = $patStmt->fetch();
        $patPhone = preg_replace('/\D+/', '', (string)($patData['whatsapp'] ?? $patData['phone_primary'] ?? ''));
        
        $dispatcher = new WhatsAppEventDispatcher();
        $dispatcher->dispatch('preadmission_approved', [
            'professional_id' => (int)$assignment['professional_user_id'],
            'professional_name' => $assignment['professional_name'] ?? '',
            'professional_phone' => $profPhone,
            'patient_id' => (int)$assignment['patient_id'],
            'patient_name' => $assignment['patient_name'] ?? '',
            'patient_phone' => $patPhone,
            'attendance_id' => (string)$assignmentId,
            'attendance_date' => date('d/m/Y'),
            'id_preadmissao' => (string)$assignmentId,
            'data_aprovacao' => date('d/m/Y H:i'),
        ]);
    } catch (Throwable $evtErr) {
        error_log('[PRE_ADMISSAO_APPROVE] Erro ao disparar evento: ' . $evtErr->getMessage());
    }
    
    flash_set('success', 'Atendimento aprovado com sucesso! O card foi movido para "Admitido" na Captação.');
    header('Location: /pre_admissao.php');
    exit;
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Erro ao aprovar atendimento: " . $e->getMessage());
    flash_set('error', 'Erro ao aprovar atendimento: ' . $e->getMessage());
    header('Location: /pre_admissao.php?id=' . $assignmentId);
    exit;
}
