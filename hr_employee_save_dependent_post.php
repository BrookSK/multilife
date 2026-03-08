<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = (int)($_POST['employee_id'] ?? 0);
$dependentId = (int)($_POST['dependent_id'] ?? 0);

$fullName = trim((string)($_POST['full_name'] ?? ''));
$cpf = trim((string)($_POST['cpf'] ?? ''));
$birthDate = trim((string)($_POST['birth_date'] ?? ''));
$relationship = trim((string)($_POST['relationship'] ?? ''));
$isIrDependent = isset($_POST['is_ir_dependent']) ? 1 : 0;
$isHealthPlanDependent = isset($_POST['is_health_plan_dependent']) ? 1 : 0;

if ($fullName === '') {
    flash_set('error', 'Informe o nome do dependente.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=dependentes');
    exit;
}

if ($relationship === '') {
    flash_set('error', 'Informe o grau de parentesco.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=dependentes');
    exit;
}

if ($dependentId === 0) {
    // Criar novo dependente
    $sql = 'INSERT INTO hr_employee_dependents (employee_id, full_name, cpf, birth_date, relationship, is_ir_dependent, is_health_plan_dependent) 
            VALUES (:employee_id, :full_name, :cpf, :birth_date, :relationship, :is_ir_dependent, :is_health_plan_dependent)';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'employee_id' => $employeeId,
        'full_name' => $fullName,
        'cpf' => $cpf !== '' ? $cpf : null,
        'birth_date' => $birthDate !== '' ? $birthDate : null,
        'relationship' => $relationship,
        'is_ir_dependent' => $isIrDependent,
        'is_health_plan_dependent' => $isHealthPlanDependent,
    ]);
    
    flash_set('success', 'Dependente adicionado com sucesso!');
} else {
    // Atualizar dependente existente
    $sql = 'UPDATE hr_employee_dependents SET 
            full_name = :full_name,
            cpf = :cpf,
            birth_date = :birth_date,
            relationship = :relationship,
            is_ir_dependent = :is_ir_dependent,
            is_health_plan_dependent = :is_health_plan_dependent
            WHERE id = :id AND employee_id = :employee_id';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'id' => $dependentId,
        'employee_id' => $employeeId,
        'full_name' => $fullName,
        'cpf' => $cpf !== '' ? $cpf : null,
        'birth_date' => $birthDate !== '' ? $birthDate : null,
        'relationship' => $relationship,
        'is_ir_dependent' => $isIrDependent,
        'is_health_plan_dependent' => $isHealthPlanDependent,
    ]);
    
    flash_set('success', 'Dependente atualizado com sucesso!');
}

header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=dependentes');
exit;
