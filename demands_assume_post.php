<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = (int)($_POST['id'] ?? 0);
$uid = auth_user_id();

$stmt = db()->prepare('SELECT id, status, assumed_by_user_id FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

if ((string)$d['status'] === 'cancelado') {
    flash_set('error', 'Demanda cancelada não pode ser assumida.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

if ($d['assumed_by_user_id'] !== null && (int)$d['assumed_by_user_id'] !== (int)$uid) {
    flash_set('error', 'Esta demanda já está assumida por outro usuário.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('UPDATE demands SET assumed_by_user_id = :uid, assumed_at = NOW(), status = IF(status = \'aguardando_captacao\', \'em_captacao\', status) WHERE id = :id');
$stmt->execute(['uid' => $uid, 'id' => $id]);

audit_log('update', 'demands_assume', (string)$id, ['assumed_by_user_id' => $d['assumed_by_user_id']], ['assumed_by_user_id' => $uid]);

flash_set('success', 'Demanda assumida.');
header('Location: /demands_view.php?id=' . $id);
exit;
