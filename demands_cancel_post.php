<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /demands_list.php');
    exit;
}

$db = db();
$stmt = $db->prepare('SELECT id, status FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

$oldStatus = (string)$d['status'];
$newStatus = 'cancelado';

$db->beginTransaction();
try {
    $upd = $db->prepare('UPDATE demands SET status = :status WHERE id = :id');
    $upd->execute(['status' => $newStatus, 'id' => $id]);

    $insLog = $db->prepare(
        'INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)'
        . ' VALUES (:did, :old, :new, :uid, :note)'
    );
    $insLog->execute([
        'did' => $id,
        'old' => $oldStatus,
        'new' => $newStatus,
        'uid' => auth_user_id(),
        'note' => 'Card cancelado pelo usuário',
    ]);

    audit_log('cancel', 'demands', (string)$id, ['old_status' => $oldStatus, 'new_status' => $newStatus], null);

    $db->commit();

    flash_set('success', 'Card cancelado com sucesso.');
} catch (Exception $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao cancelar card: ' . $e->getMessage());
}

header('Location: /demands_view.php?id=' . $id);
exit;
