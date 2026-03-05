<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$reactivationReason = trim((string)($_POST['reactivation_reason'] ?? ''));

if ($id <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /demands_list.php');
    exit;
}

if ($reactivationReason === '') {
    flash_set('error', 'A justificativa da reativação é obrigatória.');
    header('Location: /demands_reactivate.php?id=' . $id);
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

if ($d['status'] !== 'cancelado') {
    flash_set('error', 'Esta demanda não está cancelada.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

$oldStatus = (string)$d['status'];
$newStatus = 'em_captacao';

$db->beginTransaction();
try {
    $upd = $db->prepare('UPDATE demands SET status = :status, reactivation_reason = :reason, reactivated_at = NOW(), reactivated_by_user_id = :uid WHERE id = :id');
    $upd->execute([
        'status' => $newStatus,
        'reason' => $reactivationReason,
        'uid' => auth_user_id(),
        'id' => $id
    ]);

    $insLog = $db->prepare(
        'INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)'
        . ' VALUES (:did, :old, :new, :uid, :note)'
    );
    $insLog->execute([
        'did' => $id,
        'old' => $oldStatus,
        'new' => $newStatus,
        'uid' => auth_user_id(),
        'note' => 'Reativado: ' . $reactivationReason,
    ]);

    audit_log('reactivate', 'demands', (string)$id, ['old_status' => $oldStatus, 'new_status' => $newStatus, 'reason' => $reactivationReason], null);

    $db->commit();

    flash_set('success', 'Card reativado com sucesso!');
} catch (Exception $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao reativar card: ' . $e->getMessage());
}

header('Location: /demands_view.php?id=' . $id);
exit;
