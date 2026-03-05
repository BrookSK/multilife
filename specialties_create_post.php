<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$name = trim((string)($_POST['name'] ?? ''));
$minimumValue = (float)($_POST['minimum_value'] ?? 0);
$status = (string)($_POST['status'] ?? 'active');

if ($name === '') {
    flash_set('error', 'Nome é obrigatório.');
    header('Location: /specialties_create.php');
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO specialties (name, minimum_value, status) VALUES (:name, :min_val, :status)');
    $stmt->execute([
        'name' => $name,
        'min_val' => $minimumValue,
        'status' => $status,
    ]);

    $id = (int)$db->lastInsertId();

    audit_log('create', 'specialties', (string)$id, null, ['name' => $name, 'minimum_value' => $minimumValue, 'status' => $status]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Especialidade criada com sucesso.');
header('Location: /specialties_list.php');
exit;
