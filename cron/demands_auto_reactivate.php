<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * CRON: Reativação automática de demandas arquivadas
 * 
 * Demandas arquivadas que recebem uma nova interação (reação de profissional,
 * nova mensagem vinculada) são reativadas automaticamente.
 */

$db = db();

// Buscar demandas arquivadas que receberam nova reação após o arquivamento
$stmt = $db->query("
    SELECT d.id, d.title, d.archived_at
    FROM demands d
    WHERE d.archived_at IS NOT NULL
      AND d.status IN ('em_captacao', 'aguardando_captacao')
      AND EXISTS (
          SELECT 1 FROM demand_interested_professionals dip 
          WHERE dip.demand_id = d.id AND dip.reacted_at > d.archived_at
      )
");
$demands = $stmt->fetchAll();

if (count($demands) === 0) {
    echo "OK: nenhuma demanda para reativar\n";
    exit;
}

$updReactivate = $db->prepare("
    UPDATE demands 
    SET archived_at = NULL, 
        archived_reason = NULL,
        status = 'em_captacao'
    WHERE id = :id
");

$insLog = $db->prepare("
    INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) 
    VALUES (:did, 'arquivado', 'em_captacao', NULL, :note)
");

$reactivated = 0;
foreach ($demands as $d) {
    $updReactivate->execute(['id' => (int)$d['id']]);
    
    $insLog->execute([
        'did' => (int)$d['id'],
        'note' => 'Reativado automaticamente: nova interação de profissional recebida',
    ]);
    
    $reactivated++;
}

echo 'OK: reactivated=' . $reactivated . "\n";
