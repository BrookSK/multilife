<?php

declare(strict_types=1);

/**
 * Fechamento Mensal — CONFERÊNCIA (sem valores).
 *
 * Esta tela é de ACOMPANHAMENTO/CONFERÊNCIA da equipe de admissão. Mostra as
 * quantidades de atendimentos agrupadas por CLIENTE -> OPERADORA, com o dia de
 * fechamento de cada um, para conferir e "bater" antes de entregar ao financeiro.
 *
 * NÃO exibe valores monetários (isso é responsabilidade da tela do Financeiro,
 * financeiro_fechamento.php). Aqui o fluxo é:
 *   1) Conferir as quantidades por operadora.
 *   2) Fechar cada operadora (conferência concluída).
 *   3) Consolidar o cliente quando todas as operadoras dele fecharem.
 *   4) O cliente consolidado fica disponível para o Financeiro dar o OK final.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.closure.manage');

$db = db();
clients_ensure_schema();

// Estruturas idempotentes do fechamento (caso a migration não tenha rodado).
try {
    $db->exec("CREATE TABLE IF NOT EXISTS billing_monthly_closures (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        reference_month VARCHAR(7) NOT NULL,
        scope ENUM('global','operator','client') NOT NULL DEFAULT 'global',
        client_id INT UNSIGNED NULL,
        health_insurer_id INT UNSIGNED NULL,
        status ENUM('closed','reversed','operator_closed','client_closed','sent_to_finance') NOT NULL DEFAULT 'closed',
        total_sessions INT UNSIGNED NOT NULL DEFAULT 0,
        total_receivable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_payable DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        receivable_entries INT UNSIGNED NOT NULL DEFAULT 0,
        payable_entries INT UNSIGNED NOT NULL DEFAULT 0,
        closed_by_user_id INT UNSIGNED NULL,
        closed_at DATETIME NULL,
        reversed_by_user_id INT UNSIGNED NULL,
        reversed_at DATETIME NULL,
        sent_to_finance_at DATETIME NULL,
        sent_to_finance_by_user_id INT UNSIGNED NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_bmc_scope (reference_month, scope, client_id, health_insurer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}
foreach ([
    "ALTER TABLE billing_document_requirements ADD COLUMN monthly_closure_id BIGINT UNSIGNED NULL",
    "ALTER TABLE billing_document_requirements ADD COLUMN created_by_user_id INT UNSIGNED NULL",
    "ALTER TABLE billing_document_requirements ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0",
] as $alter) {
    try { $db->exec($alter); } catch (Throwable $e) {}
}

// Competência (default: mês atual) + filtros.
$month = billing_closure_normalize_month($_GET['month'] ?? null) ?? date('Y-m');
$filters = [
    'patient_q' => trim((string)($_GET['patient_q'] ?? '')),
    'client_id' => (int)($_GET['client_id'] ?? 0),
    'insurer_id' => (int)($_GET['insurer_id'] ?? 0),
    'professional_id' => (int)($_GET['professional_id'] ?? 0),
];

// Sessões faturáveis (não faturadas) + agrupamento por cliente -> operadora.
$sessions = billing_closure_fetch_sessions($db, $month, false, $filters);
$byClient = billing_closure_group_by_client($sessions);

// Status dos fechamentos por escopo (o que já foi fechado).
$scoped = billing_closure_scoped_status($db, $month);

// Totais gerais (quantidades apenas).
$totalClients = count($byClient);
$totalSessions = 0;
$totalPatients = 0;
foreach ($byClient as $c) {
    $totalSessions += (int)$c['total_sessions'];
    foreach ($c['operators'] as $o) {
        $totalPatients += (int)$o['patients_count'];
    }
}

// Dados para filtros.
$filterClients = clients_list(false);
$filterOperators = operators_list(null, false);
try {
    $filterProfessionals = $db->query("
        SELECT DISTINCT u.id, u.name FROM users u
        INNER JOIN patient_assignments pa ON pa.professional_user_id = u.id
        WHERE u.name IS NOT NULL AND u.name != '' ORDER BY u.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $filterProfessionals = []; }

// Dia de fechamento por operadora e por cliente (para exibir o prazo).
$opClosingDay = [];
$clientClosingDay = [];
foreach ($filterOperators as $op) {
    $opClosingDay[(int)$op['id']] = $op['closing_day'] !== null ? (int)$op['closing_day'] : null;
}
foreach ($filterClients as $cl) {
    $clientClosingDay[(int)$cl['id']] = $cl['closing_day'] !== null ? (int)$cl['closing_day'] : null;
}

$monthLabel = date('m/Y', strtotime($month . '-01'));

view_header('Fechamento Mensal — Conferência');

echo '<div class="grid">';

// Cabeçalho + filtros
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Fechamento Mensal — Conferência</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Confira as quantidades de atendimentos por <strong>Cliente → Operadora</strong>. Sem valores — esta etapa é de conferência. O financeiro trata os valores depois.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
if (rbac_user_can((int)auth_user_id(), 'finance.manage')) {
    echo '<a class="btn" href="/financeiro_fechamento.php?month=' . h($month) . '">Ir para o Financeiro →</a>';
}
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/faturamento_fechamento.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Competência</label>';
echo '<input type="month" name="month" value="' . h($month) . '" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></div>';
echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Cliente</label>';
echo '<select name="client_id" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"><option value="0">Todos</option>';
foreach ($filterClients as $cl) {
    $sel = ((int)$filters['client_id'] === (int)$cl['id']) ? ' selected' : '';
    echo '<option value="' . (int)$cl['id'] . '"' . $sel . '>' . h((string)$cl['name']) . '</option>';
}
echo '</select></div>';
echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Operadora</label>';
echo '<select name="insurer_id" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"><option value="0">Todas</option>';
foreach ($filterOperators as $op) {
    $sel = ((int)$filters['insurer_id'] === (int)$op['id']) ? ' selected' : '';
    $opLabel = (string)$op['name'] . ($op['client_name'] ? ' — ' . (string)$op['client_name'] : '');
    echo '<option value="' . (int)$op['id'] . '"' . $sel . '>' . h($opLabel) . '</option>';
}
echo '</select></div>';
echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Profissional</label>';
echo '<select name="professional_id" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"><option value="0">Todos</option>';
foreach ($filterProfessionals as $prof) {
    $sel = ((int)$filters['professional_id'] === (int)$prof['id']) ? ' selected' : '';
    echo '<option value="' . (int)$prof['id'] . '"' . $sel . '>' . h((string)$prof['name']) . '</option>';
}
echo '</select></div>';
echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Paciente</label>';
echo '<input type="text" name="patient_q" value="' . h((string)$filters['patient_q']) . '" placeholder="Nome" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></div>';
echo '<button class="btn btnPrimary" type="submit">Ver</button>';
echo '</form>';
echo '</section>';

// Cards de resumo (só quantidades)
echo '<section class="card col12" style="margin-top:16px">';
echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--primary)/.08);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Clientes</div><div style="font-size:26px;font-weight:800">' . (int)$totalClients . '</div></div>';
echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--primary)/.08);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Atendimentos</div><div style="font-size:26px;font-weight:800">' . (int)$totalSessions . '</div></div>';
echo '<div style="flex:1;min-width:150px;padding:16px;background:hsla(var(--primary)/.08);border-radius:10px"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Pacientes (linhas)</div><div style="font-size:26px;font-weight:800">' . (int)$totalPatients . '</div></div>';
echo '</div>';
echo '</section>';

// Corpo: um card por cliente, com suas operadoras.
if (empty($byClient)) {
    echo '<section class="card col12" style="margin-top:16px"><div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum atendimento pendente de conferência em ' . h($monthLabel) . '.</div></section>';
} else {
    foreach ($byClient as $c) {
        $clientId = (int)$c['client_id'];
        $clientClosed = isset($scoped['clients'][$clientId]);
        $ccDay = $clientClosingDay[$clientId] ?? null;

        echo '<section class="card col12" style="margin-top:16px">';
        // Cabeçalho do cliente
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-bottom:2px solid hsl(var(--border));padding-bottom:10px;margin-bottom:12px">';
        echo '<div>';
        echo '<span style="font-size:18px;font-weight:900">🏢 ' . h((string)$c['client_name']) . '</span>';
        if ($ccDay) {
            echo '<span style="margin-left:10px;font-size:12px;color:hsl(var(--muted-foreground))">Entrega dia ' . (int)$ccDay . '</span>';
        }
        echo '<span style="margin-left:10px;font-size:13px;color:hsl(var(--muted-foreground))">' . (int)$c['total_sessions'] . ' atend.</span>';
        if ($clientClosed) {
            echo '<span style="margin-left:10px;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;background:#d1fae5;color:#065f46">✅ Cliente consolidado</span>';
        }
        echo '</div>';
        // Ações do cliente (consolidar / reabrir)
        if ($clientId > 0) {
            echo '<div>';
            if ($clientClosed) {
                echo '<form method="post" action="/faturamento_fechamento_scope_post.php" style="display:inline" onsubmit="return confirm(\'Reabrir o cliente ' . h((string)$c['client_name']) . '?\')">';
                echo '<input type="hidden" name="month" value="' . h($month) . '"><input type="hidden" name="scope" value="client"><input type="hidden" name="id" value="' . $clientId . '"><input type="hidden" name="action" value="reopen">';
                echo '<button class="btn" type="submit">↩️ Reabrir cliente</button>';
                echo '</form>';
            } else {
                echo '<form method="post" action="/faturamento_fechamento_scope_post.php" style="display:inline" onsubmit="return confirm(\'Consolidar o cliente ' . h((string)$c['client_name']) . '? Só é permitido com todas as operadoras fechadas.\')">';
                echo '<input type="hidden" name="month" value="' . h($month) . '"><input type="hidden" name="scope" value="client"><input type="hidden" name="id" value="' . $clientId . '"><input type="hidden" name="action" value="close">';
                echo '<button class="btn btnPrimary" type="submit">🔒 Consolidar cliente</button>';
                echo '</form>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Operadoras do cliente
        foreach ($c['operators'] as $o) {
            $opId = (int)$o['insurer_id'];
            $opClosed = isset($scoped['operators'][$opId]);
            $ocDay = $opClosingDay[$opId] ?? null;

            echo '<div style="border:1px solid hsl(var(--border));border-radius:10px;padding:12px;margin-bottom:12px">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">';
            echo '<div>';
            echo '<span style="font-weight:700">' . h((string)$o['insurer_name']) . '</span>';
            if ($ocDay) {
                echo '<span style="margin-left:8px;font-size:12px;color:hsl(var(--muted-foreground))">Entrega dia ' . (int)$ocDay . '</span>';
            }
            echo '<span style="margin-left:8px;font-size:12px;color:hsl(var(--muted-foreground))">' . (int)$o['total_sessions'] . ' atend. · ' . (int)$o['patients_count'] . ' paciente(s)</span>';
            if ($opClosed) {
                echo '<span style="margin-left:8px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e40af">✔ Operadora fechada</span>';
            } else {
                echo '<span style="margin-left:8px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e">Em conferência</span>';
            }
            echo '</div>';
            // Ações da operadora (fechar / reabrir) — bloqueadas se o cliente já consolidou.
            if ($opId > 0 && !$clientClosed) {
                echo '<div>';
                if ($opClosed) {
                    echo '<form method="post" action="/faturamento_fechamento_scope_post.php" style="display:inline">';
                    echo '<input type="hidden" name="month" value="' . h($month) . '"><input type="hidden" name="scope" value="operator"><input type="hidden" name="id" value="' . $opId . '"><input type="hidden" name="action" value="reopen">';
                    echo '<button class="btn" type="submit" style="font-size:12px;padding:6px 12px">↩️ Reabrir</button>';
                    echo '</form>';
                } else {
                    echo '<form method="post" action="/faturamento_fechamento_scope_post.php" style="display:inline">';
                    echo '<input type="hidden" name="month" value="' . h($month) . '"><input type="hidden" name="scope" value="operator"><input type="hidden" name="id" value="' . $opId . '"><input type="hidden" name="action" value="close">';
                    echo '<button class="btn btnPrimary" type="submit" style="font-size:12px;padding:6px 12px">✔ Fechar operadora</button>';
                    echo '</form>';
                }
                echo '</div>';
            }
            echo '</div>';

            // Tabela de linhas (paciente / profissional / especialidade / qtd) — SEM valores.
            echo '<div style="overflow:auto"><table>';
            echo '<thead><tr><th>Paciente</th><th>Profissional</th><th>Especialidade</th><th style="text-align:center">Atend.</th><th style="text-align:center">Detalhes</th></tr></thead><tbody>';
            foreach ($o['lines'] as $line) {
                // Monta o payload de detalhes (datas das sessões) para o modal.
                $details = $line['sessions_detail'] ?? [];
                usort($details, static fn($a, $b) => strcmp((string)($a['session_date'] ?? ''), (string)($b['session_date'] ?? '')));
                $detailPayload = [];
                foreach ($details as $d) {
                    $detailPayload[] = [
                        'date' => $d['session_date'] !== '' ? date('d/m/Y', strtotime((string)$d['session_date'])) : 's/ data',
                        'number' => (int)$d['session_number'],
                        'manual' => (int)($d['is_manual'] ?? 0),
                        'operator' => (string)($d['operator_name'] ?? ''),
                    ];
                }
                $modalTitle = (string)$line['patient_name'] . ' — ' . (string)$line['professional_name'];
                $dataDetail = h(json_encode([
                    'title' => $modalTitle,
                    'subtitle' => (string)$o['insurer_name'] . ' · ' . (string)$line['specialty'],
                    'count' => (int)$line['sessions'],
                    'items' => $detailPayload,
                ], JSON_UNESCAPED_UNICODE));

                echo '<tr>';
                echo '<td style="font-weight:600">' . h((string)$line['patient_name']) . '</td>';
                echo '<td>' . h((string)$line['professional_name']) . '</td>';
                echo '<td>' . h((string)$line['specialty']) . '</td>';
                echo '<td style="text-align:center;font-weight:700">' . (int)$line['sessions'] . '</td>';
                echo '<td style="text-align:center">';
                echo '<button type="button" class="btn-detail" data-detail="' . $dataDetail . '" onclick="openDetail(this)" title="Ver os atendimentos">'
                    . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>'
                    . ' Detalhes</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>'; // fim operadora
        }

        echo '</section>'; // fim cliente
    }
}

echo '</div>';

// Estilos do botão de detalhes + modal.
echo '<style>
.btn-detail{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;font-size:12px;font-weight:600;color:hsl(var(--primary));background:hsla(var(--primary)/.08);border:1px solid hsla(var(--primary)/.3);border-radius:999px;cursor:pointer;transition:all .15s}
.btn-detail:hover{background:hsl(var(--primary));color:#fff;border-color:hsl(var(--primary))}
.det-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.det-overlay.open{display:flex}
.det-modal{background:#fff;border-radius:16px;max-width:520px;width:100%;max-height:85vh;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:detPop .18s ease-out}
@keyframes detPop{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.det-head{padding:18px 22px;background:linear-gradient(135deg,hsl(var(--primary)),hsl(var(--primary-dark,var(--primary))));color:#fff}
.det-head h3{margin:0;font-size:17px;font-weight:800}
.det-head .sub{margin-top:3px;font-size:13px;opacity:.9}
.det-body{padding:16px 22px;overflow-y:auto;max-height:60vh}
.det-count{font-size:13px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:12px}
.det-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:10px;margin-bottom:8px}
.det-item .cal{font-size:18px}
.det-item .dt{font-weight:700}
.det-item .ses{font-size:12px;color:hsl(var(--muted-foreground))}
.det-tag{margin-left:auto;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.det-foot{padding:14px 22px;border-top:1px solid hsl(var(--border));text-align:right}
.det-close{padding:8px 18px;background:hsl(var(--primary));color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer}
</style>';

// Modal único (preenchido dinamicamente pelo botão).
echo '<div class="det-overlay" id="detOverlay" onclick="if(event.target===this)closeDetail()">'
    . '<div class="det-modal">'
    . '<div class="det-head"><h3 id="detTitle">Detalhes</h3><div class="sub" id="detSub"></div></div>'
    . '<div class="det-body"><div class="det-count" id="detCount"></div><div id="detItems"></div></div>'
    . '<div class="det-foot"><button type="button" class="det-close" onclick="closeDetail()">Fechar</button></div>'
    . '</div></div>';

echo '<script>
function openDetail(btn){
    var data = {};
    try { data = JSON.parse(btn.getAttribute("data-detail")); } catch(e){ return; }
    document.getElementById("detTitle").textContent = data.title || "Detalhes";
    document.getElementById("detSub").textContent = data.subtitle || "";
    document.getElementById("detCount").textContent = "Atendimentos que compõem a quantidade (" + (data.count||0) + ")";
    var wrap = document.getElementById("detItems");
    wrap.innerHTML = "";
    if(!data.items || data.items.length === 0){
        wrap.innerHTML = "<div style=\\"color:#64748b;font-size:13px\\">Sem detalhes disponíveis.</div>";
    } else {
        data.items.forEach(function(it){
            var div = document.createElement("div");
            div.className = "det-item";
            var manual = it.manual ? "<span class=\\"det-tag\\">manual</span>" : "";
            var op = it.operator ? " · " + it.operator : "";
            div.innerHTML = "<span class=\\"cal\\">📅</span><span><span class=\\"dt\\">" + it.date + "</span> <span class=\\"ses\\">(Sessão " + it.number + ")" + op + "</span></span>" + manual;
            wrap.appendChild(div);
        });
    }
    document.getElementById("detOverlay").classList.add("open");
}
function closeDetail(){ document.getElementById("detOverlay").classList.remove("open"); }
document.addEventListener("keydown", function(e){ if(e.key === "Escape") closeDetail(); });
</script>';

view_footer();
