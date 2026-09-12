<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'pendentes';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowedTabs = ['pendentes', 'historico'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'pendentes';
}

// ITEM 12: Filtro por período (intervalo de datas sobre entry_date)
$dateFrom = isset($_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_from']) ? (string)$_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date_to']) ? (string)$_GET['date_to'] : '';

// Definir status baseado na aba
$status = ($tab === 'pendentes') ? 'pendente' : 'recebido';

// ============================================================================
// FONTE ÚNICA: financial_entries (evita contagem dupla com patient_assignments)
// ============================================================================
$sql = 'SELECT fe.id, fe.amount, 
               COALESCE(fe.due_date, fe.entry_date) as due_at, 
               CASE 
                   WHEN fe.status = "paid" THEN "recebido"
                   WHEN fe.status = "pending" THEN "pendente"
                   ELSE "pendente"
               END as status, 
               fe.paid_date as received_at,
               COALESCE(fe.assignment_id, 0) as appointment_id, 
               fe.created_at as first_at,
               COALESCE(p.full_name, fe.supplier_name, "-") AS patient_name,
               COALESCE(u.name, "-") AS professional_name,
               "financial_entry" as source,
               fe.description,
               fe.category as specialty,
               fe.payment_type as service_type,
               fe.category,
               COALESCE(fe.cost_center, "-") as cost_center,
               hi.name as operadora
        FROM financial_entries fe
        LEFT JOIN patients p ON p.id = fe.patient_id
        LEFT JOIN users u ON u.id = fe.professional_user_id
        LEFT JOIN patient_assignments pa ON pa.id = fe.assignment_id
        LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
        WHERE fe.entry_type = "income" AND fe.is_active = 1';

$params = [];

// Cláusula WHERE compartilhada (aba + busca + período), aplicada na listagem.
$whereExtra = '';
if ($status === 'recebido') {
    $whereExtra .= ' AND fe.status = "paid"';
} elseif ($status === 'pendente') {
    $whereExtra .= ' AND fe.status = "pending"';
}

if ($q !== '') {
    $whereExtra .= ' AND (p.full_name LIKE :q1 OR u.name LIKE :q2 OR fe.description LIKE :q3)';
    $qLike = '%' . $q . '%';
    $params['q1'] = $qLike;
    $params['q2'] = $qLike;
    $params['q3'] = $qLike;
}

if ($dateFrom !== '') {
    $whereExtra .= ' AND fe.entry_date >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $whereExtra .= ' AND fe.entry_date <= :date_to';
    $params['date_to'] = $dateTo;
}

$sql .= $whereExtra;

// Resumo financeiro: calculado sobre TODOS os registros filtrados por busca/período
// (independe da aba, mostra sempre pendente + recebido do universo filtrado).
// CORREÇÃO ITEM 12: usa fe.status = 'pending'/'paid' (valores reais da coluna, em inglês).
$summaryWhere = '';
$summaryParams = [];
if ($q !== '') {
    $summaryWhere .= ' AND (p.full_name LIKE :q1 OR u.name LIKE :q2 OR fe.description LIKE :q3)';
    $summaryParams['q1'] = '%' . $q . '%';
    $summaryParams['q2'] = '%' . $q . '%';
    $summaryParams['q3'] = '%' . $q . '%';
}
if ($dateFrom !== '') {
    $summaryWhere .= ' AND fe.entry_date >= :date_from';
    $summaryParams['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $summaryWhere .= ' AND fe.entry_date <= :date_to';
    $summaryParams['date_to'] = $dateTo;
}
$sumStmt = db()->prepare(
    'SELECT
        COALESCE(SUM(CASE WHEN fe.status = "pending" THEN fe.amount ELSE 0 END), 0) AS total_pendente,
        COALESCE(SUM(CASE WHEN fe.status = "paid" THEN fe.amount ELSE 0 END), 0) AS total_recebido,
        SUM(CASE WHEN fe.status = "pending" THEN 1 ELSE 0 END) AS qtd_pendente,
        SUM(CASE WHEN fe.status = "paid" THEN 1 ELSE 0 END) AS qtd_recebido
     FROM financial_entries fe
     LEFT JOIN patients p ON p.id = fe.patient_id
     LEFT JOIN users u ON u.id = fe.professional_user_id
     WHERE fe.entry_type = "income" AND fe.is_active = 1' . $summaryWhere
);
$sumStmt->execute($summaryParams);
$sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalPendente = (float)($sumRow['total_pendente'] ?? 0);
$totalRecebido = (float)($sumRow['total_recebido'] ?? 0);
$qtdPendente = (int)($sumRow['qtd_pendente'] ?? 0);
$qtdRecebido = (int)($sumRow['qtd_recebido'] ?? 0);

$totalGeral = $totalPendente + $totalRecebido;
$qtdGeral = $qtdPendente + $qtdRecebido;

// Contagem total para paginação (respeita aba + busca + período)
$countSql = 'SELECT COUNT(*)
    FROM financial_entries fe
    LEFT JOIN patients p ON p.id = fe.patient_id
    LEFT JOIN users u ON u.id = fe.professional_user_id
    LEFT JOIN patient_assignments pa ON pa.id = fe.assignment_id
    LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
    WHERE fe.entry_type = "income" AND fe.is_active = 1' . $whereExtra;
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

// ITEM 12: Série mensal (últimos 12 meses) de receitas recebidas x a receber
$chartLabels = [];
$chartRecebido = [];
$chartPendente = [];
try {
    $serieStmt = db()->query("
        SELECT DATE_FORMAT(fe.entry_date, '%Y-%m') AS mes,
               COALESCE(SUM(CASE WHEN fe.status = 'paid' THEN fe.amount ELSE 0 END), 0) AS recebido,
               COALESCE(SUM(CASE WHEN fe.status = 'pending' THEN fe.amount ELSE 0 END), 0) AS pendente
        FROM financial_entries fe
        WHERE fe.entry_type = 'income' AND fe.is_active = 1
          AND fe.entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(fe.entry_date, '%Y-%m')
        ORDER BY mes ASC
    ");
    foreach ($serieStmt->fetchAll(PDO::FETCH_ASSOC) as $srow) {
        $chartLabels[] = date('m/Y', strtotime($srow['mes'] . '-01'));
        $chartRecebido[] = round((float)$srow['recebido'], 2);
        $chartPendente[] = round((float)$srow['pendente'], 2);
    }
} catch (Throwable $e) {}

// Paginação
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$sql .= ' ORDER BY fe.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$buildPageUrl = function (int $p): string {
    $qs = $_GET;
    $qs['page'] = $p;
    return '/finance_receivable_list.php?' . http_build_query($qs);
};

view_header('Financeiro - Contas a Receber');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Contas a Receber</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gerencie suas receitas e recebimentos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/finance_entry_create.php?type=income">+ Nova Receita</a>';
echo '<a class="btn" href="/finance_entries_list.php">Ver Lançamentos</a>';
echo '<a class="btn" href="/finance_payable_list.php">Contas a Pagar</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Sistema de abas
echo '<div style="margin-top:20px;border-bottom:2px solid hsl(var(--border))">';
echo '<div style="display:flex;gap:4px">';

$tabs = [
    'pendentes' => 'Pendentes',
    'historico' => 'Histórico',
];

foreach ($tabs as $tabKey => $tabLabel) {
    $isActive = ($tab === $tabKey);
    $activeStyle = $isActive 
        ? 'background:hsl(var(--primary));color:white;border-color:hsl(var(--primary))' 
        : 'background:white;color:#667781;border-color:transparent';
    
    $queryParams = ['tab' => $tabKey];
    if ($q !== '') {
        $queryParams['q'] = $q;
    }
    
    echo '<a href="/finance_receivable_list.php?' . http_build_query($queryParams) . '" ';
    echo 'style="padding:12px 24px;text-decoration:none;font-weight:600;border:2px solid;border-bottom:none;border-radius:8px 8px 0 0;' . $activeStyle . '">';
    echo h($tabLabel);
    echo '</a>';
}

echo '</div>';
echo '</div>';

// Formulário de busca + filtro por período (item 12)
echo '<form method="get" action="/finance_receivable_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (paciente/descrição/categoria)" style="flex:1;min-width:220px">';
echo '<input type="date" name="date_from" value="' . h($dateFrom) . '" title="Data inicial (De)">';
echo '<input type="date" name="date_to" value="' . h($dateTo) . '" title="Data final (Até)">';
echo '<button class="btn" type="submit">Buscar</button>';
if ($q !== '' || $dateFrom !== '' || $dateTo !== '') {
    echo '<a class="btn" href="/finance_receivable_list.php?tab=' . h($tab) . '">Limpar</a>';
}
echo '</form>';

echo '</section>';

// Dashboard de resumo financeiro
echo '<section class="card col12">';
echo '<div style="font-size:16px;font-weight:700;margin-bottom:16px">Resumo Financeiro</div>';
echo '<div class="grid">';

// Card: Total Geral
echo '<div class="col3">';
echo '<div style="padding:20px;background:linear-gradient(135deg,hsl(var(--success)),hsl(var(--success)/.8));border-radius:12px;color:white">';
echo '<div style="font-size:13px;opacity:.9;margin-bottom:8px">Total Geral</div>';
echo '<div style="font-size:28px;font-weight:900">R$ ' . number_format($totalGeral, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:.8;margin-top:8px">' . $qtdGeral . ' registro(s)</div>';
echo '</div>';
echo '</div>';

// Card: Pendente
echo '<div class="col3">';
echo '<div style="padding:20px;background:linear-gradient(135deg,hsl(var(--success)),hsl(var(--success)/.8));border-radius:12px;color:white">';
echo '<div style="font-size:13px;opacity:.9;margin-bottom:8px">Pendente</div>';
echo '<div style="font-size:28px;font-weight:900">R$ ' . number_format($totalPendente, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:.8;margin-top:8px">' . $qtdPendente . ' registro(s)</div>';
echo '</div>';
echo '</div>';

// Card: Recebido
echo '<div class="col3">';
echo '<div style="padding:20px;background:linear-gradient(135deg,hsl(var(--success)),hsl(var(--success)/.8));border-radius:12px;color:white">';
echo '<div style="font-size:13px;opacity:.9;margin-bottom:8px">Recebido</div>';
echo '<div style="font-size:28px;font-weight:900">R$ ' . number_format($totalRecebido, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:.8;margin-top:8px">' . $qtdRecebido . ' registro(s)</div>';
echo '</div>';
echo '</div>';

// Card: Taxa de Recebimento
$taxaRecebimento = $totalGeral > 0 ? ($totalRecebido / $totalGeral) * 100 : 0;
echo '<div class="col3">';
echo '<div style="padding:20px;background:linear-gradient(135deg,hsl(var(--success)),hsl(var(--success)/.8));border-radius:12px;color:white">';
echo '<div style="font-size:13px;opacity:.9;margin-bottom:8px">Taxa de Recebimento</div>';
echo '<div style="font-size:28px;font-weight:900">' . number_format($taxaRecebimento, 1) . '%</div>';
echo '<div style="font-size:12px;opacity:.8;margin-top:8px">Recebido / Total</div>';
echo '</div>';
echo '</div>';

echo '</div>';
echo '</section>';

// ITEM 12: Gráficos de Contas a Receber (SVG no servidor)
echo '<section class="card col8" style="padding:24px">';
echo '<div style="font-size:16px;font-weight:800;margin-bottom:16px">Receitas por Mês (últimos 12 meses)</div>';
echo svg_bar_chart(
    $chartLabels,
    [
        ['name' => 'Recebido', 'color' => '#10b981', 'data' => $chartRecebido],
        ['name' => 'A Receber', 'color' => '#3b82f6', 'data' => $chartPendente],
    ],
    300
);
echo '</section>';

echo '<section class="card col4" style="padding:24px">';
echo '<div style="font-size:16px;font-weight:800;margin-bottom:16px">Recebido x A Receber</div>';
echo svg_donut_chart([
    ['label' => 'Recebido', 'value' => $totalRecebido, 'color' => '#10b981'],
    ['label' => 'A Receber', 'value' => $totalPendente, 'color' => '#3b82f6'],
], 220);
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Agendamento</th><th>Data</th><th>Paciente</th><th>Operadora / Cliente</th><th>Ligação</th><th>Centro de Custo</th><th>Valor</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    
    // Agendamento: "Não aplicável" para lançamentos manuais, "#ID" para atendimentos
    $appointmentDisplay = '-';
    if ((string)$r['source'] === 'financial_entry') {
        $appointmentDisplay = 'Não aplicável';
    } elseif ((int)$r['appointment_id'] > 0) {
        $appointmentDisplay = '#' . (int)$r['appointment_id'];
    }
    echo '<td>' . $appointmentDisplay . '</td>';
    
    echo '<td>' . date('d/m/Y', strtotime((string)$r['first_at'])) . '</td>';
    
    // Paciente: "Não aplicável" para lançamentos manuais
    $pacienteDisplay = h((string)$r['patient_name']);
    if ((string)$r['source'] === 'financial_entry' && ($r['patient_name'] === '-' || empty($r['patient_name']))) {
        $pacienteDisplay = 'Não aplicável';
    }
    echo '<td style="font-weight:700">' . $pacienteDisplay . '</td>';
    
    // Operadora: mostrar operadora se disponível
    $operadoraDisplay = 'Não aplicável';
    if (!empty($r['operadora'])) {
        $operadoraDisplay = h((string)$r['operadora']);
    } elseif ((int)$r['appointment_id'] > 0) {
        $operadoraDisplay = 'Não informado';
    }
    echo '<td>' . $operadoraDisplay . '</td>';
    
    // Ligação: "Profissional - nome" para atendimentos, "Categoria" para lançamentos manuais
    $ligacao = '-';
    if ((string)$r['source'] === 'financial_entry') {
        // Lançamento manual: mostrar categoria
        $ligacao = h((string)($r['category'] ?? 'Sem categoria'));
    } elseif (!empty($r['professional_name']) && $r['professional_name'] !== '-') {
        // Atendimento: mostrar profissional
        $ligacao = 'Profissional - ' . h((string)$r['professional_name']);
    }
    echo '<td>' . $ligacao . '</td>';
    
    // Centro de Custo: "Não selecionado" quando vazio
    $costCenterDisplay = h((string)($r['cost_center'] ?? ''));
    if (empty($costCenterDisplay) || $costCenterDisplay === '-') {
        $costCenterDisplay = 'Não selecionado';
    }
    echo '<td>' . $costCenterDisplay . '</td>';
    echo '<td style="font-weight:600;color:#10b981">R$ ' . number_format((float)$r['amount'], 2, ',', '.') . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td style="text-align:right">';

    // Só permitir alterar status na aba de pendentes para lançamentos financeiros
    if ($tab === 'pendentes' && (string)$r['source'] === 'financial_entry' && (string)$r['status'] === 'pendente') {
        echo '<form method="post" action="/finance_receivable_set_status_post.php" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
        echo '<select name="status" style="min-width:160px">';
        foreach (['pendente','recebido'] as $st) {
            $sel = ((string)$r['status'] === $st) ? ' selected' : '';
            echo '<option value="' . h($st) . '"' . $sel . '>' . ucfirst($st) . '</option>';
        }
        echo '</select>';
        echo '<button class="btn" type="submit" style="height:34px">Salvar</button>';
        echo '</form>';
    } elseif ($tab === 'historico' && !empty($r['received_at'])) {
        // Mostrar data de recebimento no histórico
        echo '<span style="font-size:13px;color:hsl(var(--muted-foreground))">Recebido em ' . date('d/m/Y', strtotime($r['received_at'])) . '</span>';
    } else {
        echo '<span style="font-size:13px;color:hsl(var(--muted-foreground))">-</span>';
    }

    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="10" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';

// Paginação (item 16)
if ($totalPages > 1) {
    echo '<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;flex-wrap:wrap">';
    if ($page > 1) echo '<a class="btn" href="' . h($buildPageUrl($page - 1)) . '">← Anterior</a>';
    $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
    if ($start > 1) { echo '<a class="btn" href="' . h($buildPageUrl(1)) . '">1</a>'; if ($start > 2) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; }
    for ($i = $start; $i <= $end; $i++) {
        echo $i === $page ? '<span class="btn btnPrimary" style="pointer-events:none">' . $i . '</span>' : '<a class="btn" href="' . h($buildPageUrl($i)) . '">' . $i . '</a>';
    }
    if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; echo '<a class="btn" href="' . h($buildPageUrl($totalPages)) . '">' . $totalPages . '</a>'; }
    if ($page < $totalPages) echo '<a class="btn" href="' . h($buildPageUrl($page + 1)) . '">Próxima →</a>';
    echo '</div>';
    echo '<div style="text-align:center;margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $totalPages . '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
