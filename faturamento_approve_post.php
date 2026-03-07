<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /faturamento_list.php');
    exit;
}

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
$notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
$userId = auth_user_id();

if ($assignmentId === 0) {
    $_SESSION['error'] = 'Dados inválidos';
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar atendimento
$stmt = db()->prepare("
    SELECT pa.*, p.full_name as patient_name
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    WHERE pa.id = ? AND pa.status = 'awaiting_financial_approval'
");
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    $_SESSION['error'] = 'Atendimento não encontrado ou não está aguardando aprovação';
    header('Location: /faturamento_list.php');
    exit;
}

try {
    db()->beginTransaction();
    
    $totalValue = (float)$assignment['payment_value'] * (int)$assignment['session_quantity'];
    
    // Criar fatura
    $invoiceStmt = db()->prepare("
        INSERT INTO billing_invoices (
            assignment_id,
            patient_id,
            professional_user_id,
            total_sessions,
            value_per_session,
            total_value,
            final_value,
            status,
            approved_by_user_id,
            approved_at,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), NOW())
    ");
    $invoiceStmt->execute([
        $assignmentId,
        $assignment['patient_id'],
        $assignment['professional_user_id'],
        $assignment['session_quantity'],
        $assignment['payment_value'],
        $totalValue,
        $totalValue,
        $userId
    ]);
    
    // Atualizar status do assignment
    $updateStmt = db()->prepare("
        UPDATE patient_assignments
        SET status = 'approved', updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$assignmentId]);
    
    // Registrar no prontuário
    $prontuarioStmt = db()->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, notes)
        VALUES (?, ?, 'faturamento_aprovacao', NOW(), ?)
    ");
    $prontuarioNotes = "Atendimento aprovado financeiramente:\n";
    $prontuarioNotes .= "Valor total: R$ " . number_format($totalValue, 2, ',', '.') . "\n";
    $prontuarioNotes .= "Sessões: " . (int)$assignment['session_quantity'] . "\n";
    if ($notes !== '') {
        $prontuarioNotes .= "Observações: " . $notes;
    }
    $prontuarioStmt->execute([$assignment['patient_id'], $userId, $prontuarioNotes]);
    
    db()->commit();
    
    $_SESSION['success'] = 'Atendimento aprovado financeiramente! Agora pode ser finalizado.';
    header('Location: /faturamento_view.php?id=' . $assignmentId);
    exit;
    
} catch (Exception $e) {
    db()->rollBack();
    error_log('Erro ao aprovar financeiro: ' . $e->getMessage());
    $_SESSION['error'] = 'Erro ao processar aprovação. Tente novamente.';
    header('Location: /faturamento_approve.php?id=' . $assignmentId);
    exit;
}
