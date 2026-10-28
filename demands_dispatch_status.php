<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: application/json; charset=utf-8');

/**
 * Endpoint de PROGRESSO do disparo de captação em background.
 * Consultado em polling pelo acompanhamento global (rodapé de todas as telas).
 *
 * Entradas (uma delas):
 *   ?run_id=<id>          um run
 *   ?run_ids=1,2,3        vários runs (máx. 10)
 *   ?active=1             runs em andamento disparados pelo usuário logado
 *                         (permite retomar o acompanhamento após recarregar/trocar de página)
 *
 * Saída (run_id): { ok, status, phase, phase_message, sent, errors, result_message, done, success }
 * Saída (run_ids/active): { ok, runs: [ ...mesmos campos... ] }
 */

function dds_format(array $run): array
{
    $status = (string)$run['status'];
    return [
        'run_id' => (int)$run['id'],
        'demand_id' => (int)$run['demand_id'],
        'demand_title' => (string)($run['demand_title'] ?? ''),
        'status' => $status,
        'phase' => (string)($run['phase'] ?? ''),
        'phase_message' => (string)($run['phase_message'] ?? ''),
        'sent' => (int)($run['sent_count'] ?? 0),
        'added' => (int)($run['added_count'] ?? 0),
        'errors' => (int)($run['error_count'] ?? 0),
        'result_message' => (string)($run['result_message'] ?? ''),
        'done' => in_array($status, ['completed', 'completed_with_errors', 'failed'], true),
        'success' => in_array($status, ['completed', 'completed_with_errors'], true),
    ];
}

try {
    // Garante que as colunas do disparo completo existem (fallback de migration).
    demand_dispatch_ensure_run_columns();

    $select = 'SELECT r.id, r.demand_id, r.sub_request_id, r.status, r.phase, r.phase_message,
                      r.added_count, r.sent_count, r.error_count, r.result_message, r.created_by_user_id,
                      d.title AS demand_title
               FROM demand_dispatch_runs r
               LEFT JOIN demands d ON d.id = r.demand_id';

    if (!empty($_GET['active'])) {
        // Só o disparo COMPLETO (group_jid vazio), recente, do próprio usuário.
        $st = db()->prepare($select . " WHERE r.created_by_user_id = :uid AND r.group_jid = ''
              AND r.status IN ('queued','processing')
              AND r.updated_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
              ORDER BY r.id DESC LIMIT 10");
        $st->execute(['uid' => auth_user_id()]);
        echo json_encode(['ok' => true, 'runs' => array_map('dds_format', $st->fetchAll(PDO::FETCH_ASSOC))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isset($_GET['run_ids'])) {
        $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string)$_GET['run_ids'])), static fn($x) => $x > 0)), 0, 10);
        if (!$ids) {
            echo json_encode(['ok' => true, 'runs' => []]);
            exit;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare($select . " WHERE r.id IN ($ph)");
        $st->execute($ids);
        echo json_encode(['ok' => true, 'runs' => array_map('dds_format', $st->fetchAll(PDO::FETCH_ASSOC))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $runId = (int)($_GET['run_id'] ?? 0);
    if ($runId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'run_id inválido.']);
        exit;
    }
    $st = db()->prepare($select . ' WHERE r.id = :id');
    $st->execute(['id' => $runId]);
    $run = $st->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        echo json_encode(['ok' => false, 'error' => 'Disparo não encontrado.']);
        exit;
    }
    echo json_encode(['ok' => true] + dds_format($run), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
