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
$adjustedValue = isset($_POST['adjusted_value']) ? (float)$_POST['adjusted_value'] : 0;
$adjustmentReason = isset($_POST['adjustment_reason']) ? trim((string)$_POST['adjustment_reason']) : '';
$originalValue = isset($_POST['original_value']) ? (float)$_POST['original_value'] : 0;
$userId = auth_user_id();

if ($assignmentId === 0 || $adjustedValue < 0 || $adjustmentReason === '') {
    $_SESSION['error'] = 'Todos os campos são obrigatórios';
    header('Location: /faturamento_adjust.php?id=' . $assignmentId);
    exit;
}

// Buscar fatura
$stmt = db()->prepare("
    SELECT bi.*, pa.patient_id
    FROM billing_invoices bi
    INNER JOIN patient_assignments pa ON pa.id = bi.assignment_id
    WHERE bi.assignment_id = ?
");
$stmt->execute([$assignmentId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    $_SESSION['error'] = 'Fatura não encontrada';
    header('Location: /faturamento_list.php');
    exit;
}

try {
    db()->beginTransaction();
    
    // Atualizar fatura
    $updateStmt = db()->prepare("
        UPDATE billing_invoices
        SET 
            adjusted_value = ?,
            final_value = ?,
            adjustment_reason = ?,
            adjusted_by_user_id = ?,
            adjusted_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$adjustedValue, $adjustedValue, $adjustmentReason, $userId, $invoice['id']]);
    
    // Registrar no prontuário
    $prontuarioStmt = db()->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, notes)
        VALUES (?, ?, 'faturamento_ajuste', NOW(), ?)
    ");
    $prontuarioNotes = "Valor do faturamento ajustado:\n";
    $prontuarioNotes .= "Valor original: R$ " . number_format($originalValue, 2, ',', '.') . "\n";
    $prontuarioNotes .= "Novo valor: R$ " . number_format($adjustedValue, 2, ',', '.') . "\n";
    $prontuarioNotes .= "Diferença: R$ " . number_format(abs($adjustedValue - $originalValue), 2, ',', '.') . "\n";
    $prontuarioNotes .= "Motivo: " . $adjustmentReason;
    $prontuarioStmt->execute([$invoice['patient_id'], $userId, $prontuarioNotes]);
    
    db()->commit();
    
    $_SESSION['success'] = 'Valores ajustados com sucesso!';
    header('Location: /faturamento_view.php?id=' . $assignmentId);
    exit;
    
} catch (Exception $e) {
    db()->rollBack();
    error_log('Erro ao ajustar valores: ' . $e->getMessage());
    $_SESSION['error'] = 'Erro ao processar ajuste. Tente novamente.';
    header('Location: /faturamento_adjust.php?id=' . $assignmentId);
    exit;
}
