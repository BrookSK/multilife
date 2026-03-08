<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /finance_entry_create.php');
    exit;
}

$db = db();
$userId = auth_user_id();

try {
    $db->beginTransaction();
    
    $entryType = trim((string)($_POST['entry_type'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $paymentType = trim((string)($_POST['payment_type'] ?? 'single'));
    $description = trim((string)($_POST['description'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'pending'));
    
    // Validações básicas
    if (!in_array($entryType, ['income', 'expense'], true)) {
        throw new Exception('Tipo de lançamento inválido');
    }
    
    if (!in_array($paymentType, ['single', 'installment', 'recurring', 'continuous'], true)) {
        throw new Exception('Tipo de pagamento inválido');
    }
    
    if (!in_array($status, ['pending', 'paid'], true)) {
        throw new Exception('Status inválido');
    }
    
    if (empty($category) || empty($description)) {
        throw new Exception('Categoria e descrição são obrigatórios');
    }
    
    // Dados comuns
    $entryDate = !empty($_POST['entry_date']) ? trim((string)$_POST['entry_date']) : date('Y-m-d');
    $dueDate = !empty($_POST['due_date']) ? trim((string)$_POST['due_date']) : null;
    $paidDate = $status === 'paid' ? date('Y-m-d') : null;
    
    $patientId = !empty($_POST['patient_id']) ? (int)$_POST['patient_id'] : null;
    $professionalUserId = !empty($_POST['professional_user_id']) ? (int)$_POST['professional_user_id'] : null;
    $paymentMethod = !empty($_POST['payment_method']) ? trim((string)$_POST['payment_method']) : null;
    $documentNumber = !empty($_POST['document_number']) ? trim((string)$_POST['document_number']) : null;
    $supplierName = !empty($_POST['supplier_name']) ? trim((string)$_POST['supplier_name']) : null;
    $costCenter = !empty($_POST['cost_center']) ? trim((string)$_POST['cost_center']) : null;
    $notes = !empty($_POST['notes']) ? trim((string)$_POST['notes']) : null;
    
    // Processar conforme tipo de pagamento
    if ($paymentType === 'installment') {
        // PARCELADO
        $totalInstallments = (int)($_POST['total_installments'] ?? 2);
        $totalAmount = (float)($_POST['total_amount'] ?? 0);
        
        if ($totalInstallments < 2 || $totalInstallments > 120) {
            throw new Exception('Número de parcelas deve estar entre 2 e 120');
        }
        
        if ($totalAmount <= 0) {
            throw new Exception('Valor total deve ser maior que zero');
        }
        
        $installmentAmount = round($totalAmount / $totalInstallments, 2);
        
        // Criar lançamento pai
        $stmt = $db->prepare(
            "INSERT INTO financial_entries (
                entry_type, category, amount, description, entry_date, due_date, paid_date,
                status, payment_type, total_installments, patient_id, professional_user_id,
                payment_method, document_number, supplier_name, cost_center, notes,
                created_by_user_id, is_active
            ) VALUES (
                :entry_type, :category, :amount, :description, :entry_date, :due_date, :paid_date,
                :status, :payment_type, :total_installments, :patient_id, :professional_user_id,
                :payment_method, :document_number, :supplier_name, :cost_center, :notes,
                :created_by_user_id, 0
            )"
        );
        
        $stmt->execute([
            'entry_type' => $entryType,
            'category' => $category,
            'amount' => $totalAmount,
            'description' => $description . ' (Total)',
            'entry_date' => $entryDate,
            'due_date' => $dueDate,
            'paid_date' => $paidDate,
            'status' => 'pending',
            'payment_type' => $paymentType,
            'total_installments' => $totalInstallments,
            'patient_id' => $patientId,
            'professional_user_id' => $professionalUserId,
            'payment_method' => $paymentMethod,
            'document_number' => $documentNumber,
            'supplier_name' => $supplierName,
            'cost_center' => $costCenter,
            'notes' => $notes,
            'created_by_user_id' => $userId
        ]);
        
        $parentId = (int)$db->lastInsertId();
        
        // Criar parcelas
        $baseDueDate = $dueDate ? new DateTime($dueDate) : new DateTime($entryDate);
        
        for ($i = 1; $i <= $totalInstallments; $i++) {
            $installmentDueDate = clone $baseDueDate;
            $installmentDueDate->modify('+' . ($i - 1) . ' month');
            
            // Ajustar última parcela para compensar arredondamento
            $currentAmount = $installmentAmount;
            if ($i === $totalInstallments) {
                $currentAmount = $totalAmount - ($installmentAmount * ($totalInstallments - 1));
            }
            
            $stmt = $db->prepare(
                "INSERT INTO financial_entries (
                    entry_type, category, amount, description, entry_date, due_date,
                    status, payment_type, installment_number, total_installments, parent_entry_id,
                    patient_id, professional_user_id, payment_method, document_number,
                    supplier_name, cost_center, notes, created_by_user_id
                ) VALUES (
                    :entry_type, :category, :amount, :description, :entry_date, :due_date,
                    :status, :payment_type, :installment_number, :total_installments, :parent_entry_id,
                    :patient_id, :professional_user_id, :payment_method, :document_number,
                    :supplier_name, :cost_center, :notes, :created_by_user_id
                )"
            );
            
            $stmt->execute([
                'entry_type' => $entryType,
                'category' => $category,
                'amount' => $currentAmount,
                'description' => $description . ' (Parcela ' . $i . '/' . $totalInstallments . ')',
                'entry_date' => $entryDate,
                'due_date' => $installmentDueDate->format('Y-m-d'),
                'status' => 'pending',
                'payment_type' => $paymentType,
                'installment_number' => $i,
                'total_installments' => $totalInstallments,
                'parent_entry_id' => $parentId,
                'patient_id' => $patientId,
                'professional_user_id' => $professionalUserId,
                'payment_method' => $paymentMethod,
                'document_number' => $documentNumber,
                'supplier_name' => $supplierName,
                'cost_center' => $costCenter,
                'notes' => $notes,
                'created_by_user_id' => $userId
            ]);
        }
        
        $message = $totalInstallments . ' parcelas criadas com sucesso!';
        
    } elseif ($paymentType === 'recurring' || $paymentType === 'continuous') {
        // RECORRENTE OU CONTÍNUO
        $amount = (float)($_POST['amount'] ?? 0);
        $recurrenceFrequency = trim((string)($_POST['recurrence_frequency'] ?? 'monthly'));
        $recurrenceEndDate = !empty($_POST['recurrence_end_date']) ? trim((string)$_POST['recurrence_end_date']) : null;
        
        if ($amount <= 0) {
            throw new Exception('Valor deve ser maior que zero');
        }
        
        if (!in_array($recurrenceFrequency, ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual'], true)) {
            throw new Exception('Frequência de recorrência inválida');
        }
        
        $stmt = $db->prepare(
            "INSERT INTO financial_entries (
                entry_type, category, amount, description, entry_date, due_date, paid_date,
                status, payment_type, recurrence_frequency, recurrence_end_date,
                patient_id, professional_user_id, payment_method, document_number,
                supplier_name, cost_center, notes, created_by_user_id
            ) VALUES (
                :entry_type, :category, :amount, :description, :entry_date, :due_date, :paid_date,
                :status, :payment_type, :recurrence_frequency, :recurrence_end_date,
                :patient_id, :professional_user_id, :payment_method, :document_number,
                :supplier_name, :cost_center, :notes, :created_by_user_id
            )"
        );
        
        $stmt->execute([
            'entry_type' => $entryType,
            'category' => $category,
            'amount' => $amount,
            'description' => $description,
            'entry_date' => $entryDate,
            'due_date' => $dueDate,
            'paid_date' => $paidDate,
            'status' => $status,
            'payment_type' => $paymentType,
            'recurrence_frequency' => $recurrenceFrequency,
            'recurrence_end_date' => $recurrenceEndDate,
            'patient_id' => $patientId,
            'professional_user_id' => $professionalUserId,
            'payment_method' => $paymentMethod,
            'document_number' => $documentNumber,
            'supplier_name' => $supplierName,
            'cost_center' => $costCenter,
            'notes' => $notes,
            'created_by_user_id' => $userId
        ]);
        
        $message = 'Lançamento recorrente criado com sucesso!';
        
    } else {
        // PAGAMENTO ÚNICO
        $amount = (float)($_POST['amount'] ?? 0);
        
        if ($amount <= 0) {
            throw new Exception('Valor deve ser maior que zero');
        }
        
        $stmt = $db->prepare(
            "INSERT INTO financial_entries (
                entry_type, category, amount, description, entry_date, due_date, paid_date,
                status, payment_type, patient_id, professional_user_id, payment_method,
                document_number, supplier_name, cost_center, notes, created_by_user_id
            ) VALUES (
                :entry_type, :category, :amount, :description, :entry_date, :due_date, :paid_date,
                :status, :payment_type, :patient_id, :professional_user_id, :payment_method,
                :document_number, :supplier_name, :cost_center, :notes, :created_by_user_id
            )"
        );
        
        $stmt->execute([
            'entry_type' => $entryType,
            'category' => $category,
            'amount' => $amount,
            'description' => $description,
            'entry_date' => $entryDate,
            'due_date' => $dueDate,
            'paid_date' => $paidDate,
            'status' => $status,
            'payment_type' => $paymentType,
            'patient_id' => $patientId,
            'professional_user_id' => $professionalUserId,
            'payment_method' => $paymentMethod,
            'document_number' => $documentNumber,
            'supplier_name' => $supplierName,
            'cost_center' => $costCenter,
            'notes' => $notes,
            'created_by_user_id' => $userId
        ]);
        
        $message = 'Lançamento criado com sucesso!';
    }
    
    $db->commit();
    
    flash_set('success', $message);
    header('Location: /finance_entries_list.php');
    exit;
    
} catch (Exception $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao criar lançamento: ' . $e->getMessage());
    header('Location: /finance_entry_create.php');
    exit;
}
