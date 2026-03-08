<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$entryId = (int)($_POST['entry_id'] ?? 0);
$employeeId = (int)($_POST['employee_id'] ?? 0);

if ($entryId === 0 || $employeeId === 0) {
    flash_set('error', 'Parâmetros inválidos.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=folha');
    exit;
}

// Buscar lançamento de folha
$stmt = db()->prepare('SELECT * FROM hr_payroll_entries WHERE id = :id AND employee_id = :employee_id');
$stmt->execute(['id' => $entryId, 'employee_id' => $employeeId]);
$payrollEntry = $stmt->fetch();

if (!$payrollEntry) {
    flash_set('error', 'Lançamento não encontrado.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=folha');
    exit;
}

if ($payrollEntry['status'] !== 'pending') {
    flash_set('error', 'Este lançamento já foi processado.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=folha');
    exit;
}

// Buscar funcionário
$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $employeeId]);
$employee = $stmt->fetch();

if (!$employee) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_dashboard.php');
    exit;
}

// Buscar configurações padrão
$defaultCategory = 'Folha de Pagamento';
$defaultCostCenter = 'Administrativo';

$stmt = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'payroll.default_category'");
$categoryRow = $stmt->fetch();
if ($categoryRow) {
    $defaultCategory = $categoryRow['setting_value'];
}

$stmt = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'payroll.default_cost_center'");
$costCenterRow = $stmt->fetch();
if ($costCenterRow) {
    $defaultCostCenter = $costCenterRow['setting_value'];
}

// Criar lançamento financeiro
$monthYear = DateTime::createFromFormat('Y-m', $payrollEntry['reference_month']);
$description = 'Salário - ' . $employee['full_name'] . ' - ' . ($monthYear ? $monthYear->format('m/Y') : $payrollEntry['reference_month']);

$sql = 'INSERT INTO financial_entries (
    entry_type, category, description, amount, entry_date, due_date, status,
    supplier_name, payment_type, cost_center, created_by_user_id
) VALUES (
    :entry_type, :category, :description, :amount, :entry_date, :due_date, :status,
    :supplier_name, :payment_type, :cost_center, :created_by_user_id
)';

$stmt = db()->prepare($sql);
$stmt->execute([
    'entry_type' => 'expense',
    'category' => $defaultCategory,
    'description' => $description,
    'amount' => (float)$payrollEntry['amount'],
    'entry_date' => date('Y-m-d'),
    'due_date' => $payrollEntry['due_date'],
    'status' => 'pending',
    'supplier_name' => $employee['full_name'],
    'payment_type' => 'transferencia',
    'cost_center' => $defaultCostCenter,
    'created_by_user_id' => auth_user_id(),
]);

$financialEntryId = (int)db()->lastInsertId();

// Atualizar lançamento de folha
$stmt = db()->prepare('UPDATE hr_payroll_entries SET 
    status = :status, 
    financial_entry_id = :financial_entry_id, 
    generated_at = NOW() 
WHERE id = :id');

$stmt->execute([
    'id' => $entryId,
    'status' => 'generated',
    'financial_entry_id' => $financialEntryId,
]);

// Registrar no histórico
$historyStmt = db()->prepare('INSERT INTO hr_employee_history (employee_id, change_type, change_date, description, created_by_user_id) VALUES (:employee_id, :change_type, NOW(), :description, :created_by_user_id)');
$historyStmt->execute([
    'employee_id' => $employeeId,
    'change_type' => 'outro',
    'description' => 'Lançamento de folha de pagamento gerado no financeiro: ' . $description,
    'created_by_user_id' => auth_user_id(),
]);

flash_set('success', 'Lançamento financeiro criado com sucesso! Você pode visualizá-lo em Contas a Pagar.');
header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=folha');
exit;
