<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Grupo não encontrado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$stmt = db()->prepare('DELETE FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('delete', 'whatsapp_groups', (string)$id, $old, null);

flash_set('success', 'Grupo excluído.');
header('Location: /whatsapp_groups_list.php');
exit;
