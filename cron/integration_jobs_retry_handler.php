<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Job CRON para processar jobs "dead" e criar pendências TI (Módulo 12.1)
// Após 3 tentativas sem sucesso, cria pending_items para revisão técnica

$db = db();

// Busca jobs marcados como "dead" que ainda não geraram pendência TI
$stmt = $db->query(
    "SELECT ij.id, ij.provider, ij.action, ij.payload, ij.last_error, ij.attempts, ij.created_at
     FROM integration_jobs ij
     WHERE ij.status = 'dead'
       AND NOT EXISTS (
           SELECT 1 FROM pending_items pi
           WHERE pi.type = 'integration_job_failed'
             AND pi.related_table = 'integration_jobs'
             AND pi.related_id = ij.id
             AND pi.status IN ('open','in_progress')
       )"
);
$deadJobs = $stmt->fetchAll();

foreach ($deadJobs as $job) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)
             VALUES ('integration_job_failed','open',:title,:detail,'integration_jobs',:rid,NULL)"
        );
        $stmt->execute([
            'title' => 'Job de integração falhou após ' . (int)$job['attempts'] . ' tentativas',
            'detail' => 'Provider: ' . h((string)$job['provider']) . ' | Ação: ' . h((string)$job['action']) . ' | Erro: ' . h((string)($job['last_error'] ?? 'N/A')) . ' | Job #' . (int)$job['id'],
            'rid' => (int)$job['id'],
        ]);

        $db->commit();
        echo "Pendência TI criada para job #{$job['id']}\n";
    } catch (Throwable $e) {
        $db->rollBack();
        echo "ERRO ao criar pendência para job #{$job['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Job concluído: " . count($deadJobs) . " jobs dead processados.\n";
