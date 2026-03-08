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

$benefitTransport = isset($_POST['benefit_transport']) ? 1 : 0;
$benefitFoodValue = trim((string)($_POST['benefit_food_value'] ?? ''));
$benefitMealValue = trim((string)($_POST['benefit_meal_value'] ?? ''));
$benefitHealthPlan = isset($_POST['benefit_health_plan']) ? 1 : 0;
$benefitDentalPlan = isset($_POST['benefit_dental_plan']) ? 1 : 0;
$benefitOther = trim((string)($_POST['benefit_other'] ?? ''));

$sql = 'UPDATE hr_employees SET 
    benefit_transport = :benefit_transport,
    benefit_food_value = :benefit_food_value,
    benefit_meal_value = :benefit_meal_value,
    benefit_health_plan = :benefit_health_plan,
    benefit_dental_plan = :benefit_dental_plan,
    benefit_other = :benefit_other
WHERE id = :id';

$stmt = db()->prepare($sql);
$stmt->execute([
    'id' => $employeeId,
    'benefit_transport' => $benefitTransport,
    'benefit_food_value' => $benefitFoodValue !== '' ? (float)$benefitFoodValue : null,
    'benefit_meal_value' => $benefitMealValue !== '' ? (float)$benefitMealValue : null,
    'benefit_health_plan' => $benefitHealthPlan,
    'benefit_dental_plan' => $benefitDentalPlan,
    'benefit_other' => $benefitOther !== '' ? $benefitOther : null,
]);

audit_log('update', 'hr_employees', (string)$employeeId, $employee, ['beneficios' => 'atualizado']);

flash_set('success', 'Benefícios atualizados com sucesso!');
header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=beneficios');
exit;
