<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$name = trim((string)($_POST['name'] ?? ''));

if ($name === '') {
    flash_set('error', 'Nome é obrigatório.');
    header('Location: /specialties_create.php');
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO specialties (name, status) VALUES (:name, :status)');
    $stmt->execute([
        'name' => $name,
        'status' => 'active',
    ]);

    $id = (int)$db->lastInsertId();

    audit_log('create', 'specialties', (string)$id, null, ['name' => $name, 'status' => 'active']);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Especialidade criada com sucesso! Configure os tipos de serviço agora.');
header('Location: /specialty_services_v2.php?id=' . $id);
exit;
