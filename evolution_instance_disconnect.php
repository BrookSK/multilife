<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$instanceName = trim($_GET['instance'] ?? '');

if (empty($instanceName)) {
    flash_set('error', 'Nome da instância não informado.');
    header('Location: /evolution_instances.php');
    exit;
}

// Permitir desconectar apenas a instância "multilife"
if ($instanceName !== 'multilife') {
    flash_set('error', 'Apenas a instância "multilife" pode ser desconectada por aqui.');
    header('Location: /evolution_instances.php');
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');

if (empty($baseUrl) || empty($apiKey)) {
    flash_set('error', 'Configure as credenciais da Evolution API primeiro.');
    header('Location: /admin_settings.php');
    exit;
}

try {
    $ch = curl_init($baseUrl . '/instance/logout/' . urlencode($instanceName));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        flash_set('success', 'Instância desconectada com sucesso! Você pode reconectá-la gerando um novo QR Code.');
    } else {
        flash_set('error', 'Erro ao desconectar instância. Código HTTP: ' . $httpCode);
    }
} catch (Exception $e) {
    flash_set('error', 'Erro ao desconectar instância: ' . $e->getMessage());
}

header('Location: /evolution_instances.php');
exit;
