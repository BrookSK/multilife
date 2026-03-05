<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));

if ($appointmentId <= 0) {
    flash_set('error', 'Agendamento inválido.');
    header('Location: /appointments_list.php');
    exit;
}

$stmt = db()->prepare('SELECT id FROM appointments WHERE id = :id');
$stmt->execute(['id' => $appointmentId]);
if (!$stmt->fetch()) {
    flash_set('error', 'Agendamento não encontrado.');
    header('Location: /appointments_list.php');
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        "UPDATE pending_items SET status = 'completed', completed_at = NOW()
         WHERE type = 'appointment_cycle_renewal'
           AND related_table = 'appointments'
           AND related_id = :id
           AND status = 'open'"
    );
    $stmt->execute(['id' => $appointmentId]);

    audit_log('update', 'appointments_cycle', (string)$appointmentId, null, ['action' => 'close_cycle', 'note' => $note]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Ciclo encerrado. Pendência concluída.');
header('Location: /appointments_view.php?id=' . $appointmentId);
exit;
