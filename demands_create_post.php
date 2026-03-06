<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$title = trim((string)($_POST['title'] ?? ''));
$city = trim((string)($_POST['location_city'] ?? ''));
$state = strtoupper(trim((string)($_POST['location_state'] ?? '')));
$specialty = trim((string)($_POST['specialty'] ?? ''));
$originEmail = trim((string)($_POST['origin_email'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$status = (string)($_POST['status'] ?? 'aguardando_captacao');

$allowedStatuses = ['aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'aguardando_captacao';
}

if ($title === '') {
    flash_set('error', 'Informe o título.');
    header('Location: /demands_create.php');
    exit;
}

if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
    flash_set('error', 'UF inválida.');
    header('Location: /demands_create.php');
    exit;
}

if ($originEmail !== '' && !filter_var($originEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail de origem inválido.');
    header('Location: /demands_create.php');
    exit;
}

$stmt = db()->prepare('INSERT INTO demands (title, location_city, location_state, specialty, description, origin_email, status) VALUES (:t,:c,:s,:sp,:d,:o,:st)');
$stmt->execute([
    't' => $title,
    'c' => $city !== '' ? $city : null,
    's' => $state !== '' ? $state : null,
    'sp' => $specialty !== '' ? $specialty : null,
    'd' => $description !== '' ? $description : null,
    'o' => $originEmail !== '' ? $originEmail : null,
    'st' => $status,
]);

$id = (string)db()->lastInsertId();

$stmt = db()->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, NULL, :ns, :uid, :note)');
$stmt->execute([
    'did' => $id,
    'ns' => $status,
    'uid' => auth_user_id(),
    'note' => 'criação manual',
]);

audit_log('create', 'demands', $id, null, ['title' => $title, 'status' => $status]);

page_history_log(
    '/demands_list.php',
    'Captação',
    'create',
    'Criou nova demanda: ' . $title,
    'demand',
    (int)$id
);

flash_set('success', 'Card criado.');
header('Location: /demands_view.php?id=' . urlencode($id));
exit;
