<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$instance = trim((string)($_POST['instance'] ?? ''));
$number = trim((string)($_POST['number'] ?? ''));
$text = trim((string)($_POST['text'] ?? ''));

if ($instance === '' || $number === '' || $text === '') {
    flash_set('error', 'Preencha instance, number e texto.');
    header('Location: /admin_whatsapp_instance_view.php?instance=' . urlencode($instance));
    exit;
}

$evo = new EvolutionApiV1(null, null, $instance);
$res = $evo->sendText($number, $text);

if ((int)$res['status'] < 200 || (int)$res['status'] >= 300) {
    flash_set('error', 'Falha ao enviar mensagem.');
    header('Location: /admin_whatsapp_instance_view.php?instance=' . urlencode($instance));
    exit;
}

flash_set('success', 'Mensagem enviada.');
header('Location: /admin_whatsapp_instance_view.php?instance=' . urlencode($instance));
exit;
