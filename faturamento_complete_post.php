<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
// rbac_require_permission('billing.manage'); // TODO: Configurar permissão no sistema

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /faturamento_list.php');
    exit;
}

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
$paymentDate = isset($_POST['payment_date']) ? trim((string)$_POST['payment_date']) : date('Y-m-d');
$paymentReference = isset($_POST['payment_reference']) ? trim((string)$_POST['payment_reference']) : '';
$notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
$userId = auth_user_id();

if ($assignmentId === 0) {
    $_SESSION['error'] = 'Dados inválidos';
    header('Location: /faturamento_list.php');
    exit;
}

// Buscar atendimento e fatura
$stmt = db()->prepare("
    SELECT pa.*, bi.id as invoice_id, bi.final_value
    FROM patient_assignments pa
    LEFT JOIN billing_invoices bi ON bi.assignment_id = pa.id
    WHERE pa.id = ? AND pa.status = 'approved'
");
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment || !$assignment['invoice_id']) {
    $_SESSION['error'] = 'Atendimento não encontrado ou não está aprovado';
    header('Location: /faturamento_list.php');
    exit;
}

try {
    db()->beginTransaction();
    
    // Atualizar fatura para paga
    $updateInvoiceStmt = db()->prepare("
        UPDATE billing_invoices
        SET 
            status = 'paid',
            paid_at = ?,
            payment_reference = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateInvoiceStmt->execute([$paymentDate, $paymentReference, $assignment['invoice_id']]);
    
    // Atualizar assignment para completed
    $updateAssignmentStmt = db()->prepare("
        UPDATE patient_assignments
        SET 
            status = 'completed',
            completed_at = NOW(),
            completed_by_user_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateAssignmentStmt->execute([$userId, $assignmentId]);
    
    // Atualizar demand para concluído
    $updateDemandStmt = db()->prepare("
        UPDATE demands
        SET status = 'concluido', updated_at = NOW()
        WHERE id = ?
    ");
    $updateDemandStmt->execute([$assignment['demand_id']]);
    
    // Criar lançamento financeiro
    $financialStmt = db()->prepare("
        INSERT INTO financial_entries (
            entry_type,
            category,
            invoice_id,
            assignment_id,
            patient_id,
            professional_user_id,
            amount,
            description,
            entry_date,
            paid_date,
            status,
            created_by_user_id,
            created_at
        ) VALUES (
            'expense',
            'Pagamento Profissional',
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'paid',
            ?,
            NOW()
        )
    ");
    
    $description = "Pagamento de atendimento - " . $assignment['session_quantity'] . " sessões";
    if ($paymentReference !== '') {
        $description .= " - Ref: " . $paymentReference;
    }
    
    $financialStmt->execute([
        $assignment['invoice_id'],
        $assignmentId,
        $assignment['patient_id'],
        $assignment['professional_user_id'],
        $assignment['final_value'],
        $description,
        $paymentDate,
        $paymentDate,
        $userId
    ]);
    
    // Registrar no prontuário
    $prontuarioStmt = db()->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, notes)
        VALUES (?, ?, 'faturamento_conclusao', NOW(), ?)
    ");
    $prontuarioNotes = "Atendimento finalizado e concluído:\n";
    $prontuarioNotes .= "Valor final: R$ " . number_format((float)$assignment['final_value'], 2, ',', '.') . "\n";
    $prontuarioNotes .= "Data de pagamento: " . date('d/m/Y', strtotime($paymentDate)) . "\n";
    if ($paymentReference !== '') {
        $prontuarioNotes .= "Referência: " . $paymentReference . "\n";
    }
    if ($notes !== '') {
        $prontuarioNotes .= "Observações: " . $notes;
    }
    $prontuarioStmt->execute([$assignment['patient_id'], $userId, $prontuarioNotes]);
    
    db()->commit();
    
    $_SESSION['success'] = 'Atendimento finalizado com sucesso! O card foi movido para Concluído na Captação e o valor foi registrado no financeiro.';
    header('Location: /faturamento_list.php?tab=completed');
    exit;
    
} catch (Exception $e) {
    db()->rollBack();
    error_log('Erro ao finalizar atendimento: ' . $e->getMessage());
    $_SESSION['error'] = 'Erro ao processar finalização. Tente novamente.';
    header('Location: /faturamento_complete.php?id=' . $assignmentId);
    exit;
}
