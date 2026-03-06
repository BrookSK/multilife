<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

$title = trim((string)($_POST['title'] ?? ''));
$city = trim((string)($_POST['location_city'] ?? ''));
$state = strtoupper(trim((string)($_POST['location_state'] ?? '')));
$specialty = trim((string)($_POST['specialty'] ?? ''));
$originEmail = trim((string)($_POST['origin_email'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));

if ($title === '') {
    flash_set('error', 'Informe o título.');
    header('Location: /demands_edit.php?id=' . $id);
    exit;
}

if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
    flash_set('error', 'UF inválida.');
    header('Location: /demands_edit.php?id=' . $id);
    exit;
}

if ($originEmail !== '' && !filter_var($originEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail de origem inválido.');
    header('Location: /demands_edit.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('UPDATE demands SET title = :t, location_city = :c, location_state = :s, specialty = :sp, origin_email = :o, description = :d WHERE id = :id');
$stmt->execute([
    't' => $title,
    'c' => $city !== '' ? $city : null,
    's' => $state !== '' ? $state : null,
    'sp' => $specialty !== '' ? $specialty : null,
    'o' => $originEmail !== '' ? $originEmail : null,
    'd' => $description !== '' ? $description : null,
    'id' => $id,
]);

audit_log('update', 'demands', (string)$id, $old, ['title' => $title, 'location_city' => $city, 'location_state' => $state, 'specialty' => $specialty, 'origin_email' => $originEmail]);

page_history_log(
    '/demands_view.php?id=' . $id,
    'Demanda',
    'update',
    'Atualizou demanda: ' . $title,
    'demand',
    $id
);

flash_set('success', 'Demanda atualizada.');
header('Location: /demands_view.php?id=' . $id);
exit;
