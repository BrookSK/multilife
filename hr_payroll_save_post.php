<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = (int)($_POST['employee_id'] ?? 0);
$payrollId = (int)($_POST['payroll_id'] ?? 0);
$baseSalary = trim((string)($_POST['base_salary'] ?? ''));
$startDate = trim((string)($_POST['start_date'] ?? ''));
$firstPaymentDueDate = trim((string)($_POST['first_payment_due_date'] ?? ''));
$paymentDay = (int)($_POST['payment_day'] ?? 5);
$notes = trim((string)($_POST['notes'] ?? ''));

if ($employeeId === 0 || $baseSalary === '' || $startDate === '' || $firstPaymentDueDate === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=folha');
    exit;
}

if ($paymentDay < 1 || $paymentDay > 31) {
    flash_set('error', 'Dia de pagamento deve estar entre 1 e 31.');
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

if ($payrollId === 0) {
    // Desativar salários anteriores
    $stmt = db()->prepare('UPDATE hr_employee_payroll SET is_active = 0, end_date = :end_date WHERE employee_id = :employee_id AND is_active = 1');
    $stmt->execute([
        'employee_id' => $employeeId,
        'end_date' => date('Y-m-d', strtotime($startDate . ' -1 day'))
    ]);
    
    // Criar novo salário
    $sql = 'INSERT INTO hr_employee_payroll (
        employee_id, base_salary, start_date, first_payment_due_date, payment_day, notes, created_by_user_id
    ) VALUES (
        :employee_id, :base_salary, :start_date, :first_payment_due_date, :payment_day, :notes, :created_by_user_id
    )';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'employee_id' => $employeeId,
        'base_salary' => (float)$baseSalary,
        'start_date' => $startDate,
        'first_payment_due_date' => $firstPaymentDueDate,
        'payment_day' => $paymentDay,
        'notes' => $notes !== '' ? $notes : null,
        'created_by_user_id' => auth_user_id(),
    ]);
    
    $newPayrollId = (int)db()->lastInsertId();
    
    // Gerar lançamentos para os próximos 12 meses
    $currentDate = new DateTime($startDate);
    $endDate = new DateTime($startDate);
    $endDate->modify('+12 months');
    
    while ($currentDate <= $endDate) {
        $referenceMonth = $currentDate->format('Y-m');
        $dueDate = new DateTime($currentDate->format('Y-m-' . str_pad((string)$paymentDay, 2, '0', STR_PAD_LEFT)));
        
        // Se o dia não existe no mês, usar o último dia do mês
        if ($dueDate->format('m') !== $currentDate->format('m')) {
            $dueDate = new DateTime($currentDate->format('Y-m-t'));
        }
        
        $stmt = db()->prepare('INSERT INTO hr_payroll_entries (
            employee_id, payroll_id, reference_month, amount, due_date, status
        ) VALUES (
            :employee_id, :payroll_id, :reference_month, :amount, :due_date, :status
        )');
        
        $stmt->execute([
            'employee_id' => $employeeId,
            'payroll_id' => $newPayrollId,
            'reference_month' => $referenceMonth,
            'amount' => (float)$baseSalary,
            'due_date' => $dueDate->format('Y-m-d'),
            'status' => 'pending',
        ]);
        
        $currentDate->modify('+1 month');
    }
    
    // Registrar no histórico
    $historyStmt = db()->prepare('INSERT INTO hr_employee_history (employee_id, change_type, change_date, description, old_value, new_value, created_by_user_id) VALUES (:employee_id, :change_type, NOW(), :description, :old_value, :new_value, :created_by_user_id)');
    $historyStmt->execute([
        'employee_id' => $employeeId,
        'change_type' => 'outro',
        'description' => 'Salário cadastrado no sistema de folha de pagamento',
        'old_value' => null,
        'new_value' => 'R$ ' . number_format((float)$baseSalary, 2, ',', '.'),
        'created_by_user_id' => auth_user_id(),
    ]);
    
    flash_set('success', 'Salário cadastrado com sucesso! Lançamentos dos próximos 12 meses foram criados.');
} else {
    // Atualizar salário existente
    $sql = 'UPDATE hr_employee_payroll SET 
        base_salary = :base_salary,
        start_date = :start_date,
        first_payment_due_date = :first_payment_due_date,
        payment_day = :payment_day,
        notes = :notes
    WHERE id = :id AND employee_id = :employee_id';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'id' => $payrollId,
        'employee_id' => $employeeId,
        'base_salary' => (float)$baseSalary,
        'start_date' => $startDate,
        'first_payment_due_date' => $firstPaymentDueDate,
        'payment_day' => $paymentDay,
        'notes' => $notes !== '' ? $notes : null,
    ]);
    
    flash_set('success', 'Salário atualizado com sucesso!');
}

header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=folha');
exit;
