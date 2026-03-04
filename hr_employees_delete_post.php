<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_employees_list.php');
    exit;
}

$stmt = db()->prepare('DELETE FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('delete', 'hr_employees', (string)$id, $old, null);

flash_set('success', 'Funcionário excluído.');
header('Location: /hr_employees_list.php');
exit;
