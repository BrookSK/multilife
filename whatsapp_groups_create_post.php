<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$name = trim((string)($_POST['name'] ?? ''));
$evolutionGroupJid = trim((string)($_POST['evolution_group_jid'] ?? ''));
$contactsCountRaw = trim((string)($_POST['contacts_count'] ?? ''));
$specialty = trim((string)($_POST['specialty'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$state = strtoupper(trim((string)($_POST['state'] ?? '')));
$status = (string)($_POST['status'] ?? 'active');

if ($name === '') {
    flash_set('error', 'Informe o nome.');
    header('Location: /whatsapp_groups_create.php');
    exit;
}

if ($evolutionGroupJid === '') {
    flash_set('error', 'Informe o Evolution Group JID.');
    header('Location: /whatsapp_groups_create.php');
    exit;
}

$contactsCount = null;
if ($contactsCountRaw !== '') {
    $n = (int)$contactsCountRaw;
    if ($n < 0) {
        $n = 0;
    }
    $contactsCount = $n;
}

if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
    flash_set('error', 'UF inválida.');
    header('Location: /whatsapp_groups_create.php');
    exit;
}

if (!in_array($status, ['active','inactive'], true)) {
    $status = 'active';
}

$stmt = db()->prepare('INSERT INTO whatsapp_groups (name, evolution_group_jid, contacts_count, specialty, city, state, status) VALUES (:n,:jid,:cc,:sp,:c,:s,:st)');
$stmt->execute([
    'n' => $name,
    'jid' => $evolutionGroupJid,
    'cc' => $contactsCount,
    'sp' => $specialty !== '' ? $specialty : null,
    'c' => $city !== '' ? $city : null,
    's' => $state !== '' ? $state : null,
    'st' => $status,
]);

$id = (string)db()->lastInsertId();
audit_log('create', 'whatsapp_groups', $id, null, ['name' => $name, 'status' => $status]);

flash_set('success', 'Grupo criado.');
header('Location: /whatsapp_groups_list.php');
exit;
