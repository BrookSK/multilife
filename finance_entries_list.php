<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$entryType = isset($_GET['type']) ? (string)$_GET['type'] : 'all';
$status = isset($_GET['status']) ? (string)$_GET['status'] : 'all';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// Buscar lançamentos financeiros
$sql = "
    SELECT 
        fe.*,
        p.name as patient_name,
        u.name as professional_name,
        creator.name as created_by_name,
        CASE 
            WHEN fe.payment_type = 'installment' AND fe.total_installments > 0 
            THEN CONCAT(fe.installment_number, '/', fe.total_installments)
            ELSE NULL
        END as installment_info
    FROM financial_entries fe
    LEFT JOIN patients p ON p.id = fe.patient_id
    LEFT JOIN users u ON u.id = fe.professional_user_id
    LEFT JOIN users creator ON creator.id = fe.created_by_user_id
    WHERE fe.is_active = 1
";

$params = [];

if ($entryType !== 'all') {
    $sql .= " AND fe.entry_type = :entry_type";
    $params['entry_type'] = $entryType;
}

if ($status !== 'all') {
    $sql .= " AND fe.status = :status";
    $params['status'] = $status;
}

if ($searchQuery !== '') {
    $sql .= " AND (p.name LIKE :search OR u.name LIKE :search OR fe.description LIKE :search)";
    $params['search'] = '%' . $searchQuery . '%';
}

$sql .= " ORDER BY fe.created_at DESC LIMIT 100";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular totais
$totalIncome = 0;
$totalExpense = 0;
foreach ($entries as $entry) {
    if ($entry['entry_type'] === 'income') {
        $totalIncome += (float)$entry['amount'];
    } else {
        $totalExpense += (float)$entry['amount'];
    }
}
$balance = $totalIncome - $totalExpense;

view_header('Lançamentos Financeiros');

echo '<div class="grid">';

// Cabeçalho
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Lançamentos Financeiros</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Receitas e despesas do sistema de faturamento</div>';
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
echo '<h3>Lançamentos (' . count($entries) . ')</h3>';

if (count($entries) === 0) {
    echo '<div style="padding:40px;text-align:center;color:#667781">Nenhum lançamento encontrado</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Data</th>';
    echo '<th>Tipo</th>';
    echo '<th>Categoria</th>';
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
        echo '<td>' . h($entry['patient_name'] ?? '-') . '</td>';
        echo '<td>' . h($entry['professional_name'] ?? '-') . '</td>';
        echo '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . h($entry['description'] ?? '-') . '</td>';
        echo '<td style="text-align:right;font-weight:600;color:' . $typeColor . '">R$ ' . number_format((float)$entry['amount'], 2, ',', '.') . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:600">' . h($entry['status']) . '</span></td>';
        echo '<td>' . h($entry['created_by_name'] ?? '-') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
