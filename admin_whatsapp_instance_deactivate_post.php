<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /admin_settings.php');
    exit;
}

// Inativar instância no banco de dados
$stmt = db()->prepare('UPDATE whatsapp_instances SET status = :status WHERE id = :id');
$stmt->execute([
    'status' => 'inactive',
    'id' => $id
]);

flash_set('success', 'Instância inativada.');
header('Location: /admin_settings.php');
exit;
