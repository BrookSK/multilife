<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

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
    header('Location: /admin_whatsapp_instances.php');
    exit;
}

flash_set('success', 'Instância criada.');
header('Location: /admin_whatsapp_instance_view.php?instance=' . urlencode($instanceName));
exit;
