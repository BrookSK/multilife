<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$instanceName = trim($_POST['instance_name'] ?? '');

if (empty($instanceName)) {
    flash_set('error', 'Nome da instância é obrigatório.');
    header('Location: /evolution_instance_create.php');
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
    $ch = curl_init($baseUrl . '/instance/create');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'instanceName' => $instanceName,
        'qrcode' => true,
        'integration' => 'WHATSAPP-BAILEYS'
    ]));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 201 || $httpCode === 200) {
        // Salvar como instância padrão se for a primeira
        $currentInstance = admin_setting_get('evolution.instance');
        if (empty($currentInstance)) {
            $db = db();
            $stmt = $db->prepare('UPDATE admin_settings SET setting_value = :val WHERE setting_key = :key');
            $stmt->execute(['val' => $instanceName, 'key' => 'evolution.instance']);
        }
        
        flash_set('success', 'Instância criada com sucesso! Agora conecte via QR Code.');
        header('Location: /evolution_qrcode.php?instance=' . urlencode($instanceName));
        exit;
    } else {
        flash_set('error', 'Erro ao criar instância. Código HTTP: ' . $httpCode);
        header('Location: /evolution_instance_create.php');
        exit;
    }
} catch (Exception $e) {
    flash_set('error', 'Erro ao criar instância: ' . $e->getMessage());
    header('Location: /evolution_instance_create.php');
    exit;
}
