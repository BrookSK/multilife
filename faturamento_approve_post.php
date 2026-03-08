<?php

declare(strict_types=1);

error_log(">>> ARQUIVO faturamento_approve_post.php ACESSADO <<<");
error_log("REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNDEFINED'));
error_log("POST data: " . print_r($_POST, true));

require_once __DIR__ . '/app/bootstrap.php';

error_log("Bootstrap carregado");
auth_require_login();
error_log("Auth verificado");
// rbac_require_permission('billing.manage'); // TODO: Configurar permissão no sistema

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
    error_log("=== INICIO APROVACAO FINANCEIRA ===");
    error_log("Assignment ID: " . $assignmentId);
    error_log("User ID: " . $userId);
    
    db()->beginTransaction();
    error_log("Transaction iniciada");
    
    // Calcular valores corretos
    $agreedValue = (float)($assignment['agreed_value'] ?? $assignment['payment_value'] ?? 0);
    $authorizedValue = (float)($assignment['authorized_value'] ?? $assignment['payment_value'] ?? 0);
    $sessionQty = (int)$assignment['session_quantity'];
    
    $totalRevenue = $agreedValue * $sessionQty;  // RECEITA: cliente paga
    $totalCost = $authorizedValue * $sessionQty; // DESPESA: profissional recebe
    
    error_log("Receita total: R$ " . $totalRevenue);
    error_log("Custo total: R$ " . $totalCost);
    error_log("Lucro: R$ " . ($totalRevenue - $totalCost));
    
    // Criar fatura
    error_log("Preparando INSERT billing_invoices");
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
    try {
        $invoiceStmt->execute([
            $assignmentId,
            $assignment['patient_id'],
            $assignment['professional_user_id'],
            $sessionQty,
            $agreedValue,
            $totalRevenue,
            $totalRevenue,
            $userId
        ]);
        error_log("Fatura criada com sucesso");
    } catch (PDOException $e) {
        error_log("ERRO ao criar fatura: " . $e->getMessage());
        throw $e;
    }
    
    // IMPORTANTE: Pegar lastInsertId IMEDIATAMENTE após o INSERT
    $invoiceId = (int)db()->lastInsertId();
    error_log("Invoice ID criado: " . $invoiceId);
    
    if ($invoiceId === 0) {
        throw new Exception("Falha ao obter ID da fatura criada");
    }
    
    // Atualizar status do assignment
    error_log("Atualizando status do assignment");
    $updateStmt = db()->prepare("
        UPDATE patient_assignments
        SET status = 'approved', updated_at = NOW()
        WHERE id = ?
    ");
    try {
        $updateStmt->execute([$assignmentId]);
        error_log("Status atualizado para 'approved'");
    } catch (PDOException $e) {
        error_log("ERRO ao atualizar status: " . $e->getMessage());
        throw $e;
    }
    
    // Criar lançamento de receita (income) no financeiro
    $incomeStmt = db()->prepare("
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
            status,
            created_by_user_id
        ) VALUES (
            'income',
            'Atendimento Profissional',
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            CURDATE(),
            'pending',
            ?
        )
    ");
    $incomeDescription = "Receita de atendimento - " . $assignment['patient_name'] . " - " . (int)$assignment['session_quantity'] . " sessões";
    error_log("Criando lançamento de receita (income)");
    try {
        $incomeStmt->execute([
            $invoiceId,
            $assignmentId,
            $assignment['patient_id'],
            $assignment['professional_user_id'],
            $totalRevenue,
            $incomeDescription,
            $userId
        ]);
        error_log("Receita criada: R$ " . $totalRevenue);
    } catch (PDOException $e) {
        error_log("ERRO ao criar receita: " . $e->getMessage());
        throw $e;
    }
    
    // Criar lançamento de custo (expense) - pagamento ao profissional
    
    $expenseStmt = db()->prepare("
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
            status,
            created_by_user_id
        ) VALUES (
            'expense',
            'Pagamento Profissional',
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            CURDATE(),
            'pending',
            ?
        )
    ");
    $expenseDescription = "Pagamento ao profissional - " . $assignment['patient_name'] . " - " . (int)$assignment['session_quantity'] . " sessões";
    error_log("Criando lançamento de despesa (expense)");
    try {
        $expenseStmt->execute([
            $invoiceId,
            $assignmentId,
            $assignment['patient_id'],
            $assignment['professional_user_id'],
            $totalCost,
            $expenseDescription,
            $userId
        ]);
        error_log("Despesa criada: R$ " . $totalCost);
    } catch (PDOException $e) {
        error_log("ERRO ao criar despesa: " . $e->getMessage());
        throw $e;
    }
    
    // Registrar no prontuário
    $prontuarioStmt = db()->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, notes)
        VALUES (?, ?, 'faturamento_aprovacao', NOW(), ?)
    ");
    $prontuarioNotes = "Atendimento aprovado financeiramente:\n";
    $prontuarioNotes .= "Valor total: R$ " . number_format($totalValue, 2, ',', '.') . "\n";
    $prontuarioNotes .= "Sessões: " . (int)$assignment['session_quantity'] . "\n";
    $prontuarioNotes .= "Receita registrada: R$ " . number_format($totalValue, 2, ',', '.') . "\n";
    $prontuarioNotes .= "Custo profissional: R$ " . number_format($professionalCost, 2, ',', '.') . "\n";
    $prontuarioNotes .= "Lucro líquido: R$ " . number_format($totalValue - $professionalCost, 2, ',', '.') . "\n";
    if ($notes !== '') {
        $prontuarioNotes .= "Observações: " . $notes;
    }
    error_log("Registrando no prontuário");
    try {
        $prontuarioStmt->execute([$assignment['patient_id'], $userId, $prontuarioNotes]);
        error_log("Prontuário atualizado");
    } catch (PDOException $e) {
        error_log("ERRO ao registrar prontuário: " . $e->getMessage());
        throw $e;
    }
    
    error_log("Fazendo commit da transaction");
    db()->commit();
    error_log("=== APROVACAO CONCLUIDA COM SUCESSO ===");
    
    $_SESSION['success'] = 'Atendimento aprovado financeiramente! Agora pode ser finalizado.';
    header('Location: /faturamento_view.php?id=' . $assignmentId);
    exit;
    
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
        error_log("Transaction revertida (rollback)");
    }
    error_log('=== ERRO AO APROVAR FINANCEIRO ===');
    error_log('Tipo de erro: ' . get_class($e));
    error_log('Mensagem: ' . $e->getMessage());
    error_log('Arquivo: ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    $_SESSION['error'] = 'Erro ao processar aprovação: ' . $e->getMessage();
    header('Location: /faturamento_approve.php?id=' . $assignmentId);
    exit;
}
