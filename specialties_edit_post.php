<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$minimumValue = (float)($_POST['minimum_value'] ?? 0);
$status = (string)($_POST['status'] ?? 'active');

$stmt = db()->prepare('SELECT * FROM specialties WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Especialidade não encontrada.');
    header('Location: /specialties_list.php');
    exit;
}

if ($name === '') {
    flash_set('error', 'Nome é obrigatório.');
    header('Location: /specialties_edit.php?id=' . $id);
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE specialties SET name = :name, minimum_value = :min_val, status = :status WHERE id = :id');
    $stmt->execute([
        'name' => $name,
        'min_val' => $minimumValue,
        'status' => $status,
        'id' => $id,
    ]);

    audit_log('update', 'specialties', (string)$id, $old, ['name' => $name, 'minimum_value' => $minimumValue, 'status' => $status]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Especialidade atualizada com sucesso.');
header('Location: /specialties_list.php');
exit;
