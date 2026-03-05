<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM specialty_minimums WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /specialty_minimums_list.php');
    exit;
}

$stmt = db()->prepare("UPDATE specialty_minimums SET status = 'inactive' WHERE id = :id");
$stmt->execute(['id' => $id]);

audit_log('delete', 'specialty_minimums', (string)$id, $old, ['status' => 'inactive']);

flash_set('success', 'Mínimo inativado.');
header('Location: /specialty_minimums_list.php');
exit;
