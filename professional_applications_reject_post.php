<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = (int)($_POST['id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));

$stmt = db()->prepare('SELECT id, status FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}


if ($reason === '') {
    flash_set('error', 'Informe o motivo da reprovação.');
    header('Location: /professional_applications_reject.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT * FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

$db = db();
$db->beginTransaction();
try {
    $note = 'Reprovada: ' . $reason;
    $stmt = $db->prepare('UPDATE professional_applications SET status = \'rejected\', reviewed_by_user_id = :rid, reviewed_at = NOW(), admin_note = :note WHERE id = :id');
    $stmt->execute(['rid' => auth_user_id(), 'id' => $id, 'note' => $note]);

    audit_log('update', 'professional_applications', (string)$id, $old, ['status' => 'rejected', 'admin_note' => $note]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

$payload = [
    'application_id' => $id,
    'kind' => 'rejected',
    'message' => $reason,
];
integration_job_enqueue('evolution', 'professional_application_notify', $payload, null);
integration_job_enqueue('smtp', 'professional_application_notify_email', $payload, null);

flash_set('success', 'Candidatura reprovada. Notificações (WhatsApp/e-mail) enfileiradas.');
header('Location: /professional_applications_view.php?id=' . $id);
exit;
