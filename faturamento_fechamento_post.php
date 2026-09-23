<?php

declare(strict_types=1);

/**
 * Fecha o faturamento de uma competência (YYYY-MM).
 *
 * Gera, a partir das sessões aprovadas e ainda não faturadas do mês:
 *   - Contas a RECEBER: 1 lançamento income por PACIENTE (soma authorized_value das sessões).
 *   - Contas a PAGAR:   1 lançamento expense por PROFISSIONAL (soma agreed_value das sessões).
 *
 * Proteções:
 *   - Competência única: não fecha duas vezes (billing_monthly_closures.reference_month UNIQUE).
 *   - Cada sessão recebe monthly_closure_id: não entra em outro fechamento (sem dupla contagem).
 *
 * POST: month (YYYY-MM)
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.closure.manage');

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
[$firstDay, $lastDay] = billing_closure_month_range($month);

// DDL fora da transação (commit implícito no MySQL).
try {
    $db->exec("CREATE TABLE IF NOT EXISTS billing_monthly_closures (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        reference_month VARCHAR(7) NOT NULL,
        status ENUM('closed','reversed') NOT NULL DEFAULT 'closed',
        total_sessions INT UNSIGNED NOT NULL DEFAULT 0,
        total_receivable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_payable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        receivable_entries INT UNSIGNED NOT NULL DEFAULT 0,
        payable_entries INT UNSIGNED NOT NULL DEFAULT 0,
        closed_by_user_id INT UNSIGNED NULL,
        closed_at DATETIME NULL,
        reversed_by_user_id INT UNSIGNED NULL,
        reversed_at DATETIME NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_bmc_reference_month (reference_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS billing_monthly_closure_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        closure_id BIGINT UNSIGNED NOT NULL,
        requirement_id BIGINT UNSIGNED NOT NULL,
        assignment_id BIGINT UNSIGNED NULL,
        patient_id BIGINT UNSIGNED NULL,
        professional_user_id INT UNSIGNED NULL,
        health_insurer_id INT UNSIGNED NULL,
        session_date DATE NULL,
        receivable_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payable_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_bmci_closure (closure_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}
try { $db->exec("ALTER TABLE billing_document_requirements ADD COLUMN monthly_closure_id BIGINT UNSIGNED NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE financial_entries ADD COLUMN monthly_closure_id BIGINT UNSIGNED NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE billing_document_requirements ADD COLUMN created_by_user_id INT UNSIGNED NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE billing_document_requirements ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// Já existe fechamento ativo para esta competência?
$existing = billing_closure_find($db, $month);
if ($existing !== null && (string)$existing['status'] === 'closed') {
    flash_set('error', 'A competência ' . date('m/Y', strtotime($firstDay)) . ' já está fechada. Estorne antes de refazer.');
    header('Location: ' . $redirect);
    exit;
}

// Buscar sessões faturáveis (aprovadas e não faturadas).
$sessions = billing_closure_fetch_sessions($db, $month, false);
if (empty($sessions)) {
    flash_set('error', 'Não há sessões aprovadas e pendentes de faturamento em ' . date('m/Y', strtotime($firstDay)) . '.');
    header('Location: ' . $redirect);
    exit;
}

$byPatient = billing_closure_group_by_patient($sessions);
$totals = billing_closure_totals($byPatient);

// Consolidar A PAGAR por profissional.
$byProfessional = [];
foreach ($sessions as $s) {
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
    $byProfessional[$profId]['payable'] += (float)$s['payable_per_session'];
}

$monthLabel = date('m/Y', strtotime($firstDay));

try {
    $db->beginTransaction();

    // Recheck da unicidade dentro da transação (evita corrida).
    $reStmt = $db->prepare("SELECT id, status FROM billing_monthly_closures WHERE reference_month = :m LIMIT 1 FOR UPDATE");
    $reStmt->execute(['m' => $month]);
    $reRow = $reStmt->fetch(PDO::FETCH_ASSOC);

    if ($reRow && (string)$reRow['status'] === 'closed') {
        throw new RuntimeException('Competência já fechada.');
    }

    // Cabeçalho do fechamento (reaproveita um registro estornado da mesma competência).
    if ($reRow) {
        $closureId = (int)$reRow['id'];
        $db->prepare("UPDATE billing_monthly_closures
            SET status = 'closed', total_sessions = :ts, total_receivable = :tr, total_payable = :tp,
                receivable_entries = :re, payable_entries = :pe,
                closed_by_user_id = :uid, closed_at = NOW(), reversed_by_user_id = NULL, reversed_at = NULL
            WHERE id = :id")
            ->execute([
                'ts' => (int)$totals['sessions'],
                'tr' => round((float)$totals['receivable'], 2),
                'tp' => round((float)$totals['payable'], 2),
                're' => count($byPatient),
                'pe' => count($byProfessional),
                'uid' => $userId,
                'id' => $closureId,
            ]);
    } else {
        $db->prepare("INSERT INTO billing_monthly_closures
            (reference_month, status, total_sessions, total_receivable, total_payable, receivable_entries, payable_entries, closed_by_user_id, closed_at)
            VALUES (:m, 'closed', :ts, :tr, :tp, :re, :pe, :uid, NOW())")
            ->execute([
                'm' => $month,
                'ts' => (int)$totals['sessions'],
                'tr' => round((float)$totals['receivable'], 2),
                'tp' => round((float)$totals['payable'], 2),
                're' => count($byPatient),
                'pe' => count($byProfessional),
                'uid' => $userId,
            ]);
        $closureId = (int)$db->lastInsertId();
    }

    // Contas a RECEBER: 1 income por paciente.
    $insIncome = $db->prepare(
        "INSERT INTO financial_entries
            (entry_type, category, assignment_id, patient_id, amount, description, entry_date, status, cost_center, supplier_name, monthly_closure_id, is_active, created_by_user_id, created_at)
         VALUES ('income', 'Faturamento Mensal', :assignment_id, :patient_id, :amount, :description, :entry_date, 'pending', 'Fechamento Mensal', :supplier_name, :closure_id, 1, :uid, NOW())"
    );
    foreach ($byPatient as $p) {
        // assignment_id de referência: o da primeira linha do paciente (para join com operadora nas telas).
        $refAssignmentId = null;
        foreach ($sessions as $s) {
            if ((int)$s['patient_id'] === (int)$p['patient_id']) { $refAssignmentId = (int)$s['assignment_id']; break; }
        }
        $insurerLabel = implode(', ', array_values($p['insurers']));
        $desc = 'Faturamento ' . $monthLabel . ' - ' . $p['patient_name']
            . ' (' . (int)$p['total_sessions'] . ' atend.) - Operadora: ' . $insurerLabel;
        $insIncome->execute([
            'assignment_id' => $refAssignmentId,
            'patient_id' => (int)$p['patient_id'],
            'amount' => round((float)$p['total_receivable'], 2),
            'description' => $desc,
            'entry_date' => $lastDay,
            'supplier_name' => (string)$p['patient_name'],
            'closure_id' => $closureId,
            'uid' => $userId,
        ]);
    }

    // Contas a PAGAR: 1 expense por profissional.
    $insExpense = $db->prepare(
        "INSERT INTO financial_entries
            (entry_type, category, professional_user_id, amount, description, entry_date, status, cost_center, supplier_name, monthly_closure_id, is_active, created_by_user_id, created_at)
         VALUES ('expense', 'Repasse Profissional', :professional_user_id, :amount, :description, :entry_date, 'pending', 'Fechamento Mensal', :supplier_name, :closure_id, 1, :uid, NOW())"
    );
    foreach ($byProfessional as $prof) {
        $desc = 'Repasse ' . $monthLabel . ' - ' . $prof['professional_name']
            . ' (' . (int)$prof['sessions'] . ' atend.)';
        $insExpense->execute([
            'professional_user_id' => $prof['professional_user_id'] > 0 ? $prof['professional_user_id'] : null,
            'amount' => round((float)$prof['payable'], 2),
            'supplier_name' => (string)$prof['professional_name'],
            'description' => $desc,
            'entry_date' => $lastDay,
            'closure_id' => $closureId,
            'uid' => $userId,
        ]);
    }

    // Marcar as sessões como faturadas + registrar os itens do fechamento.
    $markStmt = $db->prepare("UPDATE billing_document_requirements SET monthly_closure_id = :cid WHERE id = :id AND monthly_closure_id IS NULL");
    $itemStmt = $db->prepare(
        "INSERT INTO billing_monthly_closure_items
            (closure_id, requirement_id, assignment_id, patient_id, professional_user_id, health_insurer_id, session_date, receivable_amount, payable_amount)
         VALUES (:cid, :rid, :aid, :pid, :prof, :ins, :sdate, :recv, :pay)"
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
            'sdate' => $s['session_date'],
            'recv' => round((float)$s['receivable_per_session'], 2),
            'pay' => round((float)$s['payable_per_session'], 2),
        ]);
    }

    audit_log('close', 'billing_monthly_closures', (string)$closureId, null, [
        'reference_month' => $month,
        'sessions' => (int)$totals['sessions'],
        'receivable' => round((float)$totals['receivable'], 2),
        'payable' => round((float)$totals['payable'], 2),
        'receivable_entries' => count($byPatient),
        'payable_entries' => count($byProfessional),
    ]);

    $db->commit();

    flash_set('success', 'Mês ' . $monthLabel . ' fechado! Geradas ' . count($byPatient)
        . ' conta(s) a receber e ' . count($byProfessional) . ' conta(s) a pagar.');
    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[FATURAMENTO_FECHAMENTO] ' . $e->getMessage());
    flash_set('error', 'Erro ao fechar o mês: ' . $e->getMessage());
    header('Location: ' . $redirect);
    exit;
}
