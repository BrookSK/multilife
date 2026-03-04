<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$instance = trim((string)($_POST['instance'] ?? ''));
if ($instance === '') {
    flash_set('error', 'Instance inválida.');
    header('Location: /admin_whatsapp_instances.php');
    exit;
}

$evo = new EvolutionApiV1();
$evo->deleteInstance($instance);

flash_set('success', 'Delete solicitado.');
header('Location: /admin_whatsapp_instances.php');
exit;
