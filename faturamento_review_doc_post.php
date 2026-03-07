<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /faturamento_list.php');
    exit;
}

$requirementId = isset($_POST['requirement_id']) ? (int)$_POST['requirement_id'] : 0;
$decision = isset($_POST['decision']) ? trim((string)$_POST['decision']) : '';
$rejectionReason = isset($_POST['rejection_reason']) ? trim((string)$_POST['rejection_reason']) : '';
$notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
$userId = auth_user_id();

if ($requirementId === 0 || !in_array($decision, ['approve', 'reject'])) {
    $_SESSION['error'] = 'Dados inválidos';
    header('Location: /faturamento_list.php');
    exit;
}

if ($decision === 'reject' && $rejectionReason === '') {
    $_SESSION['error'] = 'Motivo da rejeição é obrigatório';
    header('Location: /faturamento_review_doc.php?id=' . $requirementId);
    exit;
}

// Buscar requisito
$stmt = db()->prepare("
    SELECT bdr.*, pa.patient_id
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    WHERE bdr.id = ?
");
$stmt->execute([$requirementId]);
$requirement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$requirement || $requirement['status'] !== 'uploaded') {
    $_SESSION['error'] = 'Requisito não encontrado ou já foi revisado';
    header('Location: /faturamento_list.php');
    exit;
}

try {
    db()->beginTransaction();
    
    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    
    // Atualizar requisito
    $updateStmt = db()->prepare("
        UPDATE billing_document_requirements
        SET 
            status = ?,
            reviewed_by_user_id = ?,
            reviewed_at = NOW(),
            rejection_reason = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$newStatus, $userId, $rejectionReason, $requirementId]);
    
    if ($decision === 'approve') {
        // Verificar se todos os documentos foram aprovados
        $checkStmt = db()->prepare("
            SELECT COUNT(*) as pending_count
            FROM billing_document_requirements
            WHERE assignment_id = ? AND status IN ('pending', 'uploaded', 'rejected')
        ");
        $checkStmt->execute([$requirement['assignment_id']]);
        $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Se todos os documentos foram aprovados, manter status awaiting_financial_approval
        // (não muda automaticamente, precisa de aprovação manual do financeiro)
    }
    
    // Registrar no prontuário
    $prontuarioStmt = db()->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, notes)
        VALUES (?, ?, 'faturamento_revisao', NOW(), ?)
    ");
    $prontuarioNotes = "Documento de comprovação revisado:\n";
    $prontuarioNotes .= "Sessão: " . (int)$requirement['session_number'] . "\n";
    $prontuarioNotes .= "Decisão: " . ($decision === 'approve' ? 'Aprovado' : 'Rejeitado') . "\n";
    if ($decision === 'reject') {
        $prontuarioNotes .= "Motivo: " . $rejectionReason . "\n";
    }
    if ($notes !== '') {
        $prontuarioNotes .= "Observações: " . $notes;
    }
    $prontuarioStmt->execute([$requirement['patient_id'], $userId, $prontuarioNotes]);
    
    db()->commit();
    
    if ($decision === 'approve') {
        $_SESSION['success'] = 'Documento aprovado com sucesso!';
    } else {
        $_SESSION['success'] = 'Documento rejeitado. O profissional deverá reenviar.';
    }
    
    header('Location: /faturamento_view.php?id=' . (int)$requirement['assignment_id']);
    exit;
    
} catch (Exception $e) {
    db()->rollBack();
    error_log('Erro ao revisar documento de faturamento: ' . $e->getMessage());
    $_SESSION['error'] = 'Erro ao processar revisão. Tente novamente.';
    header('Location: /faturamento_review_doc.php?id=' . $requirementId);
    exit;
}
