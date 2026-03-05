<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM specialties WHERE id = :id');
$stmt->execute(['id' => $id]);
$s = $stmt->fetch();

if (!$s) {
    flash_set('error', 'Especialidade não encontrada.');
    header('Location: /specialties_list.php');
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('DELETE FROM specialties WHERE id = :id');
    $stmt->execute(['id' => $id]);

    audit_log('delete', 'specialties', (string)$id, $s, null);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Especialidade excluída com sucesso.');
header('Location: /specialties_list.php');
exit;
