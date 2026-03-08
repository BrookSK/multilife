<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = (int)($_POST['employee_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $employeeId]);
$employee = $stmt->fetch();

if (!$employee) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_dashboard.php');
    exit;
}

$employeeNumber = trim((string)($_POST['employee_number'] ?? ''));
$roleId = (int)($_POST['role_id'] ?? 0);

// Buscar position e department automaticamente da role selecionada
$position = '';
$department = '';
if ($roleId > 0) {
    $roleStmt = db()->prepare('SELECT name FROM roles WHERE id = :id');
    $roleStmt->execute(['id' => $roleId]);
    $roleData = $roleStmt->fetch();
    if ($roleData) {
        $position = (string)$roleData['name'];
        $department = (string)$roleData['name'];
    }
}

$costCenterId = trim((string)($_POST['cost_center_id'] ?? ''));
$contractType = trim((string)($_POST['contract_type'] ?? ''));
$admissionDate = trim((string)($_POST['admission_date'] ?? ''));
$baseSalary = trim((string)($_POST['base_salary'] ?? ''));
$workHours = trim((string)($_POST['work_hours'] ?? ''));
$workRegime = trim((string)($_POST['work_regime'] ?? ''));
$supervisorUserId = trim((string)($_POST['supervisor_user_id'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$terminationDate = trim((string)($_POST['termination_date'] ?? ''));
$terminationReason = trim((string)($_POST['termination_reason'] ?? ''));
$internalNotes = trim((string)($_POST['internal_notes'] ?? ''));

if ($roleId === 0) {
    flash_set('error', 'Selecione a função no sistema.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=contrato');
    exit;
}

if ($status === '') {
    flash_set('error', 'Informe o status.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=contrato');
    exit;
}

$sql = 'UPDATE hr_employees SET 
    employee_number = :employee_number,
    position = :position,
    department = :department,
    role_id = :role_id,
    cost_center_id = :cost_center_id,
    contract_type = :contract_type,
    admission_date = :admission_date,
    base_salary = :base_salary,
    work_hours = :work_hours,
    work_regime = :work_regime,
    supervisor_user_id = :supervisor_user_id,
    status = :status,
    termination_date = :termination_date,
    termination_reason = :termination_reason,
    internal_notes = :internal_notes
WHERE id = :id';

$stmt = db()->prepare($sql);
$stmt->execute([
    'id' => $employeeId,
    'employee_number' => $employeeNumber !== '' ? $employeeNumber : null,
    'position' => $position,
    'department' => $department !== '' ? $department : null,
    'role_id' => $roleId,
    'cost_center_id' => $costCenterId !== '' ? (int)$costCenterId : null,
    'contract_type' => $contractType !== '' ? $contractType : null,
    'admission_date' => $admissionDate !== '' ? $admissionDate : null,
    'base_salary' => $baseSalary !== '' ? (float)$baseSalary : null,
    'work_hours' => $workHours !== '' ? $workHours : null,
    'work_regime' => $workRegime !== '' ? $workRegime : null,
    'supervisor_user_id' => $supervisorUserId !== '' ? (int)$supervisorUserId : null,
    'status' => $status,
    'termination_date' => $terminationDate !== '' ? $terminationDate : null,
    'termination_reason' => $terminationReason !== '' ? $terminationReason : null,
    'internal_notes' => $internalNotes !== '' ? $internalNotes : null,
]);

audit_log('update', 'hr_employees', (string)$employeeId, $employee, ['position' => $position, 'status' => $status]);

flash_set('success', 'Dados contratuais atualizados com sucesso!');
header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=contrato');
exit;
