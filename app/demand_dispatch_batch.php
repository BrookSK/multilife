<?php
/**
 * Processamento em lotes/background da captação (item 17).
 *
 * Em vez de adicionar centenas/milhares de profissionais a um grupo de forma
 * síncrona (o que causa timeout), esta camada:
 *   1. Registra cada profissional-alvo em demand_dispatch_targets (idempotente).
 *   2. Cria um "run" em demand_dispatch_runs para acompanhar o progresso.
 *   3. Enfileira jobs em integration_jobs, um por lote de N profissionais.
 *
 * O worker (cron/integration_jobs_run.php) drena os jobs, adicionando os
 * profissionais em lotes pequenos, com idempotência e registro de falhas
 * individuais que não interrompem o processamento dos demais.
 */

declare(strict_types=1);

const DEMAND_DISPATCH_BATCH_SIZE = 50;

/**
 * Garante as tabelas de dispatch em lote (fallback caso a migration não tenha rodado).
 */
function demand_dispatch_ensure_tables(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS demand_dispatch_targets (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        demand_id BIGINT UNSIGNED NOT NULL,
        group_jid VARCHAR(100) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        status ENUM('pending','added','error','skipped') NOT NULL DEFAULT 'pending',
        error_message VARCHAR(255) NULL,
        processed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_dispatch_target (demand_id, group_jid, phone),
        KEY idx_ddt_status (status),
        KEY idx_ddt_demand (demand_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS demand_dispatch_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        demand_id BIGINT UNSIGNED NOT NULL,
        group_jid VARCHAR(100) NOT NULL,
        group_db_id BIGINT UNSIGNED NULL,
        total_targets INT UNSIGNED NOT NULL DEFAULT 0,
        processed_targets INT UNSIGNED NOT NULL DEFAULT 0,
        added_count INT UNSIGNED NOT NULL DEFAULT 0,
        error_count INT UNSIGNED NOT NULL DEFAULT 0,
        status ENUM('queued','processing','completed','completed_with_errors') NOT NULL DEFAULT 'queued',
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ddr_demand (demand_id),
        KEY idx_ddr_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Normaliza um telefone para o formato "55DDNUMERO" (só dígitos).
 * Retorna null se inválido.
 */
function demand_dispatch_normalize_phone(string $phone): ?string
{
    $clean = preg_replace('/\D+/', '', $phone);
    if (strlen($clean) < 10) {
        return null;
    }
    if (strlen($clean) === 10 || strlen($clean) === 11) {
        $clean = '55' . $clean;
    }
    if (strlen($clean) < 12) {
        return null;
    }
    return $clean;
}

/**
 * Enfileira a adição de profissionais a um grupo em lotes de background.
 *
 * @param int $demandId
 * @param string $groupJid  JID do grupo (com @g.us)
 * @param int|null $groupDbId  id em whatsapp_groups
 * @param string[] $phones  telefones normalizados (55...)
 * @param string $instanceName  instância Evolution já resolvida (para não re-probing)
 * @param int|null $userId
 * @return array{run_id:int, total:int, jobs:int}
 */
function demand_dispatch_enqueue_members(
    int $demandId,
    string $groupJid,
    ?int $groupDbId,
    array $phones,
    string $instanceName,
    ?int $userId = null
): array {
    demand_dispatch_ensure_tables();

    // Deduplicar e normalizar
    $normalized = [];
    foreach ($phones as $p) {
        $n = demand_dispatch_normalize_phone((string)$p);
        if ($n !== null) {
            $normalized[$n] = true;
        }
    }
    $normalized = array_keys($normalized);

    // Registrar targets de forma idempotente (INSERT IGNORE pelo UNIQUE)
    $insTarget = db()->prepare(
        "INSERT IGNORE INTO demand_dispatch_targets (demand_id, group_jid, phone, status)
         VALUES (:did, :jid, :phone, 'pending')"
    );
    foreach ($normalized as $phone) {
        $insTarget->execute(['did' => $demandId, 'jid' => $groupJid, 'phone' => $phone]);
    }

    // Buscar apenas os que AINDA estão pendentes (idempotência: não reprocessa 'added')
    $pendingStmt = db()->prepare(
        "SELECT phone FROM demand_dispatch_targets
         WHERE demand_id = :did AND group_jid = :jid AND status = 'pending'"
    );
    $pendingStmt->execute(['did' => $demandId, 'jid' => $groupJid]);
    $pending = $pendingStmt->fetchAll(PDO::FETCH_COLUMN);

    // Criar o run de acompanhamento
    $insRun = db()->prepare(
        "INSERT INTO demand_dispatch_runs (demand_id, group_jid, group_db_id, total_targets, status, created_by_user_id)
         VALUES (:did, :jid, :gid, :total, 'queued', :uid)"
    );
    $insRun->execute([
        'did' => $demandId,
        'jid' => $groupJid,
        'gid' => $groupDbId,
        'total' => count($pending),
        'uid' => $userId,
    ]);
    $runId = (int)db()->lastInsertId();

    // Enfileirar jobs em lotes
    $jobsCreated = 0;
    $chunks = array_chunk($pending, DEMAND_DISPATCH_BATCH_SIZE);
    foreach ($chunks as $chunk) {
        integration_job_enqueue('evolution', 'demand_dispatch_add_members', [
            'run_id' => $runId,
            'demand_id' => $demandId,
            'group_jid' => $groupJid,
            'instance_name' => $instanceName,
            'phones' => array_values($chunk),
        ]);
        $jobsCreated++;
    }

    return ['run_id' => $runId, 'total' => count($pending), 'jobs' => $jobsCreated];
}
