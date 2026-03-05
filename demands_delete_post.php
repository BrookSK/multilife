<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

// Apenas admins podem excluir cards
if (!rbac_has_permission('admin.settings.manage')) {
    flash_set('error', 'Apenas administradores podem excluir cards.');
    header('Location: /demands_list.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

$stmt = db()->prepare('DELETE FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('delete', 'demands', (string)$id, $old, null);

flash_set('success', 'Card excluído.');
header('Location: /demands_list.php');
exit;
