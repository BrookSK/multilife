<?php
/**
 * Fechamento por ESCOPO (operadora ou cliente) — camada de CONFERÊNCIA.
 *
 * Esta etapa NÃO gera lançamentos financeiros. Ela apenas registra que a
 * conferência das quantidades foi concluída:
 *   - scope=operator : marca uma operadora como fechada na competência.
 *   - scope=client   : consolida o cliente (só permitido quando TODAS as
 *                      operadoras do cliente com sessões no mês já fecharam).
 *
 * A geração de Contas a Pagar/Receber acontece na tela do Financeiro
 * (financeiro_fechamento.php), após o "OK" final.
 *
 * POST:
 *   - month  : competência YYYY-MM
 *   - scope  : 'operator' | 'client'
 *   - id     : id da operadora (health_insurer_id) ou do cliente (client_id)
 *   - action : 'close' | 'reopen'
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.closure.manage');

$month = billing_closure_normalize_month($_POST['month'] ?? null);
$scope = trim((string)($_POST['scope'] ?? ''));
$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? 'close'));
$redirect = '/faturamento_fechamento.php?month=' . urlencode((string)$month);

if ($month === null || !in_array($scope, ['operator', 'client'], true) || $id <= 0) {
    flash_set('error', 'Parâmetros inválidos.');
    header('Location: ' . $redirect);
    exit;
}

$db = db();
$userId = (int)auth_user_id();
clients_ensure_schema();

try {
    if ($scope === 'operator') {
        if ($action === 'reopen') {
            $db->prepare("DELETE FROM billing_monthly_closures
                WHERE reference_month = :m AND scope = 'operator' AND health_insurer_id = :hi")
                ->execute(['m' => $month, 'hi' => $id]);
            flash_set('success', 'Operadora reaberta para conferência.');
            header('Location: ' . $redirect);
            exit;
        }

        // Contar sessões faturáveis da operadora no mês (não faturadas ainda).
        $sessions = billing_closure_fetch_sessions($db, $month, false, ['insurer_id' => $id]);
        $totalSessions = count($sessions);
        $clientId = null;
        foreach ($sessions as $s) {
            if ($s['client_id'] !== null) { $clientId = (int)$s['client_id']; break; }
        }

        // Upsert do fechamento de operadora.
        $db->prepare("
            INSERT INTO billing_monthly_closures
                (reference_month, scope, client_id, health_insurer_id, status, total_sessions, closed_by_user_id, closed_at)
            VALUES (:m, 'operator', :cid, :hi, 'operator_closed', :ts, :uid, NOW())
            ON DUPLICATE KEY UPDATE
                status = 'operator_closed', total_sessions = VALUES(total_sessions),
                client_id = VALUES(client_id), closed_by_user_id = VALUES(closed_by_user_id),
                closed_at = NOW(), reversed_at = NULL, reversed_by_user_id = NULL
        ")->execute(['m' => $month, 'cid' => $clientId, 'hi' => $id, 'ts' => $totalSessions, 'uid' => $userId]);

        audit_log('close', 'billing_monthly_closures', 'op:' . $id, null,
            ['month' => $month, 'scope' => 'operator', 'sessions' => $totalSessions]);
        flash_set('success', 'Operadora fechada (conferência concluída).');
        header('Location: ' . $redirect);
        exit;
    }

    // scope === 'client'
    if ($action === 'reopen') {
        $db->prepare("DELETE FROM billing_monthly_closures
            WHERE reference_month = :m AND scope = 'client' AND client_id = :cid")
            ->execute(['m' => $month, 'cid' => $id]);
        flash_set('success', 'Cliente reaberto.');
        header('Location: ' . $redirect);
        exit;
    }

    // Consolidar cliente: exigir que todas as operadoras do cliente COM sessões
    // no mês já estejam fechadas.
    $sessions = billing_closure_fetch_sessions($db, $month, false, ['client_id' => $id]);
    if (empty($sessions)) {
        flash_set('error', 'Este cliente não tem atendimentos pendentes de fechamento na competência.');
        header('Location: ' . $redirect);
        exit;
    }
    // Operadoras distintas com sessões no mês.
    $operatorIds = [];
    foreach ($sessions as $s) {
        $operatorIds[(int)($s['health_insurer_id'] ?? 0)] = true;
    }
    $operatorIds = array_keys($operatorIds);

    $status = billing_closure_scoped_status($db, $month);
    $pending = [];
    foreach ($operatorIds as $opId) {
        if ($opId <= 0) {
            $pending[] = 'Sem operadora';
            continue;
        }
        if (!isset($status['operators'][$opId])) {
            $op = operators_find($opId);
            $pending[] = $op ? (string)$op['name'] : ('#' . $opId);
        }
    }
    if (!empty($pending)) {
        flash_set('error', 'Feche primeiro todas as operadoras deste cliente. Pendentes: ' . implode(', ', $pending));
        header('Location: ' . $redirect);
        exit;
    }

    $totalSessions = count($sessions);
    $db->prepare("
        INSERT INTO billing_monthly_closures
            (reference_month, scope, client_id, health_insurer_id, status, total_sessions, closed_by_user_id, closed_at)
        VALUES (:m, 'client', :cid, NULL, 'client_closed', :ts, :uid, NOW())
        ON DUPLICATE KEY UPDATE
            status = 'client_closed', total_sessions = VALUES(total_sessions),
            closed_by_user_id = VALUES(closed_by_user_id), closed_at = NOW(),
            reversed_at = NULL, reversed_by_user_id = NULL
    ")->execute(['m' => $month, 'cid' => $id, 'ts' => $totalSessions, 'uid' => $userId]);

    audit_log('close', 'billing_monthly_closures', 'client:' . $id, null,
        ['month' => $month, 'scope' => 'client', 'sessions' => $totalSessions]);
    flash_set('success', 'Cliente consolidado! Já pode ser enviado ao Financeiro.');
    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    error_log('[FECHAMENTO_SCOPE] ' . $e->getMessage());
    flash_set('error', 'Erro: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
