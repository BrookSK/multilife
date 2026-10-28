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
 * Chave canônica de um número/JID para comparar membros de grupo.
 * Remove sufixo de device/servidor e normaliza o 9º dígito de celular BR
 * (o WhatsApp costuma usar 55+DDD+8 dígitos no JID).
 */
function dispatch_member_key(string $jidOrPhone): string
{
    $d = preg_replace('/\D+/', '', (string)preg_replace('/[:@].*$/', '', $jidOrPhone));
    if (strlen($d) === 13 && strncmp($d, '55', 2) === 0 && $d[4] === '9') {
        $d = substr($d, 0, 4) . substr($d, 5);
    }
    return $d;
}

/**
 * Membros atuais do grupo: [chave => é_admin]. null se não foi possível ler.
 *
 * Usado para NUNCA re-adicionar quem já está no grupo: chamadas de "add" com
 * membros existentes (ou o próprio número da instância) derrubam a sessão do
 * WhatsApp na Evolution (instância vai para 'close').
 */
function dispatch_group_member_keys(EvolutionApiV1 $api, string $groupJid): ?array
{
    $r = $api->findGroupMembers($groupJid);
    $code = (int)($r['status'] ?? 0);
    if ($code < 200 || $code >= 300 || !is_array($r['json'] ?? null)) {
        return null;
    }
    $j = $r['json'];
    $list = $j['participants'] ?? (isset($j[0]) ? $j : null);
    if (!is_array($list)) {
        return null;
    }
    $keys = [];
    foreach ($list as $p) {
        if (!is_array($p)) continue;
        $isAdmin = !empty($p['admin']);
        foreach (['id', 'jid', 'phoneNumber'] as $f) {
            if (!empty($p[$f]) && is_string($p[$f])) {
                $k = dispatch_member_key($p[$f]);
                if ($k !== '') {
                    $keys[$k] = $isAdmin || !empty($keys[$k]);
                }
            }
        }
    }
    return $keys;
}

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

/* =====================================================================
 * DISPARO COMPLETO EM BACKGROUND (criação/busca de grupo + envio)
 * ---------------------------------------------------------------------
 * Diferente de demand_dispatch_enqueue_members (que só adiciona membros em
 * lote), as funções abaixo representam o DISPARO INTEIRO da captação como um
 * "run" único, acompanhável por um popup de progresso. O trabalho pesado
 * (resolver instância, criar/achar grupo, sincronizar membros, enviar a
 * mensagem) roda no worker cron (ação 'demand_dispatch_run'), executando o
 * corpo de demands_dispatch_whatsapp_post.php em modo worker.
 * ===================================================================== */

/**
 * Garante as colunas extras de demand_dispatch_runs usadas pelo disparo
 * completo (fallback, caso a migration 2026-10-27_0001 não tenha rodado).
 */
function demand_dispatch_ensure_run_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // Atalho: o endpoint de progresso chama isto a cada poucos segundos; se a
    // última coluna e o status 'failed' já existem, não roda DDL.
    try {
        $col = db()->query("SHOW COLUMNS FROM demand_dispatch_runs LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $hasResult = (bool)db()->query("SHOW COLUMNS FROM demand_dispatch_runs LIKE 'result_message'")->fetch();
        if ($col && $hasResult && strpos((string)$col['Type'], 'failed') !== false) {
            return;
        }
    } catch (Throwable $e) { /* tabela ausente: cria abaixo */ }
    demand_dispatch_ensure_tables();
    // Cada ADD é tentado isoladamente: versões sem "ADD COLUMN IF NOT EXISTS"
    // simplesmente falham no try/catch sem abortar as demais.
    $cols = [
        "ALTER TABLE demand_dispatch_runs ADD COLUMN sub_request_id BIGINT UNSIGNED NULL AFTER demand_id",
        "ALTER TABLE demand_dispatch_runs ADD COLUMN phase VARCHAR(40) NOT NULL DEFAULT 'queued' AFTER status",
        "ALTER TABLE demand_dispatch_runs ADD COLUMN phase_message VARCHAR(255) NULL AFTER phase",
        "ALTER TABLE demand_dispatch_runs ADD COLUMN sent_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER added_count",
        "ALTER TABLE demand_dispatch_runs ADD COLUMN result_message VARCHAR(500) NULL AFTER error_count",
    ];
    foreach ($cols as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* coluna já existe */ }
    }
    // Amplia o ENUM de status para incluir 'failed' (ignora se já estiver ok).
    try {
        db()->exec("ALTER TABLE demand_dispatch_runs MODIFY COLUMN status ENUM('queued','processing','completed','completed_with_errors','failed') NOT NULL DEFAULT 'queued'");
    } catch (Throwable $e) { /* já ampliado */ }
}

/**
 * Cria um "run" de disparo completo e enfileira o job que o executará no worker.
 *
 * @return int run_id criado
 */
function demand_dispatch_enqueue_full(int $demandId, int $subRequestId, ?int $userId): int
{
    demand_dispatch_ensure_run_columns();

    $ins = db()->prepare(
        "INSERT INTO demand_dispatch_runs
            (demand_id, sub_request_id, group_jid, total_targets, status, phase, phase_message, created_by_user_id)
         VALUES
            (:did, :srid, '', 0, 'queued', 'queued', 'Na fila para iniciar...', :uid)"
    );
    $ins->execute([
        'did' => $demandId,
        'srid' => $subRequestId > 0 ? $subRequestId : null,
        'uid' => $userId,
    ]);
    $runId = (int)db()->lastInsertId();

    $jobId = integration_job_enqueue('evolution', 'demand_dispatch_run', [
        'run_id' => $runId,
        'demand_id' => $demandId,
        'sub_request_id' => $subRequestId,
        'user_id' => $userId,
    ]);

    // Não espera o próximo ciclo do cron: aciona o worker AGORA só para este job.
    demand_dispatch_kick_worker($jobId);

    return $runId;
}

/**
 * Dispara o worker (cron/integration_jobs_run.php) em background para UM job,
 * sem esperar a resposta. Antes a captação ficava parada na fila até o próximo
 * ciclo do cron (e atrás de outros jobs). Se falhar, o cron normal processa.
 */
function demand_dispatch_kick_worker(int $jobId): void
{
    if ($jobId <= 0 || !function_exists('curl_init')) {
        return;
    }
    // Interruptor: grave 'demands.dispatch_kick_worker' = '0' para voltar a depender
    // só do cron (comportamento anterior), caso o servidor corte requisições em segundo plano.
    if ((string)admin_setting_get('demands.dispatch_kick_worker', '1') === '0') {
        return;
    }
    $cronToken = trim((string)admin_setting_get('cron.token', ''));
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($cronToken === '' || $host === '') {
        return;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url = $scheme . '://' . $host . '/cron/integration_jobs_run.php?' . http_build_query([
        'token' => $cronToken,
        'job_id' => $jobId,
    ]);
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            // Só precisa entregar a requisição; o worker usa ignore_user_abort.
            CURLOPT_TIMEOUT_MS => 800,
            CURLOPT_NOSIGNAL => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[DISPATCH] Falha ao acionar worker imediato: ' . $e->getMessage());
    }
}

/**
 * Atualiza a fase/mensagem de progresso de um run (mostrado no popup).
 * Falhas são silenciosas para nunca interromper o disparo por causa do log.
 */
function demand_dispatch_run_set_phase(int $runId, string $phase, string $message = ''): void
{
    if ($runId <= 0) {
        return;
    }
    try {
        db()->prepare(
            "UPDATE demand_dispatch_runs
             SET phase = :ph, phase_message = :msg, status = IF(status = 'queued', 'processing', status)
             WHERE id = :id"
        )->execute(['ph' => $phase, 'msg' => mb_strimwidth($message, 0, 250, ''), 'id' => $runId]);
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Marca o run como concluído (ou falho) e grava a mensagem final.
 *
 * @param string $status 'completed' | 'completed_with_errors' | 'failed'
 */
function demand_dispatch_run_finish(int $runId, string $status, string $message, int $sent = 0, int $errors = 0): void
{
    if ($runId <= 0) {
        return;
    }
    try {
        db()->prepare(
            "UPDATE demand_dispatch_runs
             SET status = :st,
                 phase = 'done',
                 phase_message = :msg,
                 result_message = :msg2,
                 sent_count = GREATEST(sent_count, :sent),
                 error_count = :err
             WHERE id = :id
               -- não rebaixa para 'failed' um run que o webhook já confirmou como enviado
               AND NOT (status = 'completed' AND :st2 = 'failed')"
        )->execute([
            'st' => $status,
            'st2' => $status,
            // phase_message é VARCHAR(255); result_message, VARCHAR(500).
            'msg' => mb_strimwidth($message, 0, 250, '…'),
            'msg2' => mb_strimwidth($message, 0, 490, '…'),
            'sent' => $sent,
            'err' => $errors,
            'id' => $runId,
        ]);
    } catch (Throwable $e) {
        // Antes era engolido em silêncio: o run nunca era finalizado e o acompanhamento
        // ficava "Enviando a mensagem..." para sempre, mesmo com a mensagem enviada.
        error_log('[DISPATCH] Falha ao finalizar run #' . $runId . ': ' . $e->getMessage());
    }
}

/**
 * authorization_requests.frequency nasceu como ENUM('single','daily','weekly',
 * 'biweekly','monthly','custom'), mas o chat e a pré-admissão gravam os códigos do
 * sistema ('3x_semana', 'quinzenal'...). Converte para VARCHAR (idempotente).
 * Chamar FORA de transação (DDL faz commit implícito no MySQL).
 */
function authorization_requests_ensure_frequency_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $col = db()->query("SHOW COLUMNS FROM authorization_requests LIKE 'frequency'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)$col['Type'], 'enum') === 0) {
            db()->exec("ALTER TABLE authorization_requests MODIFY COLUMN frequency VARCHAR(30) NOT NULL DEFAULT 'weekly'");
        }
    } catch (Throwable $e) {
        error_log('[AUTH_FREQUENCY] ' . $e->getMessage());
    }
}

/**
 * CONFIRMAÇÃO DO ENVIO PELO WEBHOOK.
 *
 * Em grupo, a Evolution às vezes entrega a mensagem mas demora (ou nunca
 * responde) à chamada de envio — o disparo ficava parado em "Enviando a
 * mensagem..." mesmo com a mensagem já no grupo. Quando o WhatsApp avisa pelo
 * webhook que a NOSSA mensagem (fromMe) com o token #CAP... chegou no grupo,
 * marcamos o envio como feito e encerramos o acompanhamento.
 */
function demand_dispatch_confirm_from_webhook(string $event, array $data): void
{
    if (!in_array($event, ['messages.upsert', 'send.message'], true)) {
        return;
    }
    $msg = $data['data'] ?? null;
    if (is_array($msg) && isset($msg[0]) && is_array($msg[0])) {
        $msg = $msg[0]; // alguns formatos mandam uma lista
    }
    if (!is_array($msg)) {
        return;
    }
    $key = is_array($msg['key'] ?? null) ? $msg['key'] : [];
    $remoteJid = (string)($key['remoteJid'] ?? '');
    if (empty($key['fromMe']) || substr($remoteJid, -5) !== '@g.us') {
        return;
    }
    $m = is_array($msg['message'] ?? null) ? $msg['message'] : [];
    $text = (string)($m['conversation'] ?? ($m['extendedTextMessage']['text'] ?? ''));
    if ($text === '' || !preg_match('/#CAP(\d+)-[A-F0-9]+/', $text, $tm)) {
        return;
    }
    $token = $tm[0];
    $demandId = (int)$tm[1];
    $externalId = (string)($key['id'] ?? '');
    try {
        $upd = db()->prepare("UPDATE demand_dispatch_logs
            SET dispatch_status = 'sent', error_message = NULL,
                external_message_id = COALESCE(NULLIF(external_message_id, ''), :eid)
            WHERE capture_token = :tk AND dispatch_status IN ('queued','error')");
        $upd->execute(['eid' => $externalId !== '' ? $externalId : null, 'tk' => $token]);
        if ($upd->rowCount() === 0) {
            return; // já estava confirmado
        }
        // Encerra o acompanhamento que ainda estiver esperando a resposta do envio.
        db()->prepare("UPDATE demand_dispatch_runs
            SET status = 'completed', phase = 'done',
                phase_message = 'Captação enviada (confirmada pelo WhatsApp).',
                result_message = 'Captação enviada (confirmada pelo WhatsApp).',
                sent_count = GREATEST(sent_count, 1)
            WHERE demand_id = :d AND group_jid = '' AND status IN ('queued','processing')")
            ->execute(['d' => $demandId]);
    } catch (Throwable $e) {
        error_log('[DISPATCH_CONFIRM] ' . $e->getMessage());
    }
}
