<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Job CRON para detectar ciclos recorrentes finalizados e criar pendência para captador
// Módulo 8.4: Renovação e Encerramento de Ciclos

$db = db();

// Detecta agendamentos recorrentes que estão próximos do fim do ciclo
// Lógica simplificada: verifica se first_at + duração estimada está próximo
// Para recorrência semanal/mensal, assume 30 dias como ciclo padrão se não especificado

$stmt = $db->query(
    "SELECT a.id, a.patient_id, a.professional_user_id, a.specialty, a.first_at, a.recurrence_type, a.recurrence_rule, a.value_per_session,
            p.full_name AS patient_name,
            u.name AS professional_name
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     INNER JOIN users u ON u.id = a.professional_user_id
     WHERE a.status IN ('realizado')
       AND a.recurrence_type IN ('weekly','monthly','custom')
       AND a.first_at < DATE_SUB(NOW(), INTERVAL 25 DAY)
       AND NOT EXISTS (
           SELECT 1 FROM pending_items pi
           WHERE pi.type = 'appointment_cycle_renewal'
             AND pi.related_table = 'appointments'
             AND pi.related_id = a.id
             AND pi.status IN ('open','in_progress')
       )"
);
$cyclesNearEnd = $stmt->fetchAll();

foreach ($cyclesNearEnd as $appt) {
    $db->beginTransaction();
    try {
        // Busca captador responsável (quem criou o agendamento ou admin)
        $stmt = $db->prepare('SELECT created_by_user_id FROM appointments WHERE id = :id');
        $stmt->execute(['id' => (int)$appt['id']]);
        $creator = $stmt->fetch();
        $captadorId = $creator ? (int)$creator['created_by_user_id'] : null;

        $stmt = $db->prepare(
            "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)
             VALUES ('appointment_cycle_renewal','open',:title,:detail,'appointments',:rid,:uid)"
        );
        $stmt->execute([
            'title' => 'Renovar ou encerrar ciclo: ' . h((string)$appt['patient_name']) . ' / ' . h((string)$appt['professional_name']),
            'detail' => 'Agendamento #' . (int)$appt['id'] . ' | Especialidade: ' . h((string)$appt['specialty']) . ' | Recorrência: ' . h((string)$appt['recurrence_type']),
            'rid' => (int)$appt['id'],
            'uid' => $captadorId,
        ]);

        $db->commit();
        echo "Pendência de renovação criada para agendamento #{$appt['id']}\n";
    } catch (Throwable $e) {
        $db->rollBack();
        echo "ERRO ao criar pendência para agendamento #{$appt['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Job concluído: " . count($cyclesNearEnd) . " ciclos detectados para renovação.\n";
