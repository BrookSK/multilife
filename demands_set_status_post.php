<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = (int)($_POST['id'] ?? 0);
$newStatus = (string)($_POST['status'] ?? '');
$note = trim((string)($_POST['note'] ?? ''));

$allowed = ['aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado'];
if (!in_array($newStatus, $allowed, true)) {
    flash_set('error', 'Status inválido.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT id, status FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

$oldStatus = (string)$d['status'];
if ($oldStatus === $newStatus) {
    flash_set('success', 'Status já estava definido.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('UPDATE demands SET status = :st WHERE id = :id');
$stmt->execute(['st' => $newStatus, 'id' => $id]);

$stmt = db()->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, :os, :ns, :uid, :note)');
$stmt->execute([
    'did' => $id,
    'os' => $oldStatus,
    'ns' => $newStatus,
    'uid' => auth_user_id(),
    'note' => $note !== '' ? $note : null,
]);

audit_log('update', 'demands_status', (string)$id, ['status' => $oldStatus], ['status' => $newStatus]);

flash_set('success', 'Status atualizado.');
header('Location: /demands_view.php?id=' . $id);
exit;
