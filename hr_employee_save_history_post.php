<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = (int)($_POST['employee_id'] ?? 0);
$changeType = trim((string)($_POST['change_type'] ?? ''));
$changeDate = trim((string)($_POST['change_date'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$oldValue = trim((string)($_POST['old_value'] ?? ''));
$newValue = trim((string)($_POST['new_value'] ?? ''));

if ($changeType === '' || $changeDate === '' || $description === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=historico');
    exit;
}

$sql = 'INSERT INTO hr_employee_history (employee_id, change_type, change_date, description, old_value, new_value, created_by_user_id) 
        VALUES (:employee_id, :change_type, :change_date, :description, :old_value, :new_value, :created_by_user_id)';

$stmt = db()->prepare($sql);
$stmt->execute([
    'employee_id' => $employeeId,
    'change_type' => $changeType,
    'change_date' => $changeDate,
    'description' => $description,
    'old_value' => $oldValue !== '' ? $oldValue : null,
    'new_value' => $newValue !== '' ? $newValue : null,
    'created_by_user_id' => auth_user_id(),
]);

flash_set('success', 'Evento adicionado ao histórico!');
header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=historico');
exit;
