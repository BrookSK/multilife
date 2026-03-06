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

error_log("[ASSUME_DEMAND] Iniciando - Demand ID: $id, User ID: $uid");

$stmt = db()->prepare('UPDATE demands SET assumed_by_user_id = :uid, assumed_at = NOW(), status = IF(status = \'aguardando_captacao\', \'em_captacao\', status) WHERE id = :id');
$result = $stmt->execute(['uid' => $uid, 'id' => $id]);

error_log("[ASSUME_DEMAND] UPDATE executado - Result: " . ($result ? 'true' : 'false') . ", Rows affected: " . $stmt->rowCount());

// Verificar se realmente atualizou
$verifyStmt = db()->prepare('SELECT assumed_by_user_id, assumed_at, status FROM demands WHERE id = :id');
$verifyStmt->execute(['id' => $id]);
$verify = $verifyStmt->fetch();
error_log("[ASSUME_DEMAND] Verificação após UPDATE - assumed_by: " . ($verify['assumed_by_user_id'] ?? 'NULL') . ", status: " . ($verify['status'] ?? 'NULL'));

audit_log('update', 'demands_assume', (string)$id, ['assumed_by_user_id' => $d['assumed_by_user_id']], ['assumed_by_user_id' => $uid]);

page_history_log(
    '/demands_view.php?id=' . $id,
    'Demanda',
    'assign',
    'Assumiu a demanda',
    'demand',
    $id
);

flash_set('success', 'Demanda assumida com sucesso!');
error_log("[ASSUME_DEMAND] Redirecionando para /demands_view.php?id=$id");
header('Location: /demands_view.php?id=' . $id);
exit;
