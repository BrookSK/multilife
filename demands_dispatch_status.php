<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: application/json; charset=utf-8');

/**
 * Endpoint de PROGRESSO do disparo de captação em background.
 * Consultado em polling pelo popup da tela demands_view.php.
 *
 * Entrada:  ?run_id=<id>  (id em demand_dispatch_runs)
 * Saída:    { ok, status, phase, phase_message, sent, errors, result_message, done }
 */
try {
    $runId = (int)($_GET['run_id'] ?? 0);
    if ($runId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'run_id inválido.']);
        exit;
    }

    // Garante que as colunas do disparo completo existem (fallback de migration).
    demand_dispatch_ensure_run_columns();

    $stmt = db()->prepare(
        'SELECT id, demand_id, sub_request_id, status, phase, phase_message,
                total_targets, processed_targets, added_count, sent_count,
                error_count, result_message, created_by_user_id
         FROM demand_dispatch_runs
         WHERE id = :id'
    );
    $stmt->execute(['id' => $runId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$run) {
        echo json_encode(['ok' => false, 'error' => 'Disparo não encontrado.']);
        exit;
    }

    $status = (string)$run['status'];
    $done = in_array($status, ['completed', 'completed_with_errors', 'failed'], true);

    echo json_encode([
        'ok' => true,
        'run_id' => (int)$run['id'],
        'demand_id' => (int)$run['demand_id'],
        'status' => $status,
        'phase' => (string)($run['phase'] ?? ''),
        'phase_message' => (string)($run['phase_message'] ?? ''),
        'sent' => (int)($run['sent_count'] ?? 0),
        'errors' => (int)($run['error_count'] ?? 0),
        'result_message' => (string)($run['result_message'] ?? ''),
        'done' => $done,
        'success' => in_array($status, ['completed', 'completed_with_errors'], true),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
