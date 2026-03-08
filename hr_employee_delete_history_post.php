<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$historyId = (int)($_POST['history_id'] ?? 0);
$employeeId = (int)($_POST['employee_id'] ?? 0);

$stmt = db()->prepare('DELETE FROM hr_employee_history WHERE id = :id AND employee_id = :employee_id');
$stmt->execute([
    'id' => $historyId,
    'employee_id' => $employeeId
]);

flash_set('success', 'Evento excluído do histórico!');
header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=historico');
exit;
