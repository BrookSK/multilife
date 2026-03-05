<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = (int)($_POST['id'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));

$stmt = db()->prepare('SELECT * FROM appointment_value_authorizations WHERE id = :id');
$stmt->execute(['id' => $id]);
$req = $stmt->fetch();

if (!$req) {
    flash_set('error', 'Solicitação não encontrada.');
    header('Location: /appointment_value_authorizations_list.php');
    exit;
}

if ((string)$req['status'] !== 'pending') {
    flash_set('error', 'Solicitação já resolvida.');
    header('Location: /appointment_value_authorizations_view.php?id=' . $id);
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        "UPDATE appointment_value_authorizations\n"
        . "SET status = 'rejected', reviewed_by_user_id = :uid, reviewed_at = NOW(), review_note = :note\n"
        . "WHERE id = :id"
    );
    $stmt->execute(['uid' => auth_user_id(), 'note' => $note !== '' ? $note : null, 'id' => $id]);

    audit_log('update', 'appointment_value_authorizations', (string)$id, $req, ['status' => 'rejected']);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Solicitação rejeitada.');
header('Location: /appointment_value_authorizations_view.php?id=' . $id);
exit;
