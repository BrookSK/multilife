<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

// Verificar se Evolution API está configurada
$baseUrl = admin_setting_get('evolution.base_url', '');
$apiKey = admin_setting_get('evolution.api_key', '');

if ($baseUrl === '' || $apiKey === '') {
    flash_set('error', 'Evolution API não configurada. Configure em Configurações.');
    header('Location: /admin_settings.php');
    exit;
}

$instance = trim((string)($_POST['instance'] ?? ''));
if ($instance === '') {
    flash_set('error', 'Instance inválida.');
    header('Location: /admin_whatsapp_instances.php');
    exit;
}

$evo = new EvolutionApiV1();
$evo->logoutInstance($instance);

flash_set('success', 'Logout solicitado.');
header('Location: /admin_whatsapp_instance_view.php?instance=' . urlencode($instance));
exit;
