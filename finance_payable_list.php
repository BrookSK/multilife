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

// Definir status baseado na aba
$status = ($tab === 'pendentes') ? 'pendente' : 'pago';

// Contas a pagar de atendimentos (patient_assignments)
$sql = 'SELECT pa.id, 
               pa.agreed_value as amount,
               pa.created_at as due_at,
               CASE 
                   WHEN pa.status = "paid" THEN "pago"
                   WHEN pa.status IN ("approved", "completed") THEN "pendente"
                   ELSE "pendente"
               END as status,
               NULL as paid_at,
               pa.id AS appointment_id,
               pa.created_at as first_at,
               u.name AS professional_name,
               "patient_assignment" as source,
               pa.specialty,
               pa.service_type,
               p.full_name as patient_name,
               "Fluxo Operacional" as cost_center,
               COALESCE(hi.name, "Não informado") as operadora
        FROM patient_assignments pa
        LEFT JOIN users u ON u.id = pa.professional_user_id
        LEFT JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
        WHERE pa.agreed_value IS NOT NULL AND pa.agreed_value > 0';

$params = [];

if ($status === 'pago') {
    $sql .= ' AND pa.status = "paid"';
} elseif ($status === 'pendente') {
    $sql .= ' AND pa.status IN ("approved", "completed", "confirmed")';
}

if ($q !== '') {
    $sql .= ' AND (u.name LIKE :q OR pa.specialty LIKE :q OR p.full_name LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY pa.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Despesas de lançamentos financeiros manuais
$sqlFaturamento = 'SELECT fe.id, fe.amount, 
                          COALESCE(fe.due_date, fe.entry_date) as due_at, 
                          CASE 
                              WHEN fe.status = "paid" THEN "pago"
                              WHEN fe.status = "pending" THEN "pendente"
                              ELSE "pendente"
                          END as status, 
                          fe.paid_date as paid_at,
                          COALESCE(fe.assignment_id, 0) as appointment_id, 
                          fe.created_at as first_at,
                          COALESCE(u.name, "-") AS professional_name,
                          "financial_entry" as source,
                          fe.description,
                          fe.category as specialty,
                          fe.payment_type as service_type,
                          COALESCE(fe.supplier_name, "-") as patient_name,
                          COALESCE(fe.cost_center, "-") as cost_center,
                          NULL as operadora
                   FROM financial_entries fe
                   LEFT JOIN users u ON u.id = fe.professional_user_id
                   WHERE fe.entry_type = "expense" AND fe.is_active = 1';

$paramsFat = [];

if ($status !== '') {
    // Mapear status: pending/paid para pendente/pago
    $statusMap = ['pendente' => 'pending', 'pago' => 'paid'];
    if (isset($statusMap[$status])) {
        $sqlFaturamento .= ' AND fe.status = :status';
        $paramsFat['status'] = $statusMap[$status];
    }
}

if ($q !== '') {
    $sqlFaturamento .= ' AND (u.name LIKE :q OR fe.description LIKE :q)';
    $paramsFat['q'] = '%' . $q . '%';
}

$sqlFaturamento .= ' ORDER BY fe.id DESC';

$stmtFat = db()->prepare($sqlFaturamento);
$stmtFat->execute($paramsFat);
$rowsFaturamento = $stmtFat->fetchAll();

// Combinar ambos os arrays
$rows = array_merge($rows, $rowsFaturamento);

// Calcular resumo financeiro
$totalPendente = 0;
$totalPago = 0;
$qtdPendente = 0;
$qtdPago = 0;

foreach ($rows as $r) {
    $valor = (float)$r['amount'];
    if ((string)$r['status'] === 'pendente') {
        $totalPendente += $valor;
        $qtdPendente++;
    } elseif ((string)$r['status'] === 'pago') {
        $totalPago += $valor;
        $qtdPago++;
    }
}

$totalGeral = $totalPendente + $totalPago;
$qtdGeral = $qtdPendente + $qtdPago;

view_header('Financeiro - Contas a Pagar');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Contas a Pagar</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gerencie suas despesas e pagamentos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/finance_entry_create.php?type=expense">+ Nova Despesa</a>';
echo '<a class="btn" href="/finance_entries_list.php">Ver Lançamentos</a>';
echo '<a class="btn" href="/finance_receivable_list.php">Contas a Receber</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

// Sistema de abas
echo '<div style="margin-top:20px;border-bottom:2px solid hsl(var(--border))">';
echo '<div style="display:flex;gap:4px">';

$tabs = [
    'pendentes' => ['label' => 'Pendentes', 'icon' => '⏳'],
    'historico' => ['label' => 'Histórico', 'icon' => '📋'],
];

foreach ($tabs as $tabKey => $tabInfo) {
    $isActive = ($tab === $tabKey);
    $activeStyle = $isActive 
        ? 'background:hsl(var(--primary));color:white;border-bottom:3px solid hsl(var(--primary))' 
        : 'background:transparent;color:hsl(var(--foreground));border-bottom:3px solid transparent';
    
    $queryParams = ['tab' => $tabKey];
    if ($q !== '') {
        $queryParams['q'] = $q;
    }
    
    echo '<a href="/finance_payable_list.php?' . http_build_query($queryParams) . '" ';
    echo 'style="padding:12px 24px;text-decoration:none;font-weight:600;font-size:15px;transition:all 0.2s;' . $activeStyle . '">';
    echo $tabInfo['icon'] . ' ' . h($tabInfo['label']);
    echo '</a>';
}

echo '</div>';
echo '</div>';

// Formulário de busca
echo '<form method="get" action="/finance_payable_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (fornecedor/descrição/categoria)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Buscar</button>';
if ($q !== '') {
    echo '<a class="btn" href="/finance_payable_list.php?tab=' . h($tab) . '">Limpar</a>';
}
echo '</form>';

echo '</section>';

// Dashboard de resumo financeiro
echo '<section class="card col12">';
echo '<div style="font-size:16px;font-weight:700;margin-bottom:16px">📊 Resumo Financeiro</div>';
echo '<div class="grid">';

// Card: Total Geral
echo '<div class="col3">';
echo '<div style="padding:20px;background:linear-gradient(135deg,hsl(var(--success)),hsl(var(--success)/.8));border-radius:12px;color:white">';
echo '<div style="font-size:13px;opacity:.9;margin-bottom:8px">💰 Total Geral</div>';
echo '<div style="font-size:28px;font-weight:900">R$ ' . number_format($totalGeral, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:.8;margin-top:8px">' . $qtdGeral . ' registro(s)</div>';
echo '</div>';
echo '</div>';

// Card: Pendente
echo '<div class="col3">';
echo '<div style="padding:20px;background:linear-gradient(135deg,hsl(var(--success)),hsl(var(--success)/.8));border-radius:12px;color:white">';
echo '<div style="font-size:13px;opacity:.9;margin-bottom:8px">⏳ Pendente</div>';
echo '<div style="font-size:28px;font-weight:900">R$ ' . number_format($totalPendente, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:.8;margin-top:8px">' . $qtdPendente . ' registro(s)</div>';
echo '</div>';
echo '</div>';

// Card: Pago
echo '<div class="col3">';
echo '<div style="padding:20px;background:linear-gradient(135deg,hsl(var(--success)),hsl(var(--success)/.8));border-radius:12px;color:white">';
echo '<div style="font-size:13px;opacity:.9;margin-bottom:8px">✅ Pago</div>';
echo '<div style="font-size:28px;font-weight:900">R$ ' . number_format($totalPago, 2, ',', '.') . '</div>';
echo '<div style="font-size:12px;opacity:.8;margin-top:8px">' . $qtdPago . ' registro(s)</div>';
echo '</div>';
echo '</div>';

// Card: Taxa de Pagamento
$taxaPagamento = $totalGeral > 0 ? ($totalPago / $totalGeral) * 100 : 0;
echo '<div class="col3">';
echo '<div style="padding:20px;background:linear-gradient(135deg,hsl(var(--success)),hsl(var(--success)/.8));border-radius:12px;color:white">';
echo '<div style="font-size:13px;opacity:.9;margin-bottom:8px">📈 Taxa de Pagamento</div>';
echo '<div style="font-size:28px;font-weight:900">' . number_format($taxaPagamento, 1) . '%</div>';
echo '<div style="font-size:12px;opacity:.8;margin-top:8px">Pago / Total</div>';
echo '</div>';
echo '</div>';

echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Agendamento</th><th>Data</th><th>Fornecedor</th><th>Operadora</th><th>Ligação</th><th>Centro de Custo</th><th>Valor</th><th>Status</th><th style="text-align:right">Ações</th>';
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
    
    // Fornecedor: Profissional para atendimentos, "Não aplicável" para lançamentos manuais
    $fornecedorDisplay = 'Não aplicável';
    if ((string)$r['source'] === 'patient_assignment' && !empty($r['professional_name'])) {
        $fornecedorDisplay = 'Profissional - ' . h((string)$r['professional_name']);
    } elseif ((string)$r['source'] === 'financial_entry' && !empty($r['patient_name']) && $r['patient_name'] !== '-') {
        // Lançamento manual com fornecedor informado
        $fornecedorDisplay = h((string)$r['patient_name']);
    }
    echo '<td>' . $fornecedorDisplay . '</td>';
    
    // Operadora: mostrar operadora para atendimentos, "Não aplicável" para lançamentos manuais
    $operadoraDisplay = 'Não aplicável';
    if ((string)$r['source'] === 'patient_assignment' && !empty($r['operadora'])) {
        $operadoraDisplay = h((string)$r['operadora']);
    }
    echo '<td>' . $operadoraDisplay . '</td>';
    
    // Ligação: Paciente para atendimentos, Categoria para lançamentos manuais
    $ligacao = '-';
    if ((string)$r['source'] === 'financial_entry') {
        // Lançamento manual: mostrar categoria
        $ligacao = h((string)($r['specialty'] ?? 'Sem categoria'));
    } elseif ((string)$r['source'] === 'patient_assignment' && !empty($r['patient_name'])) {
        // Atendimento: mostrar paciente
        $ligacao = 'Paciente - ' . h((string)$r['patient_name']);
    }
    echo '<td>' . $ligacao . '</td>';
    
    // Centro de Custo: "Não selecionado" quando vazio
    $costCenterDisplay = h((string)($r['cost_center'] ?? ''));
    if (empty($costCenterDisplay) || $costCenterDisplay === '-') {
        $costCenterDisplay = 'Não selecionado';
    }
    echo '<td>' . $costCenterDisplay . '</td>';
    echo '<td style="font-weight:600;color:#dc2626">R$ ' . number_format((float)$r['amount'], 2, ',', '.') . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td style="text-align:right">';

    // Só permitir marcar como pago lançamentos financeiros manuais na aba de pendentes
    if ($tab === 'pendentes' && (string)$r['source'] === 'financial_entry' && (string)$r['status'] === 'pendente') {
        echo '<form method="post" action="/finance_payable_mark_paid_post.php" style="display:inline">';
        echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
        echo '<button class="btn" type="submit" style="height:34px">Marcar como pago</button>';
        echo '</form>';
    } elseif ($tab === 'historico' && !empty($r['paid_at'])) {
        // Mostrar data de pagamento no histórico
        echo '<span style="font-size:13px;color:hsl(var(--muted-foreground))">Pago em ' . date('d/m/Y', strtotime($r['paid_at'])) . '</span>';
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
echo '</section>';

echo '</div>';

view_footer();
