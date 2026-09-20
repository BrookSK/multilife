<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * CRON: processa a fila de COBRANÇA MANUAL de captação (follow-up).
 *
 * A cada execução:
 *  - Para cada batch ativo, respeita o INTERVALO mínimo desde o último lote enviado
 *    (demand_followup.interval_minutes). Se ainda não passou, pula o batch.
 *  - Envia no máximo BATCH_SIZE itens (demand_followup.batch_size, padrão 2) por
 *    execução/batch, gerando uma mensagem por IA para cada e enviando no privado.
 *  - Marca cada item como sent/failed e atualiza last_batch_sent_at.
 *
 * Assim, com o cron rodando de minuto em minuto, o envio fica naturalmente
 * espaçado (2 msgs por vez, com pausa de N minutos entre lotes) — anti-bloqueio.
 *
 * Agendar (ex.): a cada 1 minuto:
 *   php cron/demand_followup_dispatch.php token=SEU_CRON_TOKEN
 */

$db = db();
$batchSize = demand_followup_batch_size();
$intervalMin = demand_followup_interval_minutes();
$perMsgDelay = demand_followup_per_message_delay_ms();

// Batches com itens pendentes.
$batches = [];
try {
    $stmt = $db->query("
        SELECT b.*
        FROM demand_followup_batches b
        WHERE b.status IN ('queued','processing')
          AND EXISTS (SELECT 1 FROM demand_followup_items i WHERE i.batch_id = b.id AND i.status = 'pending')
        ORDER BY b.id ASC
    ");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "Fila indisponível: " . $e->getMessage() . "\n";
    exit;
}

if (empty($batches)) {
    echo "Nenhum lote de cobrança pendente.\n";
    exit;
}

$totalSent = 0;
$totalFailed = 0;

foreach ($batches as $batch) {
    $batchId = (int)$batch['id'];
    $demandId = (int)$batch['demand_id'];

    // Respeitar intervalo desde o último lote enviado (os próximos lotes esperam N min).
    if (!empty($batch['last_batch_sent_at'])) {
        $lastTs = strtotime((string)$batch['last_batch_sent_at']);
        if ($lastTs !== false && (time() - $lastTs) < ($intervalMin * 60)) {
            echo "Batch #$batchId: aguardando intervalo de {$intervalMin}min.\n";
            continue;
        }
    }

    // Processa um lote (mesma lógica usada no envio imediato do endpoint).
    $r = demand_followup_process_one_batch($db, $batchId);
    $totalSent += $r['sent'];
    $totalFailed += $r['failed'];
}

echo "Cobrança processada: {$totalSent} enviada(s), {$totalFailed} falha(s).\n";
