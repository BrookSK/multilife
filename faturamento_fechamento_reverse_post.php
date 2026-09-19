<?php

declare(strict_types=1);

/**
 * Estorna o fechamento de uma competência (YYYY-MM).
 *
 * Desfaz o que faturamento_fechamento_post.php gerou:
 *   - Cancela (is_active = 0, status = 'cancelled') os financial_entries do fechamento.
 *   - Libera as sessões (billing_document_requirements.monthly_closure_id = NULL) para refaturar.
 *   - Marca o fechamento como 'reversed'.
 *
 * POST: month (YYYY-MM)
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /faturamento_fechamento.php');
    exit;
}

$month = billing_closure_normalize_month($_POST['month'] ?? null);
if ($month === null) {
    flash_set('error', 'Competência inválida.');
    header('Location: /faturamento_fechamento.php');
    exit;
}

$db = db();
$userId = auth_user_id();
$redirect = '/faturamento_fechamento.php?month=' . urlencode($month);

$closure = billing_closure_find($db, $month);
if ($closure === null || (string)$closure['status'] !== 'closed') {
    flash_set('error', 'Não há fechamento ativo para estornar nesta competência.');
    header('Location: ' . $redirect);
    exit;
}
$closureId = (int)$closure['id'];

try {
    $db->beginTransaction();

    // Cancelar os lançamentos financeiros gerados pelo fechamento.
    // Mantém o histórico (não deleta): is_active=0 remove das listas; status='cancelled' registra o estorno.
    $cancelStmt = $db->prepare(
        "UPDATE financial_entries
         SET is_active = 0, status = 'cancelled', updated_at = NOW()
         WHERE monthly_closure_id = :cid"
    );
    $cancelStmt->execute(['cid' => $closureId]);
    $cancelledEntries = $cancelStmt->rowCount();

    // Liberar as sessões para refaturamento.
    $freeStmt = $db->prepare(
        "UPDATE billing_document_requirements SET monthly_closure_id = NULL WHERE monthly_closure_id = :cid"
    );
    $freeStmt->execute(['cid' => $closureId]);
    $freedSessions = $freeStmt->rowCount();

    // Marcar o fechamento como estornado.
    $db->prepare(
        "UPDATE billing_monthly_closures
         SET status = 'reversed', reversed_by_user_id = :uid, reversed_at = NOW()
         WHERE id = :id"
    )->execute(['uid' => $userId, 'id' => $closureId]);

    audit_log('reverse', 'billing_monthly_closures', (string)$closureId, [
        'reference_month' => $month,
        'status' => 'closed',
    ], [
        'status' => 'reversed',
        'cancelled_entries' => $cancelledEntries,
        'freed_sessions' => $freedSessions,
    ]);

    $db->commit();

    flash_set('success', 'Fechamento de ' . date('m/Y', strtotime($month . '-01')) . ' estornado. '
        . $cancelledEntries . ' lançamento(s) cancelado(s) e ' . $freedSessions . ' sessão(ões) liberada(s).');
    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[FATURAMENTO_FECHAMENTO_REVERSE] ' . $e->getMessage());
    flash_set('error', 'Erro ao estornar o fechamento: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
