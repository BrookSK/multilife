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
try { $db->exec("ALTER TABLE billing_document_requirements ADD COLUMN created_by_user_id INT UNSIGNED NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE billing_document_requirements ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// Competência selecionada (default: mês atual).
$month = billing_closure_normalize_month($_GET['month'] ?? null) ?? date('Y-m');

// Filtros de acompanhamento
$filters = [
    'patient_q' => trim((string)($_GET['patient_q'] ?? '')),
    'insurer_id' => (int)($_GET['insurer_id'] ?? 0),
    'professional_id' => (int)($_GET['professional_id'] ?? 0),
    'operator_id' => (int)($_GET['operator_id'] ?? 0),
];

// Modo acompanhamento: oculta os valores monetários (mantém as quantidades).
// Ativado por ?valores=0 ou para quem não tem acesso financeiro.
$canSeeValues = rbac_user_can((int)auth_user_id(), 'finance.manage');
if (isset($_GET['valores'])) {
    $showValues = $_GET['valores'] === '1' && $canSeeValues;
} else {
    $showValues = $canSeeValues;
}

// Estado do fechamento desta competência.
$closure = billing_closure_find($db, $month);
$isClosed = $closure !== null && (string)$closure['status'] === 'closed';

// Sessões faturáveis (não faturadas) + agregação por paciente.
$sessions = billing_closure_fetch_sessions($db, $month, false, $filters);
$byPatient = billing_closure_group_by_patient($sessions);
$totals = billing_closure_totals($byPatient);

// Dados para popular os selects de filtro.
$filterInsurers = [];
$filterProfessionals = [];
$filterOperators = [];
try {
    $filterInsurers = $db->query("SELECT id, name FROM health_insurers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $filterInsurers = []; }
try {
    $filterProfessionals = $db->query("
        SELECT DISTINCT u.id, u.name FROM users u
        INNER JOIN patient_assignments pa ON pa.professional_user_id = u.id
        WHERE u.name IS NOT NULL AND u.name != ''
        ORDER BY u.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $filterProfessionals = []; }
try {
    // Operadores = usuários que já registraram atendimentos manuais.
    $filterOperators = $db->query("
        SELECT DISTINCT u.id, u.name FROM users u
        INNER JOIN billing_document_requirements bdr ON bdr.created_by_user_id = u.id
        WHERE u.name IS NOT NULL AND u.name != ''
        ORDER BY u.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $filterOperators = []; }

$monthLabel = date('m/Y', strtotime($month . '-01'));
$brl = static fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
// Marca visual quando o valor está oculto (modo acompanhamento).
$hiddenVal = '<span style="color:hsl(var(--muted-foreground))">—</span>';

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
if (rbac_user_can((int)auth_user_id(), 'faturamento.manage')) {
    echo '<a class="btn" href="/faturamento_list.php">Faturamento</a>';
}
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Barra de filtros (competência + filtros de acompanhamento)
echo '<form method="get" action="/faturamento_fechamento.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Competência</label>';
echo '<input type="month" name="month" value="' . h($month) . '" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></div>';

echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Paciente</label>';
echo '<input type="text" name="patient_q" value="' . h((string)$filters['patient_q']) . '" placeholder="Nome do paciente" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></div>';

echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Cliente / Operadora</label>';
echo '<select name="insurer_id" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px">';
echo '<option value="0">Todas</option>';
foreach ($filterInsurers as $ins) {
    $sel = ((int)$filters['insurer_id'] === (int)$ins['id']) ? ' selected' : '';
    echo '<option value="' . (int)$ins['id'] . '"' . $sel . '>' . h((string)$ins['name']) . '</option>';
}
echo '</select></div>';

echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Profissional</label>';
echo '<select name="professional_id" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px">';
echo '<option value="0">Todos</option>';
foreach ($filterProfessionals as $prof) {
    $sel = ((int)$filters['professional_id'] === (int)$prof['id']) ? ' selected' : '';
    echo '<option value="' . (int)$prof['id'] . '"' . $sel . '>' . h((string)$prof['name']) . '</option>';
}
echo '</select></div>';

if (!empty($filterOperators)) {
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Operador</label>';
    echo '<select name="operator_id" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px">';
    echo '<option value="0">Todos</option>';
    foreach ($filterOperators as $op) {
        $sel = ((int)$filters['operator_id'] === (int)$op['id']) ? ' selected' : '';
        echo '<option value="' . (int)$op['id'] . '"' . $sel . '>' . h((string)$op['name']) . '</option>';
    }
    echo '</select></div>';
}

// Toggle de valores (só faz sentido para quem tem acesso financeiro)
if ($canSeeValues) {
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Valores</label>';
    echo '<select name="valores" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px">';
    echo '<option value="1"' . ($showValues ? ' selected' : '') . '>Mostrar valores</option>';
    echo '<option value="0"' . (!$showValues ? ' selected' : '') . '>Ocultar (acompanhamento)</option>';
    echo '</select></div>';
}

echo '<button class="btn btnPrimary" type="submit">Ver</button>';
echo '</form>';
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
        . (int)$closure['total_sessions'] . ' sessão(ões)'
        . ($showValues
            ? ' · A receber: ' . h($brl((float)$closure['total_receivable'])) . ' · A pagar: ' . h($brl((float)$closure['total_payable']))
            : '')
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
if ($showValues) {
    echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--success)/.10);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Total a receber</div><div style="font-size:22px;font-weight:800;color:hsl(var(--success))">' . h($brl((float)$totals['receivable'])) . '</div></div>';
    echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--warning)/.12);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Total a pagar</div><div style="font-size:22px;font-weight:800;color:hsl(var(--warning-foreground));color:#92400e">' . h($brl((float)$totals['payable'])) . '</div></div>';
}
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
    // Nº de colunas total (para colspan das linhas de detalhe expansíveis)
    $detailModals = []; // acumula o HTML dos pop-ups para renderizar após a tabela

    echo '<div style="overflow:auto"><table>';
    echo '<thead><tr>';
    echo '<th>Paciente</th><th>Operadora</th><th>Profissional</th><th>Especialidade</th>';
    echo '<th style="text-align:center">Atend.</th>';
    if ($showValues) {
        echo '<th style="text-align:right">Valor/sessão (receber)</th>';
        echo '<th style="text-align:right">A receber</th><th style="text-align:right">A pagar</th>';
    }
    echo '<th style="text-align:center">Detalhes</th>';
    echo '</tr></thead><tbody>';

    foreach ($byPatient as $p) {
        $insurerLabel = implode(', ', array_values($p['insurers']));
        $lineCount = count($p['lines']);
        $pid = (int)$p['patient_id'];
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
            if ($showValues) {
                echo '<td style="text-align:right">' . h($brl((float)$line['receivable_per_session'])) . '</td>';
                echo '<td style="text-align:right;color:#059669;font-weight:600">' . h($brl((float)$line['receivable'])) . '</td>';
                echo '<td style="text-align:right;color:#b45309;font-weight:600">' . h($brl((float)$line['payable'])) . '</td>';
            }
            // Botão de detalhes (abre o pop-up completo do paciente) — 1 vez por paciente.
            if ($first) {
                echo '<td style="text-align:center;vertical-align:top" rowspan="' . $lineCount . '">'
                    . '<button type="button" class="btn" style="font-size:12px;padding:4px 10px" onclick="fechDetail(' . $pid . ')">🔍 Detalhes</button></td>';
            }
            echo '</tr>';
            $first = false;
        }
        // Subtotal do paciente
        echo '<tr style="background:hsla(var(--muted)/.25)">';
        echo '<td colspan="4" style="text-align:right;font-weight:700">Total do paciente (' . (int)$p['total_sessions'] . ' atend.)</td>';
        echo '<td style="text-align:center;font-weight:700">' . (int)$p['total_sessions'] . '</td>';
        if ($showValues) {
            echo '<td></td>';
            echo '<td style="text-align:right;font-weight:800;color:#059669">' . h($brl((float)$p['total_receivable'])) . '</td>';
            echo '<td style="text-align:right;font-weight:800;color:#b45309">' . h($brl((float)$p['total_payable'])) . '</td>';
        }
        echo '<td></td>';
        echo '</tr>';

        // ---- Pop-up de detalhes deste paciente (renderizado após a tabela) ----
        ob_start();
        $items = $p['session_items'] ?? [];
        usort($items, static fn($a, $b) => strcmp((string)($a['session_date'] ?? ''), (string)($b['session_date'] ?? '')));
        echo '<div id="fechModal_' . $pid . '" class="fechModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)fechDetailClose(' . $pid . ')">';
        echo '<div style="background:hsl(var(--card));border-radius:12px;padding:0;max-width:860px;width:100%;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)">';
        // Cabeçalho
        echo '<div style="padding:20px 24px;border-bottom:1px solid hsl(var(--border));display:flex;justify-content:space-between;align-items:flex-start;gap:12px">';
        echo '<div>';
        echo '<div style="font-size:18px;font-weight:900">' . h($p['patient_name']) . '</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:4px">Operadora: <strong>' . h($insurerLabel) . '</strong> · Competência ' . h($monthLabel) . ' · ' . (int)$p['total_sessions'] . ' atendimento(s)</div>';
        echo '</div>';
        echo '<button type="button" onclick="fechDetailClose(' . $pid . ')" style="background:none;border:none;font-size:26px;cursor:pointer;color:hsl(var(--muted-foreground));line-height:1">×</button>';
        echo '</div>';
        // Cards de resumo do paciente
        echo '<div style="padding:16px 24px;display:flex;gap:12px;flex-wrap:wrap;border-bottom:1px solid hsl(var(--border))">';
        echo '<div style="flex:1;min-width:120px;padding:12px;background:hsla(var(--primary)/.08);border-radius:8px"><div style="font-size:11px;color:hsl(var(--muted-foreground))">Atendimentos</div><div style="font-size:22px;font-weight:800">' . (int)$p['total_sessions'] . '</div></div>';
        if ($showValues) {
            echo '<div style="flex:1;min-width:120px;padding:12px;background:hsla(var(--success)/.10);border-radius:8px"><div style="font-size:11px;color:hsl(var(--muted-foreground))">A receber</div><div style="font-size:20px;font-weight:800;color:#059669">' . h($brl((float)$p['total_receivable'])) . '</div></div>';
            echo '<div style="flex:1;min-width:120px;padding:12px;background:hsla(var(--warning)/.12);border-radius:8px"><div style="font-size:11px;color:hsl(var(--muted-foreground))">A pagar</div><div style="font-size:20px;font-weight:800;color:#b45309">' . h($brl((float)$p['total_payable'])) . '</div></div>';
        }
        echo '</div>';
        // Tabela de sessões
        echo '<div style="padding:16px 24px;overflow:auto">';
        echo '<table style="width:100%"><thead><tr>';
        echo '<th>#</th><th>Data</th><th>Profissional</th><th>Especialidade</th><th>Origem</th>';
        if ($showValues) {
            echo '<th style="text-align:right">A receber</th><th style="text-align:right">A pagar</th>';
        }
        echo '</tr></thead><tbody>';
        $n = 0;
        foreach ($items as $si) {
            $n++;
            $dt = ($si['session_date'] ?? '') !== '' ? date('d/m/Y', strtotime((string)$si['session_date'])) : 's/ data';
            $origem = !empty($si['is_manual'])
                ? '<span style="background:#fef3c7;color:#92400e;padding:1px 8px;border-radius:8px;font-size:11px;font-weight:700">Manual</span>'
                : '<span style="background:#dbeafe;color:#1e40af;padding:1px 8px;border-radius:8px;font-size:11px;font-weight:700">Automático</span>';
            if (!empty($si['operator_name'])) {
                $origem .= '<div style="font-size:11px;color:hsl(var(--muted-foreground));margin-top:2px">por ' . h((string)$si['operator_name']) . '</div>';
            }
            echo '<tr>';
            echo '<td>' . (!empty($si['session_number']) ? (int)$si['session_number'] : $n) . '</td>';
            echo '<td style="white-space:nowrap">' . h($dt) . '</td>';
            echo '<td>' . h((string)$si['professional_name']) . '</td>';
            echo '<td>' . h((string)$si['specialty']) . '</td>';
            echo '<td>' . $origem . '</td>';
            if ($showValues) {
                echo '<td style="text-align:right;color:#059669">' . h($brl((float)$si['receivable'])) . '</td>';
                echo '<td style="text-align:right;color:#b45309">' . h($brl((float)$si['payable'])) . '</td>';
            }
            echo '</tr>';
        }
        if ($showValues) {
            echo '<tr style="background:hsla(var(--muted)/.3);font-weight:800">';
            echo '<td colspan="5" style="text-align:right">Total</td>';
            echo '<td style="text-align:right;color:#059669">' . h($brl((float)$p['total_receivable'])) . '</td>';
            echo '<td style="text-align:right;color:#b45309">' . h($brl((float)$p['total_payable'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        // Rodapé
        echo '<div style="padding:14px 24px;border-top:1px solid hsl(var(--border));text-align:right">';
        echo '<button type="button" class="btn" onclick="fechDetailClose(' . $pid . ')">Fechar</button>';
        echo '</div>';
        echo '</div></div>';
        $detailModals[] = ob_get_clean();
    }
    echo '</tbody></table></div>';

    // Renderiza os pop-ups fora da tabela.
    foreach ($detailModals as $modalHtml) {
        echo $modalHtml;
    }
    echo '<script>';
    echo 'function fechDetail(id){var m=document.getElementById("fechModal_"+id);if(m)m.style.display="flex";}';
    echo 'function fechDetailClose(id){var m=document.getElementById("fechModal_"+id);if(m)m.style.display="none";}';
    echo 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){document.querySelectorAll(".fechModal").forEach(function(m){m.style.display="none";});}});';
    echo '</script>';

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
