<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$entryType = isset($_GET['type']) ? (string)$_GET['type'] : 'all';
$status = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$periodMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
$periodYear = isset($_GET['year']) ? trim((string)$_GET['year']) : date('Y');
$costCenter = isset($_GET['cost_center']) ? trim((string)$_GET['cost_center']) : '';

$db = db();

// RECEITAS de atendimentos (patient_assignments)
$sqlReceitas = "
    SELECT 
        pa.id,
        'income' as entry_type,
        COALESCE(pa.specialty, 'Atendimento') as category,
        (COALESCE(pa.authorized_value, 0) * pa.session_quantity) as amount,
        CONCAT('Receita - ', COALESCE(pa.service_type, pa.specialty, 'Atendimento'), ' - ', pa.session_quantity, ' sessões - ', p.full_name) as description,
        pa.created_at as entry_date,
        pa.created_at,
        CASE WHEN pa.status = 'paid' THEN 'paid' ELSE 'pending' END as status,
        p.full_name as patient_name,
        u.name as professional_name,
        'Sistema' as created_by_name,
        NULL as installment_info,
        'Fluxo Operacional' as cost_center,
        'atendimento' as source
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    WHERE pa.authorized_value IS NOT NULL AND pa.authorized_value > 0
";

// DESPESAS de atendimentos (custos)
$sqlDespesas = "
    SELECT 
        pa.id + 1000000 as id,
        'expense' as entry_type,
        CONCAT('Custo - ', COALESCE(pa.specialty, 'Atendimento')) as category,
        (COALESCE(pa.agreed_value, pa.payment_value, 0) * pa.session_quantity) as amount,
        CONCAT('Despesa - ', COALESCE(pa.service_type, pa.specialty, 'Atendimento'), ' - ', pa.session_quantity, ' sessões - ', p.full_name) as description,
        pa.created_at as entry_date,
        pa.created_at,
        CASE WHEN pa.status = 'paid' THEN 'paid' ELSE 'pending' END as status,
        p.full_name as patient_name,
        u.name as professional_name,
        'Sistema' as created_by_name,
        NULL as installment_info,
        'Fluxo Operacional' as cost_center,
        'custo' as source
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    LEFT JOIN users u ON u.id = pa.professional_user_id
    WHERE pa.agreed_value IS NOT NULL AND pa.agreed_value > 0
";

// LANÇAMENTOS MANUAIS
$sqlManuais = "
    SELECT 
        fe.id + 2000000 as id,
        fe.entry_type,
        fe.category,
        fe.amount,
        fe.description,
        fe.entry_date,
        fe.created_at,
        fe.status,
        p.full_name as patient_name,
        u.name as professional_name,
        creator.name as created_by_name,
        CASE 
            WHEN fe.payment_type = 'installment' AND fe.total_installments > 0 
            THEN CONCAT(fe.installment_number, '/', fe.total_installments)
            ELSE NULL
        END as installment_info,
        fe.cost_center,
        'manual' as source
    FROM financial_entries fe
    LEFT JOIN patients p ON p.id = fe.patient_id
    LEFT JOIN users u ON u.id = fe.professional_user_id
    LEFT JOIN users creator ON creator.id = fe.created_by_user_id
    WHERE fe.is_active = 1 AND fe.amount > 0
";

// Base combinada (UNION ALL das 3 fontes)
$baseUnion = "(($sqlReceitas) UNION ALL ($sqlDespesas) UNION ALL ($sqlManuais)) AS all_entries";

// Filtros (aplicados sobre o conjunto combinado). WHERE compartilhado entre listagem, contagem e totais.
$whereSql = ' WHERE 1=1';
$params = [];

if ($entryType !== 'all') {
    $whereSql .= " AND entry_type = :entry_type";
    $params['entry_type'] = $entryType;
}

if ($status !== 'all') {
    $whereSql .= " AND status = :status";
    $params['status'] = $status;
}

if ($searchQuery !== '') {
    $whereSql .= " AND (patient_name LIKE :search OR professional_name LIKE :search OR description LIKE :search OR category LIKE :search)";
    $params['search'] = '%' . $searchQuery . '%';
}

if ($costCenter !== '') {
    $whereSql .= " AND cost_center = :cost_center";
    $params['cost_center'] = $costCenter;
}

if ($periodMonth !== '' && $periodYear !== '') {
    $whereSql .= " AND DATE_FORMAT(entry_date, '%Y-%m') = :period";
    $params['period'] = $periodYear . '-' . str_pad($periodMonth, 2, '0', STR_PAD_LEFT);
} elseif ($periodYear !== '') {
    $whereSql .= " AND YEAR(entry_date) = :year";
    $params['year'] = $periodYear;
}

// ITEM 16: Paginação no backend
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Contagem total sobre o conjunto filtrado
$countStmt = $db->prepare("SELECT COUNT(*) FROM " . $baseUnion . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Totais (receitas/despesas/saldo) sobre TODOS os registros filtrados, não só a página
$sumStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN entry_type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN entry_type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
     FROM " . $baseUnion . $whereSql
);
$sumStmt->execute($params);
$sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalIncome = (float)($sumRow['total_income'] ?? 0);
$totalExpense = (float)($sumRow['total_expense'] ?? 0);
$balance = $totalIncome - $totalExpense;

// Listagem paginada
$sql = "SELECT * FROM " . $baseUnion . $whereSql . " ORDER BY entry_date DESC, created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper para preservar filtros nos links de paginação
$buildPageUrl = function (int $p) use ($entryType, $status, $searchQuery, $periodMonth, $periodYear, $costCenter): string {
    $qs = array_filter([
        'type' => $entryType !== 'all' ? $entryType : '',
        'status' => $status !== 'all' ? $status : '',
        'q' => $searchQuery,
        'month' => $periodMonth,
        'year' => $periodYear,
        'cost_center' => $costCenter,
        'page' => $p,
    ], fn($v) => $v !== '' && $v !== null);
    return '/finance_entries_list.php?' . http_build_query($qs);
};

view_header('Lançamentos Financeiros');

echo '<div class="grid">';

// Cabeçalho
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Lançamentos Financeiros</div>';
if ($periodMonth !== '' && $periodYear !== '') {
    $monthName = date('F', mktime(0, 0, 0, (int)$periodMonth, 1));
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Período: ' . $monthName . '/' . $periodYear . '</div>';
} elseif ($periodYear !== '') {
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Período: Ano ' . $periodYear . '</div>';
} else {
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Receitas e despesas do sistema de faturamento</div>';
}
echo '</div>';
echo '</div>';
echo '</section>';

// Resumo
echo '<section class="card col4" style="background:#f0fdf4;border-left:4px solid #10b981">';
echo '<div style="font-size:14px;color:#065f46;margin-bottom:4px">Receitas</div>';
echo '<div style="font-size:28px;font-weight:700;color:#10b981">R$ ' . number_format($totalIncome, 2, ',', '.') . '</div>';
echo '</section>';

echo '<section class="card col4" style="background:#fef2f2;border-left:4px solid #dc2626">';
echo '<div style="font-size:14px;color:#991b1b;margin-bottom:4px">Despesas</div>';
echo '<div style="font-size:28px;font-weight:700;color:#dc2626">R$ ' . number_format($totalExpense, 2, ',', '.') . '</div>';
echo '</section>';

echo '<section class="card col4" style="background:#f0f9ff;border-left:4px solid #0284c7">';
echo '<div style="font-size:14px;color:#0c4a6e;margin-bottom:4px">Saldo</div>';
echo '<div style="font-size:28px;font-weight:700;color:' . ($balance >= 0 ? '#10b981' : '#dc2626') . '">R$ ' . number_format($balance, 2, ',', '.') . '</div>';
echo '</section>';

// Filtros
echo '<section class="card col12">';
echo '<form method="get" action="/finance_entries_list.php" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">';

echo '<div>';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Tipo</label>';
echo '<select name="type" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
echo '<option value="all"' . ($entryType === 'all' ? ' selected' : '') . '>Todos</option>';
echo '<option value="income"' . ($entryType === 'income' ? ' selected' : '') . '>Receitas</option>';
echo '<option value="expense"' . ($entryType === 'expense' ? ' selected' : '') . '>Despesas</option>';
echo '</select>';
echo '</div>';

echo '<div>';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Status</label>';
echo '<select name="status" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
echo '<option value="all"' . ($status === 'all' ? ' selected' : '') . '>Todos</option>';
echo '<option value="pending"' . ($status === 'pending' ? ' selected' : '') . '>Pendente</option>';
echo '<option value="paid"' . ($status === 'paid' ? ' selected' : '') . '>Pago</option>';
echo '<option value="cancelled"' . ($status === 'cancelled' ? ' selected' : '') . '>Cancelado</option>';
echo '</select>';
echo '</div>';

echo '<div>';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Ano</label>';
echo '<select name="year" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
for ($y = date('Y') + 1; $y >= 2024; $y--) {
    $sel = ($periodYear === (string)$y) ? ' selected' : '';
    echo '<option value="' . $y . '"' . $sel . '>' . $y . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div>';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Mês</label>';
echo '<select name="month" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
echo '<option value="">Todos os meses</option>';
for ($m = 1; $m <= 12; $m++) {
    $sel = ($periodMonth === (string)$m) ? ' selected' : '';
    $monthName = date('F', mktime(0, 0, 0, $m, 1));
    echo '<option value="' . $m . '"' . $sel . '>' . $monthName . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div>';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Centro de Custo</label>';
echo '<select name="cost_center" style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
echo '<option value="">Todos</option>';
$costCentersStmt = $db->query('SELECT DISTINCT name FROM cost_centers WHERE is_active = 1 ORDER BY name ASC');
$costCentersList = $costCentersStmt->fetchAll();
foreach ($costCentersList as $cc) {
    $sel = ($costCenter === $cc['name']) ? ' selected' : '';
    echo '<option value="' . h($cc['name']) . '"' . $sel . '>' . h($cc['name']) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div>';
echo '<label style="display:block;font-weight:600;margin-bottom:4px">Buscar</label>';
echo '<input type="text" name="q" value="' . h($searchQuery) . '" placeholder="Paciente, profissional..." style="width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px">';
echo '</div>';

echo '<div style="display:flex;align-items:flex-end">';
echo '<button type="submit" class="btn btnPrimary">Filtrar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

// Lista de lançamentos
echo '<section class="card col12">';
echo '<h3>Lançamentos (' . number_format($totalRows, 0, ',', '.') . ')</h3>';

if (count($entries) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">Nenhum lançamento encontrado</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Data</th>';
    echo '<th>Tipo</th>';
    echo '<th>Categoria</th>';
    echo '<th>Centro de Custo</th>';
    echo '<th>Paciente</th>';
    echo '<th>Profissional</th>';
    echo '<th>Descrição</th>';
    echo '<th style="text-align:right">Valor</th>';
    echo '<th>Status</th>';
    echo '<th>Criado por</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($entries as $entry) {
        $typeColor = $entry['entry_type'] === 'income' ? '#10b981' : '#dc2626';
        $statusColors = [
            'pending' => '#f59e0b',
            'paid' => '#10b981',
            'cancelled' => '#dc2626'
        ];
        $statusColor = $statusColors[$entry['status']] ?? '#667781';
        
        echo '<tr>';
        echo '<td>' . date('d/m/Y', strtotime($entry['entry_date'])) . '</td>';
        echo '<td><span style="color:' . $typeColor . ';font-weight:600">' . ($entry['entry_type'] === 'income' ? 'Receita' : 'Despesa') . '</span></td>';
        echo '<td>' . h($entry['category']) . '</td>';
        echo '<td>' . h($entry['cost_center'] ?? '-') . '</td>';
        echo '<td>' . h($entry['patient_name'] ?? '-') . '</td>';
        echo '<td>' . h($entry['professional_name'] ?? '-') . '</td>';
        echo '<td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:normal;font-size:12px;line-height:1.4">' . h($entry['description'] ?? '-') . '</td>';
        echo '<td style="text-align:right;font-weight:600;color:' . $typeColor . '">R$ ' . number_format((float)$entry['amount'], 2, ',', '.') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . h($entry['status']) . '</span></td>';
        echo '<td>' . h($entry['created_by_name'] ?? '-') . '</td>';
        echo '</tr>';
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
        echo '<div style="text-align:center;margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $totalPages . ' • ' . number_format($totalRows, 0, ',', '.') . ' lançamento(s)</div>';
    }
}

echo '</section>';

echo '</div>';

view_footer();
