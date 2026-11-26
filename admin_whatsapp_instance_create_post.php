<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');
wa_cloud_page_guard('A criação de instância com QR Code');

// Verificar se API do WhatsApp está configurada
$baseUrl = wa_api_base_url();
$apiKey = wa_api_key();

if ($baseUrl === '' || $apiKey === '') {
    flash_set('error', 'API do WhatsApp não configurada. Configure em Configurações.');
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

// Mesmo número em duas instâncias faz as conexões disputarem a sessão (mensagens somem)
if ($number !== '') {
    $sameNumber = whatsapp_instances_with_number($number, $instanceName);
    if ($sameNumber) {
        flash_set('error', 'O número ' . whatsapp_number_display($number) . ' já está na instância "' . $sameNumber[0]['label']
            . '". Use essa instância (Reconectar) ou remova-a antes de criar outra com o mesmo número.');
        header('Location: /admin_whatsapp_instances.php');
        exit;
    }
}

$payload = [
    'instanceName' => $instanceName,
    'token' => $token !== '' ? $token : null,
    'qrcode' => $qrcode,
    'number' => $number !== '' ? $number : null,
    'integration' => 'WHATSAPP-BAILEYS',
];

if ($webhook !== '') {
    // Mesma configuração das demais instâncias (app/whatsapp_webhook_config.php,
    // modelo helpdeskON): URL única, base64 ligado e só os eventos usados.
    $payload['webhook'] = $webhook;
    $payload['webhook_by_events'] = false;
    $payload['webhookByEvents'] = false;
    $payload['webhook_base64'] = true;
    $payload['webhookBase64'] = true;
    $payload['events'] = WHATSAPP_WEBHOOK_EVENTS;
}

$waApi = new WhatsAppApi();
$res = $waApi->createInstanceBasic($payload);

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
