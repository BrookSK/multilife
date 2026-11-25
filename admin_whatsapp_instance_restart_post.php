<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

// Verificar se API do WhatsApp está configurada
$baseUrl = wa_api_base_url();
$apiKey = wa_api_key();

if ($baseUrl === '' || $apiKey === '') {
    flash_set('error', 'API do WhatsApp não configurada. Configure em Configurações.');
    header('Location: /admin_settings.php');
    exit;
}

$instance = trim((string)($_POST['instance'] ?? ''));
if ($instance === '') {
    flash_set('error', 'Instance inválida.');
    header('Location: /admin_whatsapp_instances.php');
    exit;
}

$waApi = new WhatsAppApi();
$waApi->restartInstance($instance);

flash_set('success', 'Restart solicitado.');
header('Location: /admin_whatsapp_instance_view.php?instance=' . urlencode($instance));
exit;
