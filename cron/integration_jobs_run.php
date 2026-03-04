<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($limit <= 0 || $limit > 200) {
    $limit = 20;
}

$now = (new DateTime('now'))->format('Y-m-d H:i:s');

$db = db();

$stmt = $db->prepare(
    "SELECT *
     FROM integration_jobs
     WHERE status IN ('pending','error')
       AND (next_run_at IS NULL OR next_run_at <= :now)
       AND attempts < max_attempts
     ORDER BY COALESCE(next_run_at, created_at) ASC, id ASC
     LIMIT $limit"
);
$stmt->execute(['now' => $now]);
$jobs = $stmt->fetchAll();

if (count($jobs) === 0) {
    echo "OK: no jobs\n";
    exit;
}

$runAt = (new DateTime('now'))->format('Y-m-d H:i:s');

$updRun = $db->prepare(
    "UPDATE integration_jobs
     SET status = :status,
         attempts = :attempts,
         last_error = :last_error,
         next_run_at = :next_run_at,
         last_run_at = :last_run_at
     WHERE id = :id"
);

$markDead = $db->prepare(
    "UPDATE integration_jobs
     SET status = 'dead',
         attempts = :attempts,
         last_error = :last_error,
         last_run_at = :last_run_at,
         next_run_at = NULL
     WHERE id = :id"
);

$success = 0;
$failed = 0;
$dead = 0;

foreach ($jobs as $j) {
    $id = (int)$j['id'];
    $provider = (string)$j['provider'];
    $action = (string)$j['action'];

    $attempts = (int)$j['attempts'];
    $maxAttempts = (int)$j['max_attempts'];

    $payloadRaw = $j['payload'];
    $payload = null;
    if (is_string($payloadRaw) && trim($payloadRaw) !== '') {
        $decoded = json_decode($payloadRaw, true);
        $payload = $decoded !== null ? $decoded : $payloadRaw;
    }

    try {
        // Marca como running
        $updRun->execute([
            'status' => 'running',
            'attempts' => $attempts,
            'last_error' => null,
            'next_run_at' => null,
            'last_run_at' => $runAt,
            'id' => $id,
        ]);

        // Roteamento de actions (stub inicial)
        // Aqui vamos plugar ações reais por provider/action conforme cada módulo.
        if ($provider === 'openai' && $action === 'extract_email_to_demand') {
            // Implementado no próximo endpoint /cron/demands_ai_extract.php
            throw new RuntimeException('Action not implemented yet: openai extract_email_to_demand');
        }

        if ($provider === 'evolution' && $action === 'send_message') {
            throw new RuntimeException('Action not implemented yet: evolution send_message');
        }

        throw new RuntimeException('Unknown job action: ' . $provider . ' / ' . $action);
    } catch (Throwable $e) {
        $attempts++;
        $err = mb_strimwidth($e->getMessage(), 0, 255, '');

        if ($attempts >= $maxAttempts) {
            $markDead->execute([
                'attempts' => $attempts,
                'last_error' => $err,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $dead++;
            integration_log($provider, 'job ' . $action, 'error', null, $payload, null, 'DEAD: ' . $err, $attempts);
            continue;
        }

        // Backoff exponencial simples: 5m, 15m, 60m
        $minutes = 5;
        if ($attempts === 2) {
            $minutes = 15;
        } elseif ($attempts >= 3) {
            $minutes = 60;
        }

        $next = (new DateTime('now'));
        $next->modify('+' . $minutes . ' minutes');
        $nextRunAt = $next->format('Y-m-d H:i:s');

        $updRun->execute([
            'status' => 'error',
            'attempts' => $attempts,
            'last_error' => $err,
            'next_run_at' => $nextRunAt,
            'last_run_at' => $runAt,
            'id' => $id,
        ]);

        $failed++;
        integration_log($provider, 'job ' . $action, 'error', null, $payload, null, $err, $attempts);
        continue;
    }
}

echo 'OK: success=' . $success . ' failed=' . $failed . ' dead=' . $dead . "\n";
