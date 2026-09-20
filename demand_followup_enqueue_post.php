<?php

declare(strict_types=1);

/**
 * Enfileira uma COBRANÇA MANUAL de captação para os profissionais selecionados.
 *
 * O envio real NÃO acontece aqui: os itens entram na fila (demand_followup_items)
 * e o cron cron/demand_followup_dispatch.php processa em lotes espaçados.
 *
 * Exige confirmação explícita de risco (acknowledge_risk=1), pois o envio no
 * privado pode levar a bloqueio do número — o operador assume esse risco.
 *
 * POST:
 *   - demand_id (obrigatório) — demanda JÁ captada no grupo
 *   - phones[] (obrigatório) — telefones selecionados
 *   - acknowledge_risk=1 (obrigatório)
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método inválido.']);
    exit;
}

$demandId = (int)($_POST['demand_id'] ?? 0);
$phones = $_POST['phones'] ?? [];
$ack = (string)($_POST['acknowledge_risk'] ?? '') === '1';

if ($demandId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Demanda inválida.']);
    exit;
}
if (!$ack) {
    echo json_encode(['success' => false, 'error' => 'É necessário confirmar o aviso de risco de bloqueio antes de enviar.']);
    exit;
}
if (!is_array($phones) || count($phones) === 0) {
    echo json_encode(['success' => false, 'error' => 'Selecione ao menos um profissional.']);
    exit;
}

$db = db();

// Garantir estruturas (idempotente).
try {
    $db->exec("CREATE TABLE IF NOT EXISTS demand_followup_batches (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        demand_id BIGINT UNSIGNED NOT NULL,
        dispatch_log_id BIGINT UNSIGNED NULL,
        instance_name VARCHAR(100) NULL,
        created_by_user_id INT UNSIGNED NULL,
        status ENUM('queued','processing','done','cancelled') NOT NULL DEFAULT 'queued',
        total_items INT UNSIGNED NOT NULL DEFAULT 0,
        sent_items INT UNSIGNED NOT NULL DEFAULT 0,
        failed_items INT UNSIGNED NOT NULL DEFAULT 0,
        last_batch_sent_at DATETIME NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_dfb_demand (demand_id), KEY idx_dfb_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS demand_followup_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_id BIGINT UNSIGNED NOT NULL,
        demand_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL,
        phone VARCHAR(30) NOT NULL,
        phone_jid VARCHAR(100) NOT NULL,
        push_name VARCHAR(255) NULL,
        status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
        generated_message TEXT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        error_message VARCHAR(255) NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY idx_dfi_batch (batch_id), KEY idx_dfi_status (status),
        UNIQUE KEY uk_dfi_demand_phone (demand_id, phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// A demanda precisa ter sido captada no grupo.
if (!demand_followup_demand_was_dispatched($db, $demandId)) {
    echo json_encode(['success' => false, 'error' => 'Esta demanda ainda não foi captada em grupo. A cobrança só vale para demandas disparadas.']);
    exit;
}

// Resolver os profissionais selecionados a partir dos candidatos válidos
// (evita enviar para número arbitrário vindo do cliente).
$candidates = demand_followup_candidates($db, $demandId);
$byPhone = [];
foreach ($candidates as $c) {
    $byPhone[$c['phone']] = $c;
    // também indexar pelo sufixo, para tolerar máscara
    $byPhone[substr($c['phone'], -8)] = $c;
}

$selected = [];
foreach ($phones as $ph) {
    $digits = preg_replace('/\D+/', '', (string)$ph);
    $cand = $byPhone[$digits] ?? ($byPhone[substr($digits, -8)] ?? null);
    if ($cand === null) {
        continue;
    }
    if ($cand['already_charged']) {
        continue; // anti-repetição
    }
    $selected[$cand['phone']] = $cand;
}

if (count($selected) === 0) {
    echo json_encode(['success' => false, 'error' => 'Nenhum profissional válido para cobrar (podem já ter sido cobrados).']);
    exit;
}

// Instância do usuário (para o envio sair do WhatsApp de quem cobra).
$instanceName = null;
try {
    $inst = whatsapp_get_user_instance(auth_user_id());
    $instanceName = $inst['instance_name'] ?? null;
} catch (Throwable $e) {}

try {
    $db->beginTransaction();

    $insBatch = $db->prepare(
        'INSERT INTO demand_followup_batches (demand_id, instance_name, created_by_user_id, status, total_items)
         VALUES (:d, :inst, :uid, \'queued\', :total)'
    );
    $insBatch->execute([
        'd' => $demandId,
        'inst' => $instanceName,
        'uid' => auth_user_id(),
        'total' => count($selected),
    ]);
    $batchId = (int)$db->lastInsertId();

    // INSERT IGNORE respeita o UNIQUE(demand_id, phone) — anti-repetição no banco.
    $insItem = $db->prepare(
        'INSERT IGNORE INTO demand_followup_items (batch_id, demand_id, user_id, phone, phone_jid, push_name, status)
         VALUES (:b, :d, :uid, :phone, :jid, :name, \'pending\')'
    );
    $queued = 0;
    foreach ($selected as $cand) {
        $insItem->execute([
            'b' => $batchId,
            'd' => $demandId,
            'uid' => $cand['user_id'],
            'phone' => $cand['phone'],
            'jid' => $cand['phone_jid'],
            'name' => $cand['name'] !== '' ? $cand['name'] : null,
        ]);
        if ($insItem->rowCount() > 0) {
            $queued++;
        }
    }

    // Ajustar total real enfileirado.
    $db->prepare('UPDATE demand_followup_batches SET total_items = :t WHERE id = :id')
        ->execute(['t' => $queued, 'id' => $batchId]);

    audit_log('create', 'demand_followup_batches', (string)$batchId, null, [
        'demand_id' => $demandId,
        'queued' => $queued,
    ]);

    $db->commit();

    $interval = demand_followup_interval_minutes();
    $size = demand_followup_batch_size();

    // PRIMEIRO lote é enviado IMEDIATAMENTE (os próximos ficam para o cron, espaçados).
    $immediate = ['sent' => 0, 'failed' => 0, 'processed' => 0];
    try {
        $immediate = demand_followup_process_one_batch($db, $batchId);
    } catch (Throwable $e) {
        error_log('[DEMAND_FOLLOWUP_ENQUEUE] Falha no envio imediato: ' . $e->getMessage());
    }

    $remaining = max(0, $queued - $immediate['processed']);
    $msg = 'Cobrança iniciada: ' . $immediate['sent'] . ' enviada(s) agora';
    if ($immediate['failed'] > 0) {
        $msg .= ', ' . $immediate['failed'] . ' falha(s)';
    }
    if ($remaining > 0) {
        $msg .= '. Os outros ' . $remaining . ' serão enviados em lotes de ' . $size . ' a cada ~' . $interval . ' min (requer cron ativo).';
    } else {
        $msg .= '.';
    }

    echo json_encode([
        'success' => true,
        'batch_id' => $batchId,
        'queued' => $queued,
        'sent_now' => $immediate['sent'],
        'failed_now' => $immediate['failed'],
        'message' => $msg,
    ]);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[DEMAND_FOLLOWUP_ENQUEUE] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao enfileirar cobrança: ' . $e->getMessage()]);
    exit;
}
