<?php

declare(strict_types=1);

/**
 * Fechamento Mensal de Faturamento por Paciente.
 *
 * Camada de conferência e consolidação ANTES da geração financeira (reunião 15/09).
 * Mostra, para a competência escolhida, os atendimentos (sessões aprovadas e ainda
 * não faturadas) agrupados por paciente -> profissional/especialidade, com os
 * totais a RECEBER da operadora e a PAGAR aos profissionais.
 *
 * O botão "Fechar mês" (faturamento_fechamento_post.php) gera as Contas a Receber
 * e a Pagar. O estorno (faturamento_fechamento_reverse_post.php) desfaz.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
// Permissão dedicada: a equipe de ADMISSÃO pode fechar o mês sem ter acesso
// financeiro (Contas a Pagar/Receber/Lançamentos continuam sob finance.manage).
rbac_require_permission('billing.closure.manage');

$db = db();

// Garantir estruturas (idempotente) caso a migration ainda não tenha rodado.
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
} catch (Throwable $e) {}
try { $db->exec("ALTER TABLE billing_document_requirements ADD COLUMN monthly_closure_id BIGINT UNSIGNED NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE financial_entries ADD COLUMN monthly_closure_id BIGINT UNSIGNED NULL"); } catch (Throwable $e) {}

// Competência selecionada (default: mês atual).
$month = billing_closure_normalize_month($_GET['month'] ?? null) ?? date('Y-m');

// Estado do fechamento desta competência.
$closure = billing_closure_find($db, $month);
$isClosed = $closure !== null && (string)$closure['status'] === 'closed';

// Sessões faturáveis (não faturadas) + agregação por paciente.
$sessions = billing_closure_fetch_sessions($db, $month, false);
$byPatient = billing_closure_group_by_patient($sessions);
$totals = billing_closure_totals($byPatient);

$monthLabel = date('m/Y', strtotime($month . '-01'));
$brl = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

view_header('Fechamento Mensal de Faturamento');

echo '<div class="grid">';

// Cabeçalho + seletor de competência
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Fechamento Mensal de Faturamento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Consolidação dos atendimentos por paciente antes de gerar Contas a Receber e a Pagar.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<form method="get" action="/faturamento_fechamento.php" style="display:flex;gap:8px;align-items:center">';
echo '<label style="font-size:13px;font-weight:600">Competência</label>';
echo '<input type="month" name="month" value="' . h($month) . '" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px">';
echo '<button class="btn btnPrimary" type="submit">Ver</button>';
echo '</form>';
if (rbac_user_can((int)auth_user_id(), 'faturamento.manage')) {
    echo '<a class="btn" href="/faturamento_list.php">Faturamento</a>';
}
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Faixa de estado do fechamento
if ($isClosed) {
    $closedAt = $closure['closed_at'] ? date('d/m/Y H:i', strtotime((string)$closure['closed_at'])) : '-';
    echo '<section class="card col12" style="margin-top:16px;border-left:4px solid hsl(var(--success))">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    echo '<div>';
    echo '<div style="font-weight:800;font-size:16px;color:hsl(var(--success))">✅ Competência ' . h($monthLabel) . ' já fechada</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">'
        . 'Fechada em ' . h($closedAt) . ' · '
        . (int)$closure['total_sessions'] . ' sessão(ões) · '
        . 'A receber: ' . h($brl((float)$closure['total_receivable'])) . ' · '
        . 'A pagar: ' . h($brl((float)$closure['total_payable']))
        . '</div>';
    echo '</div>';
    echo '<form method="post" action="/faturamento_fechamento_reverse_post.php" onsubmit="return confirm(\'Estornar o fechamento de ' . h($monthLabel) . '? Isto cancela os lançamentos gerados e libera as sessões para refaturar.\');">';
    echo '<input type="hidden" name="month" value="' . h($month) . '">';
    echo '<button class="btn" type="submit" style="background:hsl(var(--destructive));color:#fff">↩️ Estornar fechamento</button>';
    echo '</form>';
    echo '</div>';
    // Links financeiros só para quem tem acesso financeiro (a admissão não deve ver).
    if (rbac_user_can((int)auth_user_id(), 'finance.manage')) {
        echo '<div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">';
        echo '<a class="btn" href="/finance_receivable_list.php">Ver Contas a Receber</a>';
        echo '<a class="btn" href="/finance_payable_list.php">Ver Contas a Pagar</a>';
        echo '</div>';
    }
    echo '</section>';
}

// Cards de resumo
echo '<section class="card col12" style="margin-top:16px">';
echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--primary)/.08);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Pacientes</div><div style="font-size:26px;font-weight:800">' . (int)$totals['patients'] . '</div></div>';
echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--primary)/.08);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Atendimentos</div><div style="font-size:26px;font-weight:800">' . (int)$totals['sessions'] . '</div></div>';
echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--success)/.10);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Total a receber</div><div style="font-size:22px;font-weight:800;color:hsl(var(--success))">' . h($brl((float)$totals['receivable'])) . '</div></div>';
echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--warning)/.12);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Total a pagar</div><div style="font-size:22px;font-weight:800;color:hsl(var(--warning-foreground));color:#92400e">' . h($brl((float)$totals['payable'])) . '</div></div>';
echo '</div>';
echo '</section>';

// Tabela de consolidação por paciente
echo '<section class="card col12" style="margin-top:16px">';

if (empty($byPatient)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    if ($isClosed) {
        echo 'Nenhuma sessão pendente de faturamento nesta competência (já fechada).';
    } else {
        echo 'Nenhuma sessão aprovada e não faturada em ' . h($monthLabel) . '.';
    }
    echo '</div>';
} else {
    echo '<div style="overflow:auto"><table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Operadora</th><th>Profissional</th><th>Especialidade</th>';
    echo '<th style="text-align:center">Atend.</th><th style="text-align:right">Valor/sessão (receber)</th>';
    echo '<th style="text-align:right">A receber</th><th style="text-align:right">A pagar</th>';
    echo '</tr></thead><tbody>';

    foreach ($byPatient as $p) {
        $insurerLabel = implode(', ', array_values($p['insurers']));
        $lineCount = count($p['lines']);
        $first = true;
        foreach ($p['lines'] as $line) {
            echo '<tr>';
            if ($first) {
                echo '<td style="font-weight:700;vertical-align:top" rowspan="' . $lineCount . '">' . h($p['patient_name']) . '</td>';
                echo '<td style="vertical-align:top" rowspan="' . $lineCount . '">' . h($insurerLabel) . '</td>';
            }
            echo '<td>' . h((string)$line['professional_name']) . '</td>';
            echo '<td>' . h((string)$line['specialty']) . '</td>';
            echo '<td style="text-align:center">' . (int)$line['sessions'] . '</td>';
            echo '<td style="text-align:right">' . h($brl((float)$line['receivable_per_session'])) . '</td>';
            echo '<td style="text-align:right;color:#059669;font-weight:600">' . h($brl((float)$line['receivable'])) . '</td>';
            echo '<td style="text-align:right;color:#b45309;font-weight:600">' . h($brl((float)$line['payable'])) . '</td>';
            echo '</tr>';
            $first = false;
        }
        // Subtotal do paciente
        echo '<tr style="background:hsla(var(--muted)/.25)">';
        echo '<td colspan="4" style="text-align:right;font-weight:700">Total do paciente (' . (int)$p['total_sessions'] . ' atend.)</td>';
        echo '<td style="text-align:center;font-weight:700">' . (int)$p['total_sessions'] . '</td>';
        echo '<td></td>';
        echo '<td style="text-align:right;font-weight:800;color:#059669">' . h($brl((float)$p['total_receivable'])) . '</td>';
        echo '<td style="text-align:right;font-weight:800;color:#b45309">' . h($brl((float)$p['total_payable'])) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // Botão Fechar Mês (só quando ainda não fechado e há o que faturar)
    if (!$isClosed) {
        echo '<div style="margin-top:20px;padding-top:16px;border-top:1px solid hsl(var(--border));display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Ao fechar, o sistema gera <strong>uma conta a receber por paciente</strong> e <strong>uma conta a pagar por profissional</strong>, e marca estas sessões como faturadas.</div>';
        echo '<form method="post" action="/faturamento_fechamento_post.php" onsubmit="return confirm(\'Confirmar o fechamento de ' . h($monthLabel) . '? Serão gerados os lançamentos de Contas a Receber e a Pagar.\');">';
        echo '<input type="hidden" name="month" value="' . h($month) . '">';
        echo '<button class="btn btnPrimary" type="submit" style="font-size:15px;padding:12px 24px">🔒 Fechar mês ' . h($monthLabel) . '</button>';
        echo '</form>';
        echo '</div>';
    }
}

echo '</section>';
echo '</div>';

view_footer();
