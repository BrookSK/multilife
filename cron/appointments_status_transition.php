<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Job CRON para transição automática de status de agendamentos
// Módulo 8.3: pendente_formulario → atrasado (48h) → revisao_admin (7 dias)

$db = db();

// 1. Transição: pendente_formulario → atrasado (prazo de 48h expirado)
$stmt = $db->query(
    "SELECT a.id, a.status, pd.due_at
     FROM appointments a
     INNER JOIN professional_documentations pd ON pd.appointment_id = a.id
     WHERE a.status = 'pendente_formulario'
       AND pd.status IN ('draft')
       AND pd.due_at < NOW()"
);
$overdueAppointments = $stmt->fetchAll();

foreach ($overdueAppointments as $appt) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE appointments SET status = :st WHERE id = :id');
        $stmt->execute(['st' => 'atrasado', 'id' => (int)$appt['id']]);

        $stmt = $db->prepare('INSERT INTO appointment_status_logs (appointment_id, old_status, new_status, user_id, note) VALUES (:aid, :os, :ns, NULL, :note)');
        $stmt->execute([
            'aid' => (int)$appt['id'],
            'os' => 'pendente_formulario',
            'ns' => 'atrasado',
            'note' => 'Transição automática: prazo de 48h expirado',
        ]);

        audit_log('update', 'appointments_status', (string)$appt['id'], ['status' => 'pendente_formulario'], ['status' => 'atrasado']);

        $db->commit();
        echo "Agendamento #{$appt['id']}: pendente_formulario → atrasado\n";
    } catch (Throwable $e) {
        $db->rollBack();
        echo "ERRO ao atualizar agendamento #{$appt['id']}: " . $e->getMessage() . "\n";
    }
}

// 2. Transição: atrasado → revisao_admin (7 dias sem ação do profissional)
$stmt = $db->query(
    "SELECT a.id, a.status, pd.due_at
     FROM appointments a
     INNER JOIN professional_documentations pd ON pd.appointment_id = a.id
     WHERE a.status = 'atrasado'
       AND pd.status IN ('draft')
       AND pd.due_at < DATE_SUB(NOW(), INTERVAL 5 DAY)"
);
$criticalAppointments = $stmt->fetchAll();

foreach ($criticalAppointments as $appt) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE appointments SET status = :st WHERE id = :id');
        $stmt->execute(['st' => 'revisao_admin', 'id' => (int)$appt['id']]);

        $stmt = $db->prepare('INSERT INTO appointment_status_logs (appointment_id, old_status, new_status, user_id, note) VALUES (:aid, :os, :ns, NULL, :note)');
        $stmt->execute([
            'aid' => (int)$appt['id'],
            'os' => 'atrasado',
            'ns' => 'revisao_admin',
            'note' => 'Transição automática: 7 dias sem ação do profissional',
        ]);

        // Cria pending_item para admin intervir
        $stmt = $db->prepare(
            "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)
             VALUES ('appointment_review','open',:title,:detail,'appointments',:rid,NULL)"
        );
        $stmt->execute([
            'title' => 'Agendamento #' . (int)$appt['id'] . ' requer revisão administrativa',
            'detail' => 'Profissional não enviou formulário após 7 dias. Verificar situação.',
            'rid' => (int)$appt['id'],
        ]);

        audit_log('update', 'appointments_status', (string)$appt['id'], ['status' => 'atrasado'], ['status' => 'revisao_admin']);

        $db->commit();
        echo "Agendamento #{$appt['id']}: atrasado → revisao_admin (pending_item criado)\n";
    } catch (Throwable $e) {
        $db->rollBack();
        echo "ERRO ao atualizar agendamento #{$appt['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Job concluído: " . count($overdueAppointments) . " atrasados, " . count($criticalAppointments) . " em revisão admin.\n";
