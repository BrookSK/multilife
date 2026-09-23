<?php

declare(strict_types=1);

/**
 * Gera as Contas a Receber/Pagar de um CLIENTE consolidado (OK do financeiro).
 *
 * Pré-condição: o cliente precisa estar consolidado na conferência
 * (billing_monthly_closures scope=client, status=client_closed).
 *
 * Ao dar OK:
 *   - Gera 1 income (a receber) por PACIENTE do cliente no mês.
 *   - Gera 1 expense (a pagar) por PROFISSIONAL do cliente no mês.
 *   - Marca as sessões (billing_document_requirements.monthly_closure_id) para
 *     não recontar, e registra os itens em billing_monthly_closure_items.
 *   - Marca o fechamento do cliente como 'sent_to_finance'.
 *
 * POST: month (YYYY-MM), client_id
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /financeiro_fechamento.php');
    exit;
}

$month = billing_closure_normalize_month($_POST['month'] ?? null);
$clientId = (int)($_POST['client_id'] ?? 0);
$redirect = '/financeiro_fechamento.php?month=' . urlencode((string)$month);

if ($month === null || $clientId <= 0) {
    flash_set('error', 'Parâmetros inválidos.');
    header('Location: ' . $redirect);
    exit;
}

$db = db();
$userId = (int)auth_user_id();
clients_ensure_schema();
[$firstDay, $lastDay] = billing_closure_month_range($month);
$monthLabel = date('m/Y', strtotime($firstDay));

// Garantir colunas denormalizadas no financeiro.
foreach ([
    "ALTER TABLE financial_entries ADD COLUMN client_id INT UNSIGNED NULL",
    "ALTER TABLE financial_entries ADD COLUMN health_insurer_id INT UNSIGNED NULL",
    "ALTER TABLE financial_entries ADD COLUMN monthly_closure_id BIGINT UNSIGNED NULL",
    "ALTER TABLE billing_monthly_closure_items ADD COLUMN client_id INT UNSIGNED NULL",
] as $alter) {
    try { $db->exec($alter); } catch (Throwable $e) {}
}

// Verificar consolidação do cliente.
$scoped = billing_closure_scoped_status($db, $month);
$clientClosure = $scoped['clients'][$clientId] ?? null;
if ($clientClosure === null) {
    flash_set('error', 'Este cliente ainda não foi consolidado na conferência.');
    header('Location: ' . $redirect);
    exit;
}
if ((string)$clientClosure['status'] === 'sent_to_finance') {
    flash_set('error', 'Este cliente já foi enviado ao financeiro nesta competência.');
    header('Location: ' . $redirect);
    exit;
}

// Sessões faturáveis (não faturadas) do cliente no mês.
$sessions = billing_closure_fetch_sessions($db, $month, false, ['client_id' => $clientId]);
if (empty($sessions)) {
    flash_set('error', 'Não há atendimentos pendentes para faturar deste cliente.');
    header('Location: ' . $redirect);
    exit;
}

// Agregar A RECEBER por paciente e A PAGAR por profissional.
$byPatient = [];
$byProfessional = [];
foreach ($sessions as $s) {
    $pid = (int)$s['patient_id'];
    $recv = (float)$s['receivable_per_session'];
    $pay = (float)$s['payable_per_session'];

    if (!isset($byPatient[$pid])) {
        $byPatient[$pid] = [
            'patient_id' => $pid,
            'patient_name' => (string)($s['patient_name'] ?? '-'),
            'ref_assignment_id' => (int)$s['assignment_id'],
            'health_insurer_id' => $s['health_insurer_id'] !== null ? (int)$s['health_insurer_id'] : null,
            'sessions' => 0,
            'receivable' => 0.0,
        ];
    }
    $byPatient[$pid]['sessions']++;
    $byPatient[$pid]['receivable'] += $recv;

    $profId = (int)($s['professional_user_id'] ?? 0);
    if (!isset($byProfessional[$profId])) {
        $byProfessional[$profId] = [
            'professional_user_id' => $profId,
            'professional_name' => (string)($s['professional_name'] ?? '-'),
            'sessions' => 0,
            'payable' => 0.0,
        ];
    }
    $byProfessional[$profId]['sessions']++;
    $byProfessional[$profId]['payable'] += $pay;
}

$totalReceivable = array_sum(array_column($byPatient, 'receivable'));
$totalPayable = array_sum(array_column($byProfessional, 'payable'));

try {
    $db->beginTransaction();

    // Atualizar o cabeçalho do fechamento do cliente com os totais e marcar como enviado.
    $closureId = (int)$clientClosure['id'];
    $db->prepare("UPDATE billing_monthly_closures
        SET status = 'sent_to_finance', total_sessions = :ts, total_receivable = :tr, total_payable = :tp,
            receivable_entries = :re, payable_entries = :pe,
            sent_to_finance_at = NOW(), sent_to_finance_by_user_id = :uid
        WHERE id = :id")
        ->execute([
            'ts' => count($sessions),
            'tr' => round($totalReceivable, 2),
            'tp' => round($totalPayable, 2),
            're' => count($byPatient),
            'pe' => count($byProfessional),
            'uid' => $userId,
            'id' => $closureId,
        ]);

    // Contas a RECEBER (income) por paciente.
    $insIncome = $db->prepare(
        "INSERT INTO financial_entries
            (entry_type, category, assignment_id, patient_id, client_id, health_insurer_id, amount, description, entry_date, status, cost_center, supplier_name, monthly_closure_id, is_active, created_by_user_id, created_at)
         VALUES ('income', 'Faturamento Mensal', :assignment_id, :patient_id, :client_id, :hi, :amount, :description, :entry_date, 'pending', 'Fechamento Mensal', :supplier_name, :closure_id, 1, :uid, NOW())"
    );
    foreach ($byPatient as $p) {
        $desc = 'Faturamento ' . $monthLabel . ' - ' . $p['patient_name'] . ' (' . (int)$p['sessions'] . ' atend.)';
        $insIncome->execute([
            'assignment_id' => $p['ref_assignment_id'] ?: null,
            'patient_id' => (int)$p['patient_id'],
            'client_id' => $clientId,
            'hi' => $p['health_insurer_id'],
            'amount' => round((float)$p['receivable'], 2),
            'description' => $desc,
            'entry_date' => $lastDay,
            'supplier_name' => (string)$p['patient_name'],
            'closure_id' => $closureId,
            'uid' => $userId,
        ]);
    }

    // Contas a PAGAR (expense) por profissional.
    $insExpense = $db->prepare(
        "INSERT INTO financial_entries
            (entry_type, category, professional_user_id, client_id, amount, description, entry_date, status, cost_center, supplier_name, monthly_closure_id, is_active, created_by_user_id, created_at)
         VALUES ('expense', 'Repasse Profissional', :professional_user_id, :client_id, :amount, :description, :entry_date, 'pending', 'Fechamento Mensal', :supplier_name, :closure_id, 1, :uid, NOW())"
    );
    foreach ($byProfessional as $prof) {
        $desc = 'Repasse ' . $monthLabel . ' - ' . $prof['professional_name'] . ' (' . (int)$prof['sessions'] . ' atend.)';
        $insExpense->execute([
            'professional_user_id' => $prof['professional_user_id'] > 0 ? $prof['professional_user_id'] : null,
            'client_id' => $clientId,
            'amount' => round((float)$prof['payable'], 2),
            'description' => $desc,
            'entry_date' => $lastDay,
            'supplier_name' => (string)$prof['professional_name'],
            'closure_id' => $closureId,
            'uid' => $userId,
        ]);
    }

    // Marcar sessões como faturadas + registrar itens.
    $markStmt = $db->prepare("UPDATE billing_document_requirements SET monthly_closure_id = :cid WHERE id = :id AND monthly_closure_id IS NULL");
    $itemStmt = $db->prepare(
        "INSERT INTO billing_monthly_closure_items
            (closure_id, requirement_id, assignment_id, patient_id, professional_user_id, health_insurer_id, client_id, session_date, receivable_amount, payable_amount)
         VALUES (:cid, :rid, :aid, :pid, :prof, :ins, :client, :sdate, :recv, :pay)"
    );
    foreach ($sessions as $s) {
        $markStmt->execute(['cid' => $closureId, 'id' => (int)$s['requirement_id']]);
        $itemStmt->execute([
            'cid' => $closureId,
            'rid' => (int)$s['requirement_id'],
            'aid' => (int)$s['assignment_id'],
            'pid' => (int)$s['patient_id'],
            'prof' => (int)($s['professional_user_id'] ?? 0) ?: null,
            'ins' => $s['health_insurer_id'] !== null ? (int)$s['health_insurer_id'] : null,
            'client' => $clientId,
            'sdate' => $s['session_date'],
            'recv' => round((float)$s['receivable_per_session'], 2),
            'pay' => round((float)$s['payable_per_session'], 2),
        ]);
    }

    audit_log('close', 'billing_monthly_closures', (string)$closureId, null, [
        'reference_month' => $month,
        'client_id' => $clientId,
        'sessions' => count($sessions),
        'receivable' => round($totalReceivable, 2),
        'payable' => round($totalPayable, 2),
    ]);

    $db->commit();
    flash_set('success', 'Contas geradas! ' . count($byPatient) . ' a receber e ' . count($byProfessional) . ' a pagar.');
    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[FINANCEIRO_FECHAMENTO] ' . $e->getMessage());
    flash_set('error', 'Erro ao gerar contas: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
