<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, status FROM appointments WHERE id = :id');
$stmt->execute(['id' => $id]);
$a = $stmt->fetch();
if (!$a) {
    flash_set('error', 'Agendamento não encontrado.');
    header('Location: /appointments_list.php');
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare("UPDATE appointments SET status = 'cancelado', cancel_reason = 'cancelado manualmente' WHERE id = :id");
    $stmt->execute(['id' => $id]);

    $stmt = $db->prepare('INSERT INTO appointment_status_logs (appointment_id, old_status, new_status, user_id, note) VALUES (:aid, :os, :ns, :uid, :note)');
    $stmt->execute([
        'aid' => $id,
        'os' => (string)$a['status'],
        'ns' => 'cancelado',
        'uid' => auth_user_id(),
        'note' => 'cancelado',
    ]);

    audit_log('update', 'appointments_cancel', (string)$id, ['status' => (string)$a['status']], ['status' => 'cancelado']);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Agendamento cancelado.');
header('Location: /appointments_view.php?id=' . $id);
exit;
