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

$instanceName = trim((string)($_POST['instanceName'] ?? ''));
$token = trim((string)($_POST['token'] ?? ''));
$number = trim((string)($_POST['number'] ?? ''));
$webhook = trim((string)($_POST['webhook'] ?? ''));
$qrcode = (string)($_POST['qrcode'] ?? '') === '1';

if ($instanceName === '') {
    flash_set('error', 'Informe o instanceName.');
    header('Location: /admin_whatsapp_instances.php');
    exit;
}

$payload = [
    'instanceName' => $instanceName,
    'token' => $token !== '' ? $token : null,
    'qrcode' => $qrcode,
    'number' => $number !== '' ? $number : null,
    'integration' => 'WHATSAPP-BAILEYS',
];

if ($webhook !== '') {
    $payload['webhook'] = $webhook;
    $payload['webhook_by_events'] = true;
    $payload['events'] = [
        'QRCODE_UPDATED',
        'MESSAGES_UPSERT',
        'CHATS_UPSERT',
        'CONNECTION_UPDATE',
        'GROUPS_UPSERT',
        'GROUP_PARTICIPANTS_UPDATE',
    ];
}

$evo = new EvolutionApiV1();
$res = $evo->createInstanceBasic($payload);

if ((int)$res['status'] < 200 || (int)$res['status'] >= 300) {
    flash_set('error', 'Falha ao criar instância.');
    header('Location: /admin_settings.php');
    exit;
}

// Salvar instância no banco de dados
$userId = auth_user_id();
$stmt = db()->prepare('
    INSERT INTO whatsapp_instances (instance_name, token, owner_number, webhook_url, created_by)
    VALUES (:instance_name, :token, :owner_number, :webhook_url, :created_by)
    ON DUPLICATE KEY UPDATE
        token = VALUES(token),
        owner_number = VALUES(owner_number),
        webhook_url = VALUES(webhook_url),
        updated_at = CURRENT_TIMESTAMP
');
$stmt->execute([
    'instance_name' => $instanceName,
    'token' => $token !== '' ? $token : null,
    'owner_number' => $number !== '' ? $number : null,
    'webhook_url' => $webhook !== '' ? $webhook : null,
    'created_by' => $userId
]);

flash_set('success', 'Instância criada e salva no sistema.');
header('Location: /admin_settings.php');
exit;
