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
$status = ($tab === 'pendentes') ? 'pendente' : 'recebido';

// Contas a receber de atendimentos (patient_assignments)
$sql = 'SELECT pa.id, 
               pa.authorized_value as amount,
               pa.created_at as due_at,
               CASE 
                   WHEN pa.status = "paid" THEN "recebido"
                   WHEN pa.status IN ("approved", "completed") THEN "pendente"
                   ELSE "pendente"
               END as status,
               NULL as received_at,
               pa.id AS appointment_id,
               pa.created_at as first_at,
               p.full_name AS patient_name,
               u.name AS professional_name,
               "patient_assignment" as source,
               pa.specialty,
               pa.service_type,
               NULL as category,
               "Fluxo Operacional" as cost_center,
               COALESCE(hi.name, "Não informado") as operadora
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN users u ON u.id = pa.professional_user_id
        LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
        WHERE p.deleted_at IS NULL AND pa.authorized_value IS NOT NULL AND pa.authorized_value > 0';
$params = [];

if ($status === 'recebido') {
    $sql .= ' AND pa.status = "paid"';
} elseif ($status === 'pendente') {
    $sql .= ' AND pa.status IN ("approved", "completed", "confirmed")';
}

if ($q !== '') {
    $sql .= ' AND (p.full_name LIKE :q OR u.name LIKE :q OR pa.specialty LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY pa.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Receitas de lançamentos financeiros manuais
$sqlFaturamento = 'SELECT fe.id, fe.amount, 
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
                          u.name AS professional_name,
                          "financial_entry" as source,
                          fe.description,
                          NULL as specialty,
                          fe.payment_type as service_type,
                          fe.category,
                          COALESCE(fe.cost_center, "-") as cost_center,
                          NULL as operadora
                   FROM financial_entries fe
                   LEFT JOIN patients p ON p.id = fe.patient_id
                   LEFT JOIN users u ON u.id = fe.professional_user_id
                   WHERE fe.entry_type = "income" AND fe.is_active = 1';

$paramsFat = [];

if ($status !== '') {
    // Mapear status: pending/paid para pendente/recebido
    $statusMap = ['pendente' => 'pending', 'recebido' => 'paid'];
    if (isset($statusMap[$status])) {
        $sqlFaturamento .= ' AND fe.status = :status';
        $paramsFat['status'] = $statusMap[$status];
    }
}

if ($q !== '') {
    $sqlFaturamento .= ' AND (p.full_name LIKE :q OR u.name LIKE :q OR fe.description LIKE :q)';
    $paramsFat['q'] = '%' . $q . '%';
}

$sqlFaturamento .= ' ORDER BY fe.id DESC';

$stmtFat = db()->prepare($sqlFaturamento);
$stmtFat->execute($paramsFat);
$rowsFaturamento = $stmtFat->fetchAll();

// Combinar ambos os arrays
$rows = array_merge($rows, $rowsFaturamento);

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
    
    echo '<a href="/finance_receivable_list.php?' . http_build_query($queryParams) . '" ';
    echo 'style="padding:12px 24px;text-decoration:none;font-weight:600;font-size:15px;transition:all 0.2s;' . $activeStyle . '">';
    echo $tabInfo['icon'] . ' ' . h($tabInfo['label']);
    echo '</a>';
}

echo '</div>';
echo '</div>';

// Formulário de busca
echo '<form method="get" action="/finance_receivable_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (paciente/descrição/categoria)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Buscar</button>';
if ($q !== '') {
    echo '<a class="btn" href="/finance_receivable_list.php?tab=' . h($tab) . '">Limpar</a>';
}
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Agendamento</th><th>Data</th><th>Paciente</th><th>Operadora</th><th>Ligação</th><th>Centro de Custo</th><th>Valor</th><th>Status</th><th style="text-align:right">Ações</th>';
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
    
    // Operadora: mostrar operadora para atendimentos, "Não aplicável" para lançamentos manuais
    $operadoraDisplay = 'Não aplicável';
    if ((string)$r['source'] === 'patient_assignment' && !empty($r['operadora'])) {
        $operadoraDisplay = h((string)$r['operadora']);
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
echo '</section>';

echo '</div>';

view_footer();
