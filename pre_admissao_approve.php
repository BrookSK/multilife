<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
$demandId = isset($_POST['demand_id']) ? (int)$_POST['demand_id'] : 0;

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
               pa.session_quantity, pa.session_frequency, pa.payment_value,
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
    
    // Atualizar status da atribuição para 'approved'
    $updateAssignmentStmt = $db->prepare("
        UPDATE patient_assignments 
        SET status = 'approved', approved_at = NOW(), approved_by_user_id = ?
        WHERE id = ?
    ");
    $updateAssignmentStmt->execute([auth_user_id(), $assignmentId]);
    
    // Atualizar status do card de captação para 'admitido'
    $updateDemandStmt = $db->prepare("
        UPDATE demands 
        SET status = 'admitido', updated_at = NOW()
        WHERE id = ?
    ");
    $updateDemandStmt->execute([$demandId]);
    
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
