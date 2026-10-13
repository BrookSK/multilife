<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * CRON: Restaurar coberturas (substituição por tempo determinado) encerradas.
 *
 * Para cada cobertura com status 'active' cujo end_date já passou, devolve o
 * atendimento ao profissional ORIGINAL (dados guardados em assignment_coverages)
 * e religa as sessões pendentes a ele. As notificações usam os eventos neutros
 * (professional_substituted / professional_removed), sem citar "substituição".
 *
 * Rodar diariamente (ex.: 1x por dia de madrugada).
 *
 * Config:
 * - coverage.restore_enabled: 1/0 (padrão 1)
 */

$enabled = (int)admin_setting_get('coverage.restore_enabled', '1');
if ($enabled !== 1) {
    echo "Restore de coberturas desabilitado.\n";
    exit;
}

$db = db();

// A tabela pode não existir ainda em ambientes antigos.
try {
    $db->query("SELECT 1 FROM assignment_coverages LIMIT 1");
} catch (Throwable $e) {
    echo "OK: tabela assignment_coverages ausente (nada a fazer).\n";
    exit;
}

$today = date('Y-m-d');

$stmt = $db->prepare(
    "SELECT * FROM assignment_coverages
     WHERE status = 'active' AND end_date < :today
     ORDER BY end_date ASC, id ASC
     LIMIT 100"
);
$stmt->execute(['today' => $today]);
$coverages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($coverages) === 0) {
    echo "OK: nenhuma cobertura a restaurar.\n";
    exit;
}

$restored = 0;
$errors = 0;

foreach ($coverages as $cov) {
    $covId = (int)$cov['id'];
    $assignmentId = (int)$cov['assignment_id'];
    $originalProfId = (int)($cov['original_professional_user_id'] ?? 0);

    if ($assignmentId <= 0 || $originalProfId <= 0) {
        // Dados insuficientes para restaurar — apenas finaliza para não repetir.
        try {
            $db->prepare("UPDATE assignment_coverages SET status = 'finished', restored_at = NOW() WHERE id = :id")
                ->execute(['id' => $covId]);
        } catch (Throwable $e) {}
        continue;
    }

    $db->beginTransaction();
    try {
        // Confirmar que o atendimento ainda está com o profissional de cobertura.
        // Se já foi trocado manualmente, não sobrescreve: apenas finaliza a cobertura.
        $assStmt = $db->prepare("SELECT professional_user_id FROM patient_assignments WHERE id = :id LIMIT 1");
        $assStmt->execute(['id' => $assignmentId]);
        $currentProf = (int)($assStmt->fetchColumn() ?: 0);
        $coverProfId = (int)($cov['cover_professional_user_id'] ?? 0);

        if ($currentProf !== 0 && $coverProfId !== 0 && $currentProf !== $coverProfId) {
            // Outro profissional assumiu depois — não reverter.
            $db->prepare("UPDATE assignment_coverages SET status = 'finished', restored_at = NOW() WHERE id = :id")
                ->execute(['id' => $covId]);
            $db->commit();
            error_log("[COVERAGE] Cobertura #$covId: atendimento já mudou de profissional, apenas finalizada.");
            continue;
        }

        // Restaurar dados ORIGINAIS no assignment.
        $upd = $db->prepare(
            "UPDATE patient_assignments
             SET professional_user_id = :uid,
                 professional_remote_jid = :jid,
                 agreed_value = COALESCE(:av, agreed_value),
                 session_frequency = COALESCE(NULLIF(:freq, ''), session_frequency)
             WHERE id = :id"
        );
        $upd->execute([
            'uid' => $originalProfId,
            'jid' => $cov['original_professional_jid'] ?? null,
            'av' => $cov['original_agreed_value'] !== null ? (float)$cov['original_agreed_value'] : null,
            'freq' => (string)($cov['original_frequency'] ?? ''),
            'id' => $assignmentId,
        ]);

        // Religar sessões pendentes ao profissional original (as futuras).
        try {
            $db->prepare(
                "UPDATE billing_document_requirements
                 SET professional_user_id = :uid
                 WHERE assignment_id = :aid AND status = 'pending'
                   AND (session_date IS NULL OR session_date >= CURDATE())"
            )->execute(['uid' => $originalProfId, 'aid' => $assignmentId]);
        } catch (Throwable $e) {
            error_log("[COVERAGE] Cobertura #$covId: erro ao religar sessões: " . $e->getMessage());
        }

        // Marcar cobertura como finalizada.
        $db->prepare("UPDATE assignment_coverages SET status = 'finished', restored_at = NOW() WHERE id = :id")
            ->execute(['id' => $covId]);

        $db->commit();
        $restored++;
        error_log("[COVERAGE] Cobertura #$covId restaurada: atendimento #$assignmentId voltou ao profissional #$originalProfId.");
    } catch (Throwable $e) {
        $db->rollBack();
        $errors++;
        error_log("[COVERAGE] Erro ao restaurar cobertura #$covId: " . $e->getMessage());
        continue;
    }

    // Notificação NEUTRA (fora da transação; best-effort). Avisa o profissional
    // original que o atendimento está novamente sob sua responsabilidade.
    try {
        $info = $db->prepare(
            "SELECT pa.id, pa.specialty, pa.service_type, p.full_name AS patient_name, p.id AS patient_id,
                    p.whatsapp AS patient_phone, u.name AS prof_name, u.phone AS prof_phone, u.email AS prof_email
             FROM patient_assignments pa
             INNER JOIN patients p ON p.id = pa.patient_id
             LEFT JOIN users u ON u.id = pa.professional_user_id
             WHERE pa.id = :id LIMIT 1"
        );
        $info->execute(['id' => $assignmentId]);
        $row = $info->fetch(PDO::FETCH_ASSOC);

        if ($row && class_exists('WhatsAppEventDispatcher')) {
            $dispatcher = new WhatsAppEventDispatcher();
            $eventData = [
                'attendance_id' => $assignmentId,
                'attendance_date' => date('d/m/Y'),
                'patient_id' => (int)($row['patient_id'] ?? 0),
                'patient_name' => (string)($row['patient_name'] ?? ''),
                'patient_phone' => '',
                'professional_id' => $originalProfId,
                'professional_name' => (string)($row['prof_name'] ?? ''),
                'professional_phone' => (string)($row['prof_phone'] ?? ''),
                'professional_email' => (string)($row['prof_email'] ?? ''),
                'specialty' => (string)($row['specialty'] ?? $row['service_type'] ?? ''),
                // Sem links: aviso simples de retorno de responsabilidade.
                'attendance_link' => '',
                'appointment_link' => '',
                'registration_link' => '',
                'documents_link' => '',
            ];
            // Reaproveita o evento neutro de designação (texto já não cita substituição).
            $dispatcher->dispatch('professional_substituted', $eventData);
        }
    } catch (Throwable $e) {
        error_log("[COVERAGE] Cobertura #$covId: erro ao notificar retorno: " . $e->getMessage());
    }
}

echo 'OK: coverages_restored=' . $restored . ' errors=' . $errors . "\n";
