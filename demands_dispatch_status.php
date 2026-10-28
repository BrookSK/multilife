<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');
session_release(); // polling a cada 3 s do acompanhamento da captação

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

/**
 * Captação PARADA: sem nenhuma atualização de fase há muito tempo, o processo
 * morreu (servidor interrompeu a execução) ou a Evolution não respondeu. Antes o
 * acompanhamento ficava "em andamento" para sempre; agora encerra com o motivo.
 */
function dds_check_stale(array $run): array
{
    $status = (string)$run['status'];
    if (!in_array($status, ['queued', 'processing'], true) || empty($run['updated_at'])) {
        return $run;
    }
    $idle = time() - (int)strtotime((string)$run['updated_at']);
    $phase = (string)($run['phase'] ?? '');
    $msg = null;
    if ($status === 'queued' && $idle > 180) {
        $msg = 'A captação ficou 3 minutos na fila sem começar: o processamento em segundo plano não está rodando. '
            . 'Verifique o cron "integration_jobs_run" e o token do cron em Configurações e tente novamente.';
    } elseif ($status === 'processing' && $idle > 300) {
        $where = $phase === 'creating_group' ? 'ao criar o grupo' : ($phase === 'adding_members' ? 'ao adicionar os profissionais' : ($phase === 'sending' ? 'ao enviar a mensagem' : 'no processamento'));
        $msg = 'A captação parou ' . $where . ' (5 minutos sem resposta). Normalmente é o WhatsApp/Evolution travado ou em loop: '
            . 'veja o aviso de WhatsApp no topo da tela e, se preciso, desconecte os aparelhos no celular, faça logout da instância e leia um QR novo. Depois clique em "Realizar Captação" de novo.';
    }
    if ($msg !== null) {
        try {
            db()->prepare("UPDATE demand_dispatch_runs SET status = 'failed', phase = 'done', phase_message = :m, result_message = :m2
                           WHERE id = :id AND status IN ('queued','processing')")
                ->execute(['m' => mb_strimwidth($msg, 0, 250, '…'), 'm2' => mb_strimwidth($msg, 0, 490, '…'), 'id' => (int)$run['id']]);
        } catch (Throwable $e) {}
        $run['status'] = 'failed';
        $run['phase'] = 'done';
        $run['result_message'] = $msg;
    }
    return $run;
}

function dds_format(array $run): array
{
    $run = dds_check_stale($run);
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
                      r.added_count, r.sent_count, r.error_count, r.result_message, r.created_by_user_id, r.updated_at,
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
