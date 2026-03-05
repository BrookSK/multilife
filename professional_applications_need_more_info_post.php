<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = (int)($_POST['id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));

$stmt = db()->prepare('SELECT id, status FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}


if ($message === '') {
    flash_set('error', 'Informe a mensagem para o candidato.');
    header('Location: /professional_applications_need_more_info.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT * FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'UPDATE professional_applications '
        . 'SET status = \'need_more_info\', reviewed_by_user_id = :rid, reviewed_at = NOW(), admin_note = :note '
        . 'WHERE id = :id'
    );
    $note = 'Solicitação de complemento: ' . $message;
    $stmt->execute(['rid' => auth_user_id(), 'id' => $id, 'note' => $note]);

    // Pendência interna
    $stmt = $db->prepare(
        "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)"
        . " VALUES ('professional_application_followup','open',:title,:detail,'professional_applications',:rid,:uid)"
    );
    $stmt->execute([
        'title' => 'Candidatura: complemento solicitado (#' . $id . ')',
        'detail' => mb_strimwidth($message, 0, 240, '...'),
        'rid' => $id,
        'uid' => auth_user_id(),
    ]);

    audit_log('update', 'professional_applications', (string)$id, $old, ['status' => 'need_more_info', 'admin_note' => $note]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

// Notificações (WhatsApp + e-mail) via jobs
$payload = [
    'application_id' => $id,
    'kind' => 'need_more_info',
    'message' => $message,
];
integration_job_enqueue('evolution', 'professional_application_notify', $payload, null);
integration_job_enqueue('smtp', 'professional_application_notify_email', $payload, null);

flash_set('success', 'Complemento solicitado. Notificações (WhatsApp/e-mail) enfileiradas.');
header('Location: /professional_applications_view.php?id=' . $id);
exit;
