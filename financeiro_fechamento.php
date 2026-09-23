<?php

declare(strict_types=1);

/**
 * Fechamento Financeiro — geração de Contas a Pagar/Receber.
 *
 * Diferente da tela de conferência (faturamento_fechamento.php), ESTA tela é do
 * FINANCEIRO e MOSTRA VALORES. Lista os clientes já CONSOLIDADOS na conferência
 * (scope=client, status=client_closed) e permite dar o "OK" que:
 *   - gera 1 Conta a Receber por paciente (income);
 *   - gera 1 Conta a Pagar por profissional (expense);
 *   - marca as sessões como faturadas (monthly_closure_id) para não recontar;
 *   - marca o fechamento do cliente como enviado ao financeiro (sent_to_finance).
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$db = db();
clients_ensure_schema();

$month = billing_closure_normalize_month($_GET['month'] ?? null) ?? date('Y-m');
$monthLabel = date('m/Y', strtotime($month . '-01'));
$brl = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

// Sessões faturáveis do mês, agrupadas por cliente -> operadora (com valores).
$sessions = billing_closure_fetch_sessions($db, $month, false);
$byClient = billing_closure_group_by_client($sessions);
$scoped = billing_closure_scoped_status($db, $month);

view_header('Fechamento Financeiro');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Fechamento Financeiro</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Clientes consolidados pela conferência. Dê o <strong>OK</strong> para gerar as Contas a Receber e a Pagar.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/faturamento_fechamento.php?month=' . h($month) . '">← Conferência</a>';
echo '<a class="btn" href="/finance_receivable_list.php">Contas a Receber</a>';
echo '<a class="btn" href="/finance_payable_list.php">Contas a Pagar</a>';
echo '</div>';
echo '</div>';
echo '<form method="get" action="/financeiro_fechamento.php" style="margin-top:14px;display:flex;gap:8px;align-items:center">';
echo '<label style="font-size:13px;font-weight:600">Competência</label>';
echo '<input type="month" name="month" value="' . h($month) . '" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px">';
echo '<button class="btn btnPrimary" type="submit">Ver</button>';
echo '</form>';
echo '</section>';

if (empty($byClient)) {
    echo '<section class="card col12" style="margin-top:16px"><div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum atendimento pendente de faturamento em ' . h($monthLabel) . '.</div></section>';
} else {
    foreach ($byClient as $c) {
        $clientId = (int)$c['client_id'];
        $clientRow = $scoped['clients'][$clientId] ?? null;
        $clientConsolidated = $clientRow !== null;
        $alreadySent = $clientConsolidated && (string)$clientRow['status'] === 'sent_to_finance';

        echo '<section class="card col12" style="margin-top:16px">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-bottom:2px solid hsl(var(--border));padding-bottom:10px;margin-bottom:12px">';
        echo '<div>';
        echo '<span style="font-size:18px;font-weight:900">🏢 ' . h((string)$c['client_name']) . '</span>';
        echo '<span style="margin-left:10px;font-size:13px;color:hsl(var(--muted-foreground))">' . (int)$c['total_sessions'] . ' atend.</span>';
        echo '<span style="margin-left:10px;font-weight:700;color:#059669">A receber: ' . h($brl((float)$c['total_receivable'])) . '</span>';
        echo '<span style="margin-left:10px;font-weight:700;color:#b45309">A pagar: ' . h($brl((float)$c['total_payable'])) . '</span>';
        echo '</div>';
        echo '<div>';
        if ($alreadySent) {
            echo '<span style="padding:6px 12px;border-radius:12px;font-size:13px;font-weight:700;background:#d1fae5;color:#065f46">✅ Enviado ao financeiro</span>';
        } elseif ($clientConsolidated) {
            echo '<form method="post" action="/financeiro_fechamento_post.php" onsubmit="return confirm(\'Gerar Contas a Receber e a Pagar do cliente ' . h((string)$c['client_name']) . '?\')">';
            echo '<input type="hidden" name="month" value="' . h($month) . '"><input type="hidden" name="client_id" value="' . $clientId . '">';
            echo '<button class="btn btnPrimary" type="submit" style="font-size:14px;padding:10px 18px">✅ OK — gerar contas</button>';
            echo '</form>';
        } else {
            echo '<span style="padding:6px 12px;border-radius:12px;font-size:13px;font-weight:700;background:#fef3c7;color:#92400e">Aguardando consolidação na conferência</span>';
        }
        echo '</div>';
        echo '</div>';

        // Operadoras com valores
        foreach ($c['operators'] as $o) {
            echo '<div style="border:1px solid hsl(var(--border));border-radius:10px;padding:12px;margin-bottom:12px">';
            echo '<div style="font-weight:700;margin-bottom:8px">' . h((string)$o['insurer_name'])
                . ' <span style="font-weight:400;font-size:12px;color:hsl(var(--muted-foreground))">· ' . (int)$o['total_sessions'] . ' atend. · '
                . 'A receber ' . h($brl((float)$o['total_receivable'])) . ' · A pagar ' . h($brl((float)$o['total_payable'])) . '</span></div>';
            echo '<div style="overflow:auto"><table>';
            echo '<thead><tr><th>Paciente</th><th>Profissional</th><th>Especialidade</th><th style="text-align:center">Atend.</th><th style="text-align:right">A receber</th><th style="text-align:right">A pagar</th></tr></thead><tbody>';
            foreach ($o['lines'] as $line) {
                echo '<tr>';
                echo '<td style="font-weight:600">' . h((string)$line['patient_name']) . '</td>';
                echo '<td>' . h((string)$line['professional_name']) . '</td>';
                echo '<td>' . h((string)$line['specialty']) . '</td>';
                echo '<td style="text-align:center">' . (int)$line['sessions'] . '</td>';
                echo '<td style="text-align:right;color:#059669;font-weight:600">' . h($brl((float)$line['receivable'])) . '</td>';
                echo '<td style="text-align:right;color:#b45309;font-weight:600">' . h($brl((float)$line['payable'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>';
        }

        echo '</section>';
    }
}

echo '</div>';
view_footer();
