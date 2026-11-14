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
 *   1) Conferir as quantidades por operadora (e ajustar o atendimento se preciso:
 *      PAD, datas, polo, operadora, observação interna).
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
$monthParam = trim((string)($_GET['month'] ?? ''));
$month = billing_closure_normalize_month($monthParam) ?? date('Y-m');
$monthInvalid = $monthParam !== '' && billing_closure_normalize_month($monthParam) === null;
$filters = billing_closure_filters_from($_GET);
$filtersActive = billing_closure_filters_active($filters);
$selfQs = billing_closure_filters_qs($month, $filters);

// Sessões faturáveis (não faturadas) + agrupamento por cliente -> operadora.
$sessions = billing_closure_fetch_sessions($db, $month, false, $filters);
$byClient = billing_closure_group_by_client($sessions);

// Status dos fechamentos por escopo (o que já foi fechado).
$scoped = billing_closure_scoped_status($db, $month);

// Totais gerais (quantidades apenas). Pacientes = distintos no resultado.
$totalClients = count($byClient);
$totalSessions = count($sessions);
$distinctPatients = [];
$totalLines = 0;
foreach ($byClient as $c) {
    foreach ($c['operators'] as $o) {
        foreach ($o['lines'] as $line) {
            $distinctPatients[(int)$line['patient_id']] = true;
            $totalLines++;
        }
    }
}
$totalPatients = count($distinctPatients);
// Sessões PENDENTES de hoje em diante por atendimento (as que um desmame remarcaria).
$pendingFuture = [];
$assignmentIds = array_values(array_unique(array_map(static fn($s) => (int)$s['assignment_id'], $sessions)));
if ($assignmentIds) {
    try {
        $in = implode(',', array_fill(0, count($assignmentIds), '?'));
        $pf = $db->prepare("SELECT assignment_id, COUNT(*) c FROM billing_document_requirements
            WHERE status = 'pending' AND (session_date IS NULL OR session_date >= CURDATE()) AND assignment_id IN ($in)
            GROUP BY assignment_id");
        $pf->execute($assignmentIds);
        foreach ($pf->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pendingFuture[(int)$r['assignment_id']] = (int)$r['c'];
        }
    } catch (Throwable $e) {
        error_log('[FECHAMENTO] pendentes futuras: ' . $e->getMessage());
    }
}

// Polos distintos (cliente + polo; "Sem polo" conta uma vez por cliente).
$totalPolos = 0;
foreach ($byClient as $c) {
    $totalPolos += count($c['polos'] ?? []);
}

// Dados para filtros e para o modal de edição.
$filterClients = clients_list(false);
$filterPolos = polos_list(null, false);
$filterOperators = operators_list(null, false);
try {
    $filterProfessionals = $db->query("
        SELECT DISTINCT u.id, u.name FROM users u
        INNER JOIN patient_assignments pa ON pa.professional_user_id = u.id
        WHERE u.name IS NOT NULL AND u.name != '' ORDER BY u.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $filterProfessionals = []; }

// Especialidades: cadastro + as que já existem nos atendimentos (texto livre legado).
$specialtyOptions = [];
try {
    foreach ($db->query("SELECT name FROM specialties WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN) as $sp) {
        $sp = trim((string)$sp);
        if ($sp !== '') { $specialtyOptions[mb_strtolower($sp)] = $sp; }
    }
} catch (Throwable $e) {}
try {
    foreach ($db->query("SELECT DISTINCT TRIM(specialty) FROM patient_assignments WHERE specialty IS NOT NULL AND TRIM(specialty) <> ''")->fetchAll(PDO::FETCH_COLUMN) as $sp) {
        $sp = trim((string)$sp);
        if ($sp !== '' && !isset($specialtyOptions[mb_strtolower($sp)])) { $specialtyOptions[mb_strtolower($sp)] = $sp; }
    }
} catch (Throwable $e) {}
natcasesort($specialtyOptions);
$frequencyOptions = frequency_get_options();

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
$fmtDate = static function (?string $v): string {
    $v = trim((string)$v);
    if ($v === '' || strpos($v, '0000-00-00') === 0) { return ''; }
    $ts = strtotime($v);
    return $ts ? date('d/m/Y', $ts) : '';
};
$isoDate = static function (?string $v): string {
    $v = trim((string)$v);
    if ($v === '' || strpos($v, '0000-00-00') === 0) { return ''; }
    return substr($v, 0, 10);
};
// Hidden inputs com os filtros atuais (para os forms voltarem à mesma visão).
$filterHidden = static function () use ($filters): string {
    $out = '';
    foreach ($filters as $k => $v) {
        if ($v === '' || $v === 0 || $v === null) { continue; }
        $out .= '<input type="hidden" name="f[' . h((string)$k) . ']" value="' . h((string)$v) . '">';
    }
    return $out;
};
$jsConfirm = static fn(string $msg): string => 'return confirm(' . h(json_encode($msg, JSON_UNESCAPED_UNICODE)) . ')';
$selStyle = 'padding:8px;border:1px solid hsl(var(--border));border-radius:6px;max-width:220px';
$lblStyle = 'display:block;font-size:12px;font-weight:600;margin-bottom:4px';

view_header('Fechamento Mensal — Conferência');

echo '<div class="grid">';

// Cabeçalho + filtros
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Fechamento Mensal — Conferência</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Confira as quantidades de atendimentos por <strong>Cliente → Polo → Operadora</strong>. Sem valores — esta etapa é de conferência. O financeiro trata os valores depois.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
if (rbac_user_can((int)auth_user_id(), 'finance.manage')) {
    echo '<a class="btn" href="/financeiro_fechamento.php?' . h($selfQs) . '">Ir para o Financeiro →</a>';
}
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

if ($monthInvalid) {
    echo '<div style="margin-top:12px;padding:10px 14px;border-radius:8px;background:#fef3c7;color:#92400e;font-size:13px">Competência "' . h($monthParam) . '" inválida — mostrando ' . h($monthLabel) . '.</div>';
}

echo '<form method="get" action="/faturamento_fechamento.php" id="fltForm" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
echo '<div><label style="' . $lblStyle . '">Competência</label>';
echo '<input type="month" name="month" value="' . h($month) . '" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></div>';

// Cliente
echo '<div><label style="' . $lblStyle . '">Cliente</label>';
echo '<select name="client_id" id="fltClient" style="' . $selStyle . '"><option value="0">Todos</option>';
echo '<option value="-1"' . ($filters['client_id'] === -1 ? ' selected' : '') . '>— Sem cliente —</option>';
foreach ($filterClients as $cl) {
    $sel = ($filters['client_id'] === (int)$cl['id']) ? ' selected' : '';
    echo '<option value="' . (int)$cl['id'] . '"' . $sel . '>' . h((string)$cl['name']) . '</option>';
}
echo '</select></div>';

// Polo (filtrado pelo cliente)
echo '<div><label style="' . $lblStyle . '">Polo</label>';
echo '<select name="polo_id" id="fltPolo" style="' . $selStyle . '"><option value="0">Todos</option>';
echo '<option value="-1"' . ($filters['polo_id'] === -1 ? ' selected' : '') . '>— Sem polo —</option>';
foreach ($filterPolos as $po) {
    $sel = ($filters['polo_id'] === (int)$po['id']) ? ' selected' : '';
    echo '<option value="' . (int)$po['id'] . '" data-client="' . (int)($po['client_id'] ?? 0) . '"' . $sel . '>' . h(polos_label($po)) . '</option>';
}
echo '</select></div>';

// Operadora (filtrada pelo cliente)
echo '<div><label style="' . $lblStyle . '">Operadora</label>';
echo '<select name="insurer_id" id="fltInsurer" style="' . $selStyle . '"><option value="0">Todas</option>';
echo '<option value="-1"' . ($filters['insurer_id'] === -1 ? ' selected' : '') . '>— Sem operadora —</option>';
foreach ($filterOperators as $op) {
    $sel = ($filters['insurer_id'] === (int)$op['id']) ? ' selected' : '';
    $opLabel = (string)$op['name'] . ($op['client_name'] ? ' — ' . (string)$op['client_name'] : '');
    echo '<option value="' . (int)$op['id'] . '" data-client="' . (int)($op['client_id'] ?? 0) . '"' . $sel . '>' . h($opLabel) . '</option>';
}
echo '</select></div>';

// Especialidade
echo '<div><label style="' . $lblStyle . '">Especialidade</label>';
echo '<select name="specialty" style="' . $selStyle . '"><option value="">Todas</option>';
$specFound = false;
foreach ($specialtyOptions as $sp) {
    $sel = (mb_strtolower($filters['specialty']) === mb_strtolower($sp)) ? ' selected' : '';
    if ($sel) { $specFound = true; }
    echo '<option value="' . h($sp) . '"' . $sel . '>' . h($sp) . '</option>';
}
if ($filters['specialty'] !== '' && !$specFound) {
    echo '<option value="' . h($filters['specialty']) . '" selected>' . h($filters['specialty']) . '</option>';
}
echo '</select></div>';

// PAD (frequência)
echo '<div><label style="' . $lblStyle . '">PAD</label>';
echo '<select name="frequency" style="' . $selStyle . '"><option value="">Todos</option>';
foreach ($frequencyOptions as $fo) {
    $sel = ($filters['frequency'] === $fo['code']) ? ' selected' : '';
    echo '<option value="' . h($fo['code']) . '"' . $sel . '>' . h($fo['label']) . '</option>';
}
echo '</select></div>';

// Profissional
echo '<div><label style="' . $lblStyle . '">Profissional</label>';
echo '<select name="professional_id" style="' . $selStyle . '"><option value="0">Todos</option>';
foreach ($filterProfessionals as $prof) {
    $sel = ($filters['professional_id'] === (int)$prof['id']) ? ' selected' : '';
    echo '<option value="' . (int)$prof['id'] . '"' . $sel . '>' . h((string)$prof['name']) . '</option>';
}
echo '</select></div>';

echo '<div><label style="' . $lblStyle . '">Paciente</label>';
echo '<input type="text" name="patient_q" value="' . h((string)$filters['patient_q']) . '" placeholder="Nome" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></div>';
echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
if ($filtersActive) {
    echo '<a class="btn" href="/faturamento_fechamento.php?month=' . h($month) . '">Limpar filtros</a>';
}
echo '</form>';
if ($filtersActive) {
    echo '<div style="margin-top:10px;font-size:12px;color:#92400e">Filtros ativos: os totais abaixo mostram só o que foi filtrado. Fechar uma operadora ou consolidar um cliente considera <strong>todos</strong> os atendimentos dela/dele no mês, não só os filtrados.</div>';
}
echo '</section>';

// Cards de resumo (só quantidades)
echo '<section class="card col12" style="margin-top:16px">';
echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
$cardStyle = 'flex:1;min-width:150px;padding:16px;background:hsla(var(--primary)/.08);border-radius:10px';
echo '<div style="' . $cardStyle . '"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Clientes</div><div style="font-size:26px;font-weight:800">' . (int)$totalClients . '</div></div>';
echo '<div style="' . $cardStyle . '"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Polos</div><div style="font-size:26px;font-weight:800">' . (int)$totalPolos . '</div></div>';
echo '<div style="' . $cardStyle . '"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Sessões</div><div style="font-size:26px;font-weight:800">' . (int)$totalSessions . '</div></div>';
echo '<div style="' . $cardStyle . '"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Pacientes</div><div style="font-size:26px;font-weight:800">' . (int)$totalPatients . '</div></div>';
echo '<div style="' . $cardStyle . '"><div style="font-size:12px;color:hsl(var(--muted-foreground))">Atendimentos (linhas)</div><div style="font-size:26px;font-weight:800">' . (int)$totalLines . '</div></div>';
echo '</div>';
echo '</section>';

// Corpo: um card por cliente, com suas operadoras.
if (empty($byClient)) {
    $emptyMsg = $filtersActive
        ? 'Nenhum atendimento encontrado com esses filtros em ' . h($monthLabel) . '.'
        : 'Nenhum atendimento pendente de conferência em ' . h($monthLabel) . '.';
    echo '<section class="card col12" style="margin-top:16px"><div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">' . $emptyMsg . '</div></section>';
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
        echo '<span style="margin-left:10px;font-size:13px;color:hsl(var(--muted-foreground))">' . (int)$c['total_sessions'] . ' sessões</span>';
        if ($clientClosed) {
            echo '<span style="margin-left:10px;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;background:#d1fae5;color:#065f46">✅ Cliente consolidado</span>';
        }
        echo '</div>';
        // Ações do cliente (consolidar / reabrir)
        if ($clientId > 0) {
            echo '<div>';
            if ($clientClosed) {
                echo '<form method="post" action="/faturamento_fechamento_scope_post.php" style="display:inline" onsubmit="' . $jsConfirm('Reabrir o cliente ' . (string)$c['client_name'] . '?') . '">';
                echo '<input type="hidden" name="month" value="' . h($month) . '"><input type="hidden" name="scope" value="client"><input type="hidden" name="id" value="' . $clientId . '"><input type="hidden" name="action" value="reopen">' . $filterHidden();
                echo '<button class="btn" type="submit">↩️ Reabrir cliente</button>';
                echo '</form>';
            } else {
                echo '<form method="post" action="/faturamento_fechamento_scope_post.php" style="display:inline" onsubmit="' . $jsConfirm('Consolidar o cliente ' . (string)$c['client_name'] . '? Só é permitido com todas as operadoras fechadas.') . '">';
                echo '<input type="hidden" name="month" value="' . h($month) . '"><input type="hidden" name="scope" value="client"><input type="hidden" name="id" value="' . $clientId . '"><input type="hidden" name="action" value="close">' . $filterHidden();
                echo '<button class="btn btnPrimary" type="submit">🔒 Consolidar cliente</button>';
                echo '</form>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Polos do cliente (cliente -> polo -> operadora)
        foreach ($c['polos'] as $pl) {
          $plId = (int)$pl['polo_id'];
          $plOnly = billing_closure_filters_qs($month, array_merge($filters, ['polo_id' => $plId > 0 ? $plId : -1]));
          echo '<div class="ff-polo">';
          echo '<div class="ff-polo-head">';
          echo '<span style="font-size:15px;font-weight:800">📍 ' . h((string)$pl['polo_name']) . '</span>';
          echo '<span style="font-size:12px;color:hsl(var(--muted-foreground))">' . (int)$pl['total_sessions'] . ' sessões · ' . (int)$pl['patients_count'] . ' paciente(s) · ' . count($pl['operators']) . ' operadora(s)</span>';
          if ($plId === 0) {
              echo '<span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e" title="Defina o polo no atendimento (botão Editar) ou o polo padrão da operadora">Defina o polo nos atendimentos</span>';
          }
          if (count($c['polos']) > 1 && $filters['polo_id'] === 0) {
              echo '<a href="/faturamento_fechamento.php?' . h($plOnly) . '" style="margin-left:auto;font-size:12px">Ver só este polo</a>';
          }
          echo '</div>';

        // Operadoras do polo
        foreach ($pl['operators'] as $o) {
            $opId = (int)$o['insurer_id'];
            $opClosed = isset($scoped['operators'][$opId]);
            $ocDay = $opClosingDay[$opId] ?? null;
            $locked = $clientClosed || $opClosed;
            $otherPolos = max(0, (int)($o['polos_count'] ?? 1) - 1);

            echo '<div style="border:1px solid hsl(var(--border));border-radius:10px;padding:12px;margin-bottom:12px;background:#fff">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">';
            echo '<div>';
            echo '<span style="font-weight:700">' . h((string)$o['insurer_name']) . '</span>';
            if ($ocDay) {
                echo '<span style="margin-left:8px;font-size:12px;color:hsl(var(--muted-foreground))">Entrega dia ' . (int)$ocDay . '</span>';
            }
            echo '<span style="margin-left:8px;font-size:12px;color:hsl(var(--muted-foreground))">' . (int)$o['total_sessions'] . ' sessões · ' . (int)$o['patients_count'] . ' paciente(s)</span>';
            if ($opId <= 0) {
                echo '<span style="margin-left:8px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b" title="Defina a operadora em cada atendimento (botão Editar) para poder fechar">⚠ Sem operadora — edite os atendimentos</span>';
            } elseif ($opClosed) {
                echo '<span style="margin-left:8px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e40af">✔ Operadora fechada</span>';
            } else {
                echo '<span style="margin-left:8px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e">Em conferência</span>';
            }
            if ($opId > 0 && $otherPolos > 0) {
                echo '<div style="margin-top:4px;font-size:11px;color:hsl(var(--muted-foreground))">Esta operadora também tem atendimentos em ' . $otherPolos . ' outro(s) polo(s) deste cliente. Fechar/reabrir vale para todos os polos.</div>';
            }
            echo '</div>';
            // Ações da operadora (fechar / reabrir) — bloqueadas se o cliente já consolidou.
            if ($opId > 0 && !$clientClosed) {
                echo '<div>';
                $opConfirm = $otherPolos > 0
                    ? ' onsubmit="' . $jsConfirm(($opClosed ? 'Reabrir' : 'Fechar') . ' a operadora ' . (string)$o['insurer_name'] . ' em TODOS os polos deste cliente?') . '"'
                    : '';
                echo '<form method="post" action="/faturamento_fechamento_scope_post.php" style="display:inline"' . $opConfirm . '>';
                echo '<input type="hidden" name="month" value="' . h($month) . '"><input type="hidden" name="scope" value="operator"><input type="hidden" name="id" value="' . $opId . '"><input type="hidden" name="action" value="' . ($opClosed ? 'reopen' : 'close') . '">' . $filterHidden();
                if ($opClosed) {
                    echo '<button class="btn" type="submit" style="font-size:12px;padding:6px 12px">↩️ Reabrir</button>';
                } else {
                    echo '<button class="btn btnPrimary" type="submit" style="font-size:12px;padding:6px 12px">✔ Fechar operadora</button>';
                }
                echo '</form>';
                echo '</div>';
            }
            echo '</div>';

            // Tabela de linhas (um atendimento por linha) — SEM valores.
            echo '<div style="overflow:auto"><table class="ff-table">';
            echo '<thead><tr><th>Paciente</th><th>Profissional</th><th>Especialidade</th><th>PAD</th><th>Início</th><th>Desmame</th><th>Fim</th><th style="text-align:center">Sessões</th><th style="text-align:center">Ações</th></tr></thead><tbody>';
            foreach ($o['lines'] as $line) {
                $aid = (int)($line['assignment_id'] ?? 0);
                // Payload de detalhes (datas das sessões) para o modal.
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
                $padLabel = billing_closure_pad_label($line['session_frequency'] ?? '', $line['session_quantity'] ?? null, (int)($line['is_indefinite'] ?? 0));
                $poloName = (string)($line['polo_name'] ?? '');
                $notes = (string)($line['internal_notes'] ?? '');

                $dataDetail = h(json_encode([
                    'title' => (string)$line['patient_name'] . ' — ' . (string)$line['professional_name'],
                    'subtitle' => (string)$o['insurer_name'] . ($poloName !== '' ? ' · ' . $poloName : '') . ' · ' . (string)$line['specialty'] . ' · PAD ' . $padLabel,
                    'count' => (int)$line['sessions'],
                    'notes' => $notes,
                    'items' => $detailPayload,
                ], JSON_UNESCAPED_UNICODE));

                $dataEdit = h(json_encode([
                    'assignment_id' => $aid,
                    'title' => (string)$line['patient_name'] . ' — ' . (string)$line['professional_name'],
                    'specialty' => (string)($line['specialty_raw'] ?? ''),
                    'session_frequency' => (string)($line['session_frequency'] ?? ''),
                    'session_quantity' => $line['session_quantity'] ?? '',
                    'is_indefinite' => (int)($line['is_indefinite'] ?? 0),
                    'admitted_at' => $isoDate($line['admitted_at'] ?? ''),
                    'weaning_at' => $isoDate($line['weaning_at'] ?? ''),
                    'ended_at' => $isoDate($line['ended_at'] ?? ''),
                    'client_id' => $line['client_id'] ?? '',
                    'health_insurer_id' => $line['health_insurer_id'] ?? '',
                    'polo_id' => $line['polo_id'] ?? '',
                    'internal_notes' => $notes,
                    // Para o aviso de agenda / desmame.
                    'pending_future' => $pendingFuture[$aid] ?? 0,
                    'weekdays' => $line['weekdays'] ?? '',
                    'has_month_cfg' => assignment_schedule_month_cfg_from_json($line['month_days'] ?? null) !== null ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE));

                echo '<tr id="pa-' . $aid . '">';
                echo '<td style="font-weight:600">' . h((string)$line['patient_name']);
                if ($notes !== '') {
                    echo '<div class="ff-note" title="' . h($notes) . '">📝 ' . h(mb_strimwidth($notes, 0, 60, '…')) . '</div>';
                }
                echo '</td>';
                echo '<td>' . h((string)$line['professional_name']) . '</td>';
                echo '<td>' . h((string)$line['specialty']) . '</td>';
                echo '<td style="white-space:nowrap">' . h($padLabel) . '</td>';
                foreach (['admitted_at', 'weaning_at', 'ended_at'] as $dk) {
                    $dv = $fmtDate($line[$dk] ?? '');
                    echo '<td style="white-space:nowrap">' . ($dv !== '' ? h($dv) : '<span style="color:hsl(var(--muted-foreground))">-</span>') . '</td>';
                }
                echo '<td style="text-align:center;font-weight:700">' . (int)$line['sessions'] . '</td>';
                echo '<td style="text-align:center;white-space:nowrap">';
                echo '<button type="button" class="btn-detail" data-detail="' . $dataDetail . '" onclick="openDetail(this)" title="Ver as sessões">'
                    . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>'
                    . ' Detalhes</button> ';
                if ($aid > 0) {
                    if ($locked) {
                        echo '<button type="button" class="btn-detail" disabled style="opacity:.5;cursor:not-allowed" title="' . h($clientClosed ? 'Cliente consolidado: reabra o cliente para editar' : 'Operadora fechada: reabra a operadora para editar') . '">✏️ Editar</button>';
                    } else {
                        echo '<button type="button" class="btn-detail" data-edit="' . $dataEdit . '" onclick="openEdit(this)" title="Editar dados do atendimento">✏️ Editar</button>';
                    }
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>'; // fim operadora
        }
          echo '</div>'; // fim polo
        }

        echo '</section>'; // fim cliente
    }
}

echo '</div>';

// Estilos do botão de detalhes + modais.
echo '<style>
.ff-table td,.ff-table th{vertical-align:top}
.ff-polo{border-left:4px solid hsl(var(--primary));background:hsla(var(--primary)/.04);border-radius:10px;padding:10px 12px 2px;margin-bottom:14px}
.ff-polo-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.ff-note{margin-top:3px;font-size:11px;font-weight:500;color:#92400e;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn-detail{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;font-size:12px;font-weight:600;color:hsl(var(--primary));background:hsla(var(--primary)/.08);border:1px solid hsla(var(--primary)/.3);border-radius:999px;cursor:pointer;transition:all .15s}
.btn-detail:hover:not([disabled]){background:hsl(var(--primary));color:#fff;border-color:hsl(var(--primary))}
.det-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.det-overlay.open{display:flex}
.det-modal{background:#fff;border-radius:16px;max-width:520px;width:100%;max-height:90vh;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:detPop .18s ease-out;display:flex;flex-direction:column}
.det-modal.wide{max-width:760px}
@keyframes detPop{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.det-head{padding:18px 22px;background:linear-gradient(135deg,hsl(var(--primary)),hsl(var(--primary-dark,var(--primary))));color:#fff}
.det-head h3{margin:0;font-size:17px;font-weight:800}
.det-head .sub{margin-top:3px;font-size:13px;opacity:.9}
.det-body{padding:16px 22px;overflow-y:auto;flex:1}
.det-count{font-size:13px;font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:12px}
.det-notes{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:12px;white-space:pre-wrap}
.det-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:10px;margin-bottom:8px}
.det-item .cal{font-size:18px}
.det-item .dt{font-weight:700}
.det-item .ses{font-size:12px;color:hsl(var(--muted-foreground))}
.det-tag{margin-left:auto;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700}
.det-foot{padding:14px 22px;border-top:1px solid hsl(var(--border));text-align:right;display:flex;gap:10px;justify-content:flex-end}
.det-close{padding:8px 18px;background:hsl(var(--primary));color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer}
.det-cancel{padding:8px 18px;background:transparent;color:hsl(var(--muted-foreground));border:1px solid hsl(var(--border));border-radius:8px;font-weight:600;cursor:pointer}
.ed-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.ed-grid .full{grid-column:1/-1}
.ed-grid .two{grid-column:span 2}
.ed-grid label{display:block;font-size:12px;font-weight:700;margin-bottom:4px}
.ed-grid input,.ed-grid select,.ed-grid textarea{width:100%;padding:8px;border:1px solid hsl(var(--border));border-radius:8px;font-size:13px;box-sizing:border-box}
.ed-sec{grid-column:1/-1;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:hsl(var(--muted-foreground));margin-top:4px;border-bottom:1px solid hsl(var(--border));padding-bottom:4px}
.ed-hint{font-size:11px;color:hsl(var(--muted-foreground));margin-top:3px}
.ed-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:8px 10px;font-size:12px;margin-bottom:8px}
.ed-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:10px 12px;font-size:12px;margin-top:8px}
.ed-danger label{color:#7f1d1d}
.ed-resched-toggle{display:flex!important;align-items:center;gap:6px;font-size:13px!important;cursor:pointer}
@media (max-width:640px){.ed-grid{grid-template-columns:1fr}.ed-grid .two{grid-column:auto}}
</style>';

// Modal de detalhes (preenchido dinamicamente).
echo '<div class="det-overlay" id="detOverlay" onclick="if(event.target===this)closeDetail()">'
    . '<div class="det-modal">'
    . '<div class="det-head"><h3 id="detTitle">Detalhes</h3><div class="sub" id="detSub"></div></div>'
    . '<div class="det-body"><div class="det-notes" id="detNotes" style="display:none"></div><div class="det-count" id="detCount"></div><div id="detItems"></div></div>'
    . '<div class="det-foot"><button type="button" class="det-close" onclick="closeDetail()">Fechar</button></div>'
    . '</div></div>';

// Modal de edição do atendimento.
echo '<div class="det-overlay" id="edOverlay" onclick="if(event.target===this)closeEdit()">';
echo '<div class="det-modal wide">';
echo '<form method="post" action="/faturamento_atendimento_update_post.php" id="edForm" style="display:flex;flex-direction:column;min-height:0;flex:1">';
echo '<div class="det-head"><h3>Editar atendimento</h3><div class="sub" id="edTitle"></div></div>';
echo '<div class="det-body">';
echo '<input type="hidden" name="assignment_id" id="edAssignment" value="">';
echo '<input type="hidden" name="month" value="' . h($month) . '">' . $filterHidden();
echo '<div class="ed-grid">';

echo '<div class="ed-sec">Cliente · Polo · Operadora</div>';
echo '<div><label>Cliente</label><select name="client_id" id="edClient"><option value="">— Sem cliente —</option>';
foreach ($filterClients as $cl) {
    echo '<option value="' . (int)$cl['id'] . '">' . h((string)$cl['name']) . '</option>';
}
echo '</select></div>';
echo '<div><label>Polo</label><select name="polo_id" id="edPolo"><option value="">— Sem polo —</option>';
foreach ($filterPolos as $po) {
    if ((int)$po['is_active'] !== 1) { $inactive = ' (inativo)'; } else { $inactive = ''; }
    echo '<option value="' . (int)$po['id'] . '" data-client="' . (int)($po['client_id'] ?? 0) . '">' . h(polos_label($po, false) . $inactive) . '</option>';
}
echo '</select><div class="ed-hint"><a href="/polos_config.php" target="_blank">Cadastrar polo</a></div></div>';
echo '<div><label>Operadora</label><select name="health_insurer_id" id="edInsurer"><option value="">— Sem operadora —</option>';
foreach ($filterOperators as $op) {
    $inactive = (int)($op['is_active'] ?? 1) !== 1 ? ' (inativa)' : '';
    echo '<option value="' . (int)$op['id'] . '" data-client="' . (int)($op['client_id'] ?? 0) . '" data-polo="' . (int)($op['polo_id'] ?? 0) . '">' . h((string)$op['name'] . $inactive) . '</option>';
}
echo '</select></div>';

echo '<div class="ed-sec">Atendimento</div>';
echo '<div><label>Especialidade</label><input type="text" name="specialty" id="edSpecialty" list="edSpecList" maxlength="120"><datalist id="edSpecList">';
foreach ($specialtyOptions as $sp) {
    echo '<option value="' . h($sp) . '">';
}
echo '</datalist></div>';
echo '<div><label>PAD (frequência)</label><select name="session_frequency" id="edFrequency"><option value="">— Não definido —</option>';
foreach ($frequencyOptions as $fo) {
    echo '<option value="' . h($fo['code']) . '">' . h($fo['label']) . '</option>';
}
echo '</select></div>';
echo '<div><label>Qtd. de sessões autorizadas</label><input type="number" name="session_quantity" id="edQty" min="0" max="1000">';
echo '<label style="display:flex;align-items:center;gap:6px;font-weight:600;margin-top:6px"><input type="checkbox" name="is_indefinite" id="edIndef" value="1" style="width:auto"> Tempo indeterminado (contínuo)</label></div>';

// Agenda: aviso + opção de fazer o desmame (remarcar as próximas sessões).
echo '<div class="full">';
echo '<div class="ed-warn" id="edPadWarn" style="display:none">⚠️ Você mudou o PAD. Se não marcar a opção abaixo, muda <strong>só o cadastro</strong>: a agenda (datas das próximas sessões) continua igual.</div>';
echo '<label class="ed-resched-toggle"><input type="checkbox" name="reschedule" id="edResched" value="1" style="width:auto"> Também mudar a agenda (fazer o desmame)</label>';
echo '<div class="ed-danger" id="edReschedBox" style="display:none">';
echo '<div id="edReschedText" style="margin-bottom:8px"></div>';
echo '<label>Motivo do desmame *</label><textarea name="reschedule_reason" id="edReschedReason" rows="2" maxlength="1000" placeholder="Ex.: paciente evoluiu bem, reduzindo de 3x para 2x por semana"></textarea>';
echo '<label style="display:flex;align-items:center;gap:6px;font-weight:600;margin-top:8px"><input type="checkbox" name="reschedule_notify" id="edReschedNotify" value="1" checked style="width:auto"> Avisar profissional e paciente no WhatsApp (igual ao desmame do Monitoramento)</label>';
echo '</div>';
echo '<div class="ed-hint" id="edReschedBlocked" style="display:none"></div>';
echo '</div>';

echo '<div class="ed-sec">Datas</div>';
echo '<div><label>Início (admissão)</label><input type="date" name="admitted_at" id="edAdmitted"></div>';
echo '<div><label>Desmame</label><input type="date" name="weaning_at" id="edWeaning"></div>';
echo '<div><label>Fim / encerramento</label><input type="date" name="ended_at" id="edEnded"><div class="ed-hint">Só registra a data; para encerrar o atendimento use o fluxo de finalização.</div></div>';

echo '<div class="ed-sec">Observação interna</div>';
echo '<div class="full"><textarea name="internal_notes" id="edNotes" rows="4" maxlength="5000" placeholder="Visível só para a equipe (não vai para o profissional nem para o paciente)"></textarea></div>';
echo '<div class="full ed-hint">As alterações valem para o atendimento em todo o sistema (Monitoramento, Fechamento Financeiro etc.). A agenda só muda se a opção "Também mudar a agenda" estiver marcada.</div>';

echo '</div>'; // ed-grid
echo '</div>'; // det-body
echo '<div class="det-foot"><button type="button" class="det-cancel" onclick="closeEdit()">Cancelar</button><button type="submit" class="det-close">Salvar</button></div>';
echo '</form>';
echo '</div></div>';

// Dados das frequências para o aviso de agenda (dias da semana / mensal).
$ffFreqInfo = [];
foreach ($frequencyOptions as $fo) {
    $ffFreqInfo[$fo['code']] = [
        'label' => $fo['label'],
        'days' => array_values($fo['weekdays']),
        'monthly' => assignment_schedule_is_monthly($fo['code']),
    ];
}
echo '<script>var ffFreqInfo=' . json_encode($ffFreqInfo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';

echo '<script>
var edOrig = null;
var ffWdNames = ["", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb", "Dom"];
function edPadChanged(){
    if(!edOrig) return false;
    var f = document.getElementById("edFrequency").value || "";
    var q = document.getElementById("edQty").value || "";
    var i = document.getElementById("edIndef").checked ? 1 : 0;
    var oq = (edOrig.session_quantity === null || edOrig.session_quantity === undefined) ? "" : String(edOrig.session_quantity);
    return f !== (edOrig.session_frequency || "") || q !== oq || i !== Number(edOrig.is_indefinite || 0);
}
// Dias que a nova agenda vai usar: os já escolhidos (mesmo PAD) ou os padrão do PAD.
function edScheduleDays(code){
    if(edOrig && code === (edOrig.session_frequency || "") && edOrig.weekdays){
        try {
            var arr = JSON.parse(edOrig.weekdays);
            if(arr && arr.length) return arr;
        } catch(e){}
    }
    var fi = ffFreqInfo[code];
    return fi ? fi.days : [];
}
function edFmtDate(iso){ var p = String(iso).split("-"); return p.length === 3 ? p[2] + "/" + p[1] + "/" + p[0] : iso; }
function edUpdateAgenda(){
    if(!edOrig) return;
    var chk = document.getElementById("edResched");
    var box = document.getElementById("edReschedBox");
    var blocked = document.getElementById("edReschedBlocked");
    var code = document.getElementById("edFrequency").value || "";
    var fi = ffFreqInfo[code];
    var why = "";
    if(!fi){
        why = "Para mudar a agenda, escolha um PAD da lista.";
    } else if(fi.monthly && !(code === (edOrig.session_frequency || "") && Number(edOrig.has_month_cfg) === 1)){
        why = "Quinzenal/mensal: os dias do mês são escolhidos na tela de Desmame do Monitoramento.";
    } else if(!fi.monthly && edScheduleDays(code).length === 0){
        why = "Esse PAD não tem dias fixos na semana; ajuste a data da sessão pelo Monitoramento.";
    }
    blocked.textContent = "";
    if(why){
        chk.checked = false;
        chk.disabled = true;
        blocked.appendChild(document.createTextNode(why + " "));
        var a = document.createElement("a");
        a.href = "/monitoramento_desmame.php?assignment_id=" + encodeURIComponent(edOrig.assignment_id);
        a.target = "_blank";
        a.textContent = "Abrir Desmame";
        blocked.appendChild(a);
        blocked.style.display = "block";
    } else {
        chk.disabled = false;
        blocked.style.display = "none";
    }
    document.getElementById("edPadWarn").style.display = (edPadChanged() && !chk.checked) ? "block" : "none";
    box.style.display = chk.checked ? "block" : "none";
    if(!chk.checked) return;

    var today = new Date(); today.setHours(0,0,0,0);
    var w = document.getElementById("edWeaning").value;
    var startTxt = "hoje";
    if(w){ var wd = new Date(w + "T00:00:00"); if(wd > today) startTxt = edFmtDate(w); }
    var n = Number(edOrig.pending_future || 0);
    var qty = document.getElementById("edQty").value;
    var indef = document.getElementById("edIndef").checked;
    var days = fi.monthly ? "dias do mês já configurados" : edScheduleDays(code).map(function(d){ return ffWdNames[d] || d; }).join(", ");
    var txt = "Vai fazer o desmame: as " + n + " sessão(ões) pendente(s) de " + startTxt + " em diante ganham novas datas no PAD " + fi.label + " (" + days + ").";
    if(!indef && qty && Number(qty) > n){
        txt += " Serão criadas sessões até chegar a " + qty + ".";
    } else if(indef && n === 0){
        txt += " Como é contínuo e não há sessões pendentes, serão criadas 12.";
    }
    txt += " Sessões já realizadas, enviadas ou aprovadas não mudam. A data do desmame fica registrada (hoje, se o campo estiver vazio).";
    document.getElementById("edReschedText").textContent = txt;
}
(function(){
    ["edFrequency","edIndef","edResched","edWeaning"].forEach(function(id){
        var el = document.getElementById(id); if(el) el.addEventListener("change", edUpdateAgenda);
    });
    var q = document.getElementById("edQty"); if(q) q.addEventListener("input", edUpdateAgenda);
    var form = document.getElementById("edForm");
    if(form) form.addEventListener("submit", function(e){
        var chk = document.getElementById("edResched");
        if(!chk || !chk.checked) return;
        var reason = document.getElementById("edReschedReason");
        if(!reason.value.trim()){ e.preventDefault(); alert("Informe o motivo do desmame."); reason.focus(); return; }
        var n = Number((edOrig && edOrig.pending_future) || 0);
        var notify = document.getElementById("edReschedNotify").checked;
        var msg = "Confirmar o desmame?\n\n" + n + " sessão(ões) pendente(s) terão as datas remarcadas pelo novo PAD."
            + (notify ? "\nO profissional e o paciente serão avisados no WhatsApp." : "\nNinguém será avisado no WhatsApp.");
        if(!confirm(msg)) e.preventDefault();
    });
})();

function ffFilterByClient(clientSel, targetSel, keepNoClient){
    if(!clientSel || !targetSel) return;
    var cid = clientSel.value || "";
    var all = (cid === "" || cid === "0");
    Array.prototype.forEach.call(targetSel.options, function(o){
        var dc = o.getAttribute("data-client");
        if(dc === null) return; // opções fixas (Todos / Sem ...)
        var ok;
        if(all) ok = true;
        else if(cid === "-1") ok = (dc === "0");
        else ok = (dc === cid) || (keepNoClient && dc === "0");
        o.hidden = !ok;
        o.disabled = !ok;
    });
    var cur = targetSel.options[targetSel.selectedIndex];
    if(cur && cur.disabled) targetSel.value = targetSel.options[0].value;
}
(function(){
    var c = document.getElementById("fltClient");
    var p = document.getElementById("fltPolo");
    var i = document.getElementById("fltInsurer");
    function upd(){ ffFilterByClient(c, p, false); ffFilterByClient(c, i, false); }
    if(c){ c.addEventListener("change", upd); upd(); }
})();

function openDetail(btn){
    var data = {};
    try { data = JSON.parse(btn.getAttribute("data-detail")); } catch(e){ return; }
    document.getElementById("detTitle").textContent = data.title || "Detalhes";
    document.getElementById("detSub").textContent = data.subtitle || "";
    var nt = document.getElementById("detNotes");
    if(data.notes){ nt.textContent = "📝 " + data.notes; nt.style.display = "block"; } else { nt.style.display = "none"; }
    document.getElementById("detCount").textContent = "Sessões que compõem a quantidade (" + (data.count||0) + ")";
    var wrap = document.getElementById("detItems");
    wrap.innerHTML = "";
    if(!data.items || data.items.length === 0){
        var empty = document.createElement("div");
        empty.style.cssText = "color:#64748b;font-size:13px";
        empty.textContent = "Sem detalhes disponíveis.";
        wrap.appendChild(empty);
    } else {
        data.items.forEach(function(it){
            var div = document.createElement("div");
            div.className = "det-item";
            var cal = document.createElement("span"); cal.className = "cal"; cal.textContent = "📅";
            var info = document.createElement("span");
            var dt = document.createElement("span"); dt.className = "dt"; dt.textContent = it.date;
            var ses = document.createElement("span"); ses.className = "ses";
            ses.textContent = " (Sessão " + it.number + ")" + (it.operator ? " · " + it.operator : "");
            info.appendChild(dt); info.appendChild(ses);
            div.appendChild(cal); div.appendChild(info);
            if(it.manual){ var tg = document.createElement("span"); tg.className = "det-tag"; tg.textContent = "manual"; div.appendChild(tg); }
            wrap.appendChild(div);
        });
    }
    document.getElementById("detOverlay").classList.add("open");
}
function closeDetail(){ document.getElementById("detOverlay").classList.remove("open"); }

function edSetSelect(sel, value, labelIfMissing){
    value = (value === null || value === undefined) ? "" : String(value);
    var found = false;
    Array.prototype.forEach.call(sel.options, function(o){
        // Valor já salvo continua selecionável mesmo se não for do cliente atual.
        if(o.value === value){ found = true; o.hidden = false; o.disabled = false; }
    });
    if(!found && value !== ""){
        var o = document.createElement("option");
        o.value = value; o.textContent = labelIfMissing || value;
        sel.appendChild(o);
    }
    sel.value = value;
}
function edSyncOrg(){
    var c = document.getElementById("edClient");
    ffFilterByClient(c, document.getElementById("edPolo"), true);
    ffFilterByClient(c, document.getElementById("edInsurer"), true);
}
function openEdit(btn){
    var d = {};
    try { d = JSON.parse(btn.getAttribute("data-edit")); } catch(e){ return; }
    document.getElementById("edAssignment").value = d.assignment_id;
    document.getElementById("edTitle").textContent = d.title || "";
    edSetSelect(document.getElementById("edClient"), d.client_id);
    edSyncOrg();
    edSetSelect(document.getElementById("edPolo"), d.polo_id);
    edSetSelect(document.getElementById("edInsurer"), d.health_insurer_id);
    document.getElementById("edSpecialty").value = d.specialty || "";
    edSetSelect(document.getElementById("edFrequency"), d.session_frequency, d.session_frequency + " (legado)");
    document.getElementById("edQty").value = (d.session_quantity === null || d.session_quantity === undefined) ? "" : d.session_quantity;
    document.getElementById("edIndef").checked = Number(d.is_indefinite) === 1;
    document.getElementById("edAdmitted").value = d.admitted_at || "";
    document.getElementById("edWeaning").value = d.weaning_at || "";
    document.getElementById("edEnded").value = d.ended_at || "";
    document.getElementById("edNotes").value = d.internal_notes || "";
    edOrig = d;
    document.getElementById("edResched").checked = false;
    document.getElementById("edReschedReason").value = "";
    document.getElementById("edReschedNotify").checked = true;
    edUpdateAgenda();
    document.getElementById("edOverlay").classList.add("open");
}
function closeEdit(){ document.getElementById("edOverlay").classList.remove("open"); }
(function(){
    var c = document.getElementById("edClient");
    var i = document.getElementById("edInsurer");
    var p = document.getElementById("edPolo");
    if(c) c.addEventListener("change", edSyncOrg);
    // Ao escolher a operadora: completa cliente e polo padrão se estiverem vazios.
    if(i) i.addEventListener("change", function(){
        var o = i.options[i.selectedIndex];
        if(!o || !o.value) return;
        var dc = o.getAttribute("data-client"), dp = o.getAttribute("data-polo");
        if(dc && dc !== "0" && !c.value){ c.value = dc; edSyncOrg(); i.value = o.value; }
        if(dp && dp !== "0" && !p.value){ p.value = dp; }
    });
})();
document.addEventListener("keydown", function(e){ if(e.key === "Escape"){ closeDetail(); closeEdit(); } });
</script>';

view_footer();
