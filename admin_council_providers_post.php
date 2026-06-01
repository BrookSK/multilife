<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$settings = [];

// Consultar.IO
$settings['council_provider.consultario.api_key']  = trim((string)($_POST['consultario_api_key'] ?? ''));
$settings['council_provider.consultario.base_url'] = trim((string)($_POST['consultario_base_url'] ?? ''));
$settings['council_provider.consultario.enabled']  = isset($_POST['consultario_enabled']) ? '1' : '0';
$settings['council_provider.consultario.priority'] = (string)max(1, (int)($_POST['consultario_priority'] ?? 10));

// Infosimples
$settings['council_provider.infosimples.api_token'] = trim((string)($_POST['infosimples_api_token'] ?? ''));
$settings['council_provider.infosimples.base_url']  = trim((string)($_POST['infosimples_base_url'] ?? ''));
$settings['council_provider.infosimples.enabled']   = isset($_POST['infosimples_enabled']) ? '1' : '0';
$settings['council_provider.infosimples.priority']  = (string)max(1, (int)($_POST['infosimples_priority'] ?? 20));

// Portal Direto
$settings['council_provider.portal_direct.enabled']  = isset($_POST['portal_direct_enabled']) ? '1' : '0';
$settings['council_provider.portal_direct.priority'] = (string)max(1, (int)($_POST['portal_direct_priority'] ?? 99));

// Não sobrescreve API key/token se o campo veio vazio (preserva valor existente)
if ($settings['council_provider.consultario.api_key'] === '') {
    unset($settings['council_provider.consultario.api_key']);
}
if ($settings['council_provider.infosimples.api_token'] === '') {
    unset($settings['council_provider.infosimples.api_token']);
}

admin_settings_set_many($settings, (int)auth_user_id());

audit_log('update', 'admin_settings', 'council_providers', null, ['keys' => array_keys($settings)]);

flash_set('success', 'Configurações de provedores salvas com sucesso.');
header('Location: /admin_council_providers_settings.php');
exit;
