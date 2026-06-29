<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * CRON: Auto-arquivamento de demandas sem retorno
 * 
 * Demandas em captação que não recebem nenhuma interação (reação, resposta)
 * após X dias são arquivadas automaticamente.
 * 
 * Configurações:
 * - demands.auto_archive_enabled: 1/0
 * - demands.auto_archive_days: número de dias sem interação (padrão: 7)
 * 
 * Reativação: Se uma nova interação ocorrer (mensagem, reação), a demanda
 * pode ser reativada manualmente pelo operador.
 */

$enabled = (int)admin_setting_get('demands.auto_archive_enabled', '1');
if ($enabled !== 1) {
    echo "Auto-archive desabilitado.\n";
    exit;
}

$days = (int)admin_setting_get('demands.auto_archive_days', '7');
if ($days <= 0) {
    $days = 7;
}

$db = db();

$cutoff = (new DateTime('now'))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

// Buscar demandas em captação sem interação nos últimos X dias
// Critérios:
// - Status em_captacao ou aguardando_captacao
// - Nenhum profissional interessado (demand_interested_professionals)
// - Último dispatch foi há mais de X dias
// - Não foi assumida recentemente
$stmt = $db->prepare("
    SELECT d.id, d.title, d.status, d.created_at
    FROM demands d
    WHERE d.status IN ('em_captacao', 'aguardando_captacao')
      AND d.archived_at IS NULL
      AND d.created_at <= :cutoff
      AND NOT EXISTS (
          SELECT 1 FROM demand_interested_professionals dip 
          WHERE dip.demand_id = d.id AND dip.reacted_at > :cutoff2
      )
      AND NOT EXISTS (
          SELECT 1 FROM demand_dispatch_logs ddl 
          WHERE ddl.demand_id = d.id AND ddl.created_at > :cutoff3
      )
      AND (d.assumed_at IS NULL OR d.assumed_at <= :cutoff4)
");
$stmt->execute([
    'cutoff'  => $cutoff,
    'cutoff2' => $cutoff,
    'cutoff3' => $cutoff,
    'cutoff4' => $cutoff,
]);
$demands = $stmt->fetchAll();

if (count($demands) === 0) {
    echo "OK: nenhuma demanda para arquivar\n";
    exit;
}

$updArchive = $db->prepare("
    UPDATE demands 
    SET archived_at = NOW(), 
        archived_reason = :reason,
        assumed_by_user_id = NULL,
        assumed_at = NULL
    WHERE id = :id
");

$insLog = $db->prepare("
    INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) 
    VALUES (:did, :old, 'arquivado', NULL, :note)
");

$archived = 0;
foreach ($demands as $d) {
    $reason = 'Auto-arquivado: sem retorno após ' . $days . ' dias';
    
    $updArchive->execute([
        'reason' => $reason,
        'id' => (int)$d['id'],
    ]);
    
    $insLog->execute([
        'did' => (int)$d['id'],
        'old' => (string)$d['status'],
        'note' => $reason,
    ]);
    
    $archived++;
}

echo 'OK: archived=' . $archived . "\n";
