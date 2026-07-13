<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * CRON: Auto-arquivamento de Autorizações de Propostas sem retorno
 * 
 * Propostas enviadas para operadoras/clientes que não recebem resposta
 * após X dias são arquivadas (canceladas) automaticamente.
 * 
 * Se a operadora/cliente responder o e-mail depois, o sistema desarquiva
 * automaticamente (via CRON de process_authorization_responses).
 * 
 * Configurações:
 * - demands.auto_archive_enabled: 1/0
 * - demands.auto_archive_days: número de dias sem resposta (padrão: 7)
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

// =============================================
// 1. Auto-arquivar AUTORIZAÇÕES DE PROPOSTAS sem resposta
// =============================================
// Critérios:
// - Status 'aguardando_autorizacao'
// - Enviada há mais de X dias (sent_at <= cutoff)
// - Sem resposta recebida (response_received_at IS NULL)

$stmtAuth = $db->prepare("
    SELECT ar.id, ar.demand_id, ar.operator_email, ar.sent_at, d.title
    FROM authorization_requests ar
    INNER JOIN demands d ON d.id = ar.demand_id
    WHERE ar.status = 'aguardando_autorizacao'
      AND ar.sent_at IS NOT NULL
      AND ar.sent_at <= :cutoff
      AND ar.response_received_at IS NULL
");
$stmtAuth->execute(['cutoff' => $cutoff]);
$pendingAuths = $stmtAuth->fetchAll();

$archivedAuths = 0;

if (count($pendingAuths) > 0) {
    $updAuth = $db->prepare("
        UPDATE authorization_requests 
        SET status = 'cancelada', 
            denial_reason = :reason
        WHERE id = :id
    ");
    
    $insHistory = $db->prepare("
        INSERT INTO authorization_request_history (authorization_request_id, action, note, created_at)
        VALUES (:id, 'auto_archived', :note, NOW())
    ");

    foreach ($pendingAuths as $auth) {
        $reason = 'Auto-arquivada: sem retorno da operadora/cliente após ' . $days . ' dias';
        
        try {
            $updAuth->execute([
                'reason' => $reason,
                'id' => (int)$auth['id'],
            ]);
            
            $insHistory->execute([
                'id' => (int)$auth['id'],
                'note' => $reason,
            ]);
            
            $archivedAuths++;
            error_log("[AUTO_ARCHIVE] Autorização #" . $auth['id'] . " arquivada (demanda #" . $auth['demand_id'] . " - " . $auth['operator_email'] . ")");
        } catch (Throwable $e) {
            error_log("[AUTO_ARCHIVE] Erro ao arquivar autorização #" . $auth['id'] . ": " . $e->getMessage());
        }
    }
}

// =============================================
// 2. Auto-arquivar DEMANDAS em captação sem interação (opcional)
// =============================================
// Demandas em captação que não recebem nenhuma interação (reação, resposta)
// após X dias. Mantem o fluxo original.

$stmtDemands = $db->prepare("
    SELECT d.id, d.title, d.status, d.created_at
    FROM demands d
    WHERE d.status IN ('em_captacao', 'aguardando_captacao')
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
$stmtDemands->execute([
    'cutoff'  => $cutoff,
    'cutoff2' => $cutoff,
    'cutoff3' => $cutoff,
    'cutoff4' => $cutoff,
]);
$demands = $stmtDemands->fetchAll();

$archivedDemands = 0;

if (count($demands) > 0) {
    $updDemand = $db->prepare("
        UPDATE demands 
        SET status = 'cancelado',
            updated_at = NOW()
        WHERE id = :id
    ");
    
    $insLog = $db->prepare("
        INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) 
        VALUES (:did, :old, 'cancelado', NULL, :note)
    ");

    foreach ($demands as $d) {
        $reason = 'Auto-arquivado: sem retorno após ' . $days . ' dias';
        
        try {
            $updDemand->execute(['id' => (int)$d['id']]);
            $insLog->execute([
                'did' => (int)$d['id'],
                'old' => (string)$d['status'],
                'note' => $reason,
            ]);
            $archivedDemands++;
        } catch (Throwable $e) {
            error_log("[AUTO_ARCHIVE] Erro ao arquivar demanda #" . $d['id'] . ": " . $e->getMessage());
        }
    }
}

echo 'OK: auth_archived=' . $archivedAuths . ' demands_archived=' . $archivedDemands . "\n";
