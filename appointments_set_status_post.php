<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$id = (int)($_POST['id'] ?? 0);
$newStatus = (string)($_POST['status'] ?? '');
$note = trim((string)($_POST['note'] ?? ''));

$allowed = ['agendado','pendente_formulario','realizado','atrasado','cancelado','revisao_admin'];
if (!in_array($newStatus, $allowed, true)) {
    flash_set('error', 'Status inválido.');
    header('Location: /appointments_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT id, status FROM appointments WHERE id = :id');
$stmt->execute(['id' => $id]);
$a = $stmt->fetch();
if (!$a) {
    flash_set('error', 'Agendamento não encontrado.');
    header('Location: /appointments_list.php');
    exit;
}

$oldStatus = (string)$a['status'];
if ($oldStatus === $newStatus) {
    flash_set('success', 'Status já estava definido.');
    header('Location: /appointments_view.php?id=' . $id);
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE appointments SET status = :st WHERE id = :id');
    $stmt->execute(['st' => $newStatus, 'id' => $id]);

    $stmt = $db->prepare('INSERT INTO appointment_status_logs (appointment_id, old_status, new_status, user_id, note) VALUES (:aid, :os, :ns, :uid, :note)');
    $stmt->execute([
        'aid' => $id,
        'os' => $oldStatus,
        'ns' => $newStatus,
        'uid' => auth_user_id(),
        'note' => $note !== '' ? $note : null,
    ]);

    audit_log('update', 'appointments_status', (string)$id, ['status' => $oldStatus], ['status' => $newStatus]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Status atualizado.');
header('Location: /appointments_view.php?id=' . $id);
exit;
