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

    // Respeitar intervalo desde o último lote enviado.
    if (!empty($batch['last_batch_sent_at'])) {
        $lastTs = strtotime((string)$batch['last_batch_sent_at']);
        if ($lastTs !== false && (time() - $lastTs) < ($intervalMin * 60)) {
            echo "Batch #$batchId: aguardando intervalo de {$intervalMin}min.\n";
            continue;
        }
    }

    // Dados da demanda (para a IA).
    $demand = [];
    try {
        $d = $db->prepare('SELECT id, title, specialty, location_city FROM demands WHERE id = :d LIMIT 1');
        $d->execute(['d' => $demandId]);
        $demand = $d->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    // Próximos itens pendentes (lote).
    $items = [];
    try {
        $it = $db->prepare("SELECT * FROM demand_followup_items WHERE batch_id = :b AND status = 'pending' ORDER BY id ASC LIMIT :lim");
        $it->bindValue('b', $batchId, PDO::PARAM_INT);
        $it->bindValue('lim', $batchSize, PDO::PARAM_INT);
        $it->execute();
        $items = $it->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        continue;
    }

    if (empty($items)) {
        // Nada pendente: marcar batch como done.
        $db->prepare("UPDATE demand_followup_batches SET status = 'done' WHERE id = :id")->execute(['id' => $batchId]);
        continue;
    }

    $db->prepare("UPDATE demand_followup_batches SET status = 'processing' WHERE id = :id")->execute(['id' => $batchId]);

    // API do WhatsApp da instância de quem criou (fallback: instância padrão do usuário).
    $api = null;
    try {
        $api = whatsapp_get_api_for_user((int)($batch['created_by_user_id'] ?? 0) ?: null);
    } catch (Throwable $e) {
        $api = null;
    }

    foreach ($items as $item) {
        $itemId = (int)$item['id'];
        $jid = (string)$item['phone_jid'];
        $name = (string)($item['push_name'] ?? '');

        if ($api === null) {
            $db->prepare("UPDATE demand_followup_items SET status='failed', attempts=attempts+1, error_message='WhatsApp indisponível' WHERE id=:id")
                ->execute(['id' => $itemId]);
            $totalFailed++;
            continue;
        }

        $message = demand_followup_generate_message($demand, $name);

        try {
            $res = $api->sendText($jid, $message, ['delay' => $perMsgDelay]);
            $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if ($ok) {
                $db->prepare("UPDATE demand_followup_items SET status='sent', generated_message=:m, attempts=attempts+1, sent_at=NOW(), error_message=NULL WHERE id=:id")
                    ->execute(['m' => $message, 'id' => $itemId]);
                $db->prepare("UPDATE demand_followup_batches SET sent_items = sent_items + 1 WHERE id=:id")->execute(['id' => $batchId]);
                $totalSent++;
                // Salvar também em chat_messages para aparecer no Chat ao Vivo (privado).
                try {
                    $db->prepare('INSERT INTO chat_messages (remote_jid, instance_name, message_text, from_me, message_timestamp) VALUES (?, ?, ?, 1, ?)')
                        ->execute([$jid, $batch['instance_name'] ?? null, $message, time()]);
                } catch (Throwable $e) {}
            } else {
                $err = 'HTTP ' . (string)($res['status'] ?? '');
                $db->prepare("UPDATE demand_followup_items SET status='failed', generated_message=:m, attempts=attempts+1, error_message=:e WHERE id=:id")
                    ->execute(['m' => $message, 'e' => $err, 'id' => $itemId]);
                $db->prepare("UPDATE demand_followup_batches SET failed_items = failed_items + 1 WHERE id=:id")->execute(['id' => $batchId]);
                $totalFailed++;
            }
        } catch (Throwable $e) {
            $db->prepare("UPDATE demand_followup_items SET status='failed', attempts=attempts+1, error_message=:e WHERE id=:id")
                ->execute(['e' => mb_strimwidth($e->getMessage(), 0, 240, ''), 'id' => $itemId]);
            $db->prepare("UPDATE demand_followup_batches SET failed_items = failed_items + 1 WHERE id=:id")->execute(['id' => $batchId]);
            $totalFailed++;
        }

        // Pequeno respiro entre as 2 mensagens do mesmo lote.
        usleep(1500000); // 1.5s
    }

    // Marcar horário deste lote (base do intervalo) e concluir se acabou.
    $db->prepare("UPDATE demand_followup_batches SET last_batch_sent_at = NOW() WHERE id = :id")->execute(['id' => $batchId]);

    $remaining = (int)$db->query("SELECT COUNT(*) FROM demand_followup_items WHERE batch_id = $batchId AND status = 'pending'")->fetchColumn();
    if ($remaining === 0) {
        $db->prepare("UPDATE demand_followup_batches SET status = 'done' WHERE id = :id")->execute(['id' => $batchId]);
    }
}

echo "Cobrança processada: {$totalSent} enviada(s), {$totalFailed} falha(s).\n";
