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
    
    // Registrar no prontuário do paciente
    $recordDescription = "✅ ATENDIMENTO APROVADO\n\n";
    $recordDescription .= "Profissional: " . ($assignment['professional_name'] ?? 'Não informado') . "\n";
    $recordDescription .= "Especialidade: " . $assignment['specialty'] . "\n";
    $recordDescription .= "Tipo de Serviço: " . $assignment['service_type'] . "\n";
    $recordDescription .= "Quantidade de Sessões: " . $assignment['session_quantity'] . "x\n";
    $recordDescription .= "Frequência: " . $assignment['session_frequency'] . "\n";
    $recordDescription .= "Valor por Sessão: R$ " . number_format((float)$assignment['payment_value'], 2, ',', '.') . "\n";
    $recordDescription .= "\nAprovado por: " . auth_user_name() . "\n";
    $recordDescription .= "Data de Aprovação: " . date('d/m/Y H:i:s');
    
    $patientRecordStmt = $db->prepare("
        INSERT INTO medical_records (patient_id, record_type, record_date, description, created_by_user_id)
        VALUES (?, 'approval', NOW(), ?, ?)
    ");
    $patientRecordStmt->execute([
        $assignment['patient_id'],
        $recordDescription,
        auth_user_id()
    ]);
    
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
