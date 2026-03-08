<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowed = ['', 'pendente', 'recebido', 'inadimplente'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

// Contas a receber de atendimentos (patient_assignments)
$sql = 'SELECT pa.id, 
               COALESCE(pa.agreed_value, pa.payment_value, 0) as amount,
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
               pa.service_type
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN users u ON u.id = pa.professional_user_id
        WHERE p.deleted_at IS NULL';
$params = [];

if ($status !== '') {
    if ($status === 'recebido') {
        $sql .= ' AND pa.status = "paid"';
    } elseif ($status === 'pendente') {
        $sql .= ' AND pa.status IN ("approved", "completed", "confirmed")';
    }
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
                          fe.assignment_id as appointment_id, 
                          fe.created_at as first_at,
                          p.full_name AS patient_name,
                          u.name AS professional_name,
                          "financial_entry" as source,
                          fe.description,
                          fe.category as specialty,
                          fe.payment_type as service_type
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
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Geradas ao criar o agendamento.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/finance_entry_create.php?type=income">+ Nova Receita</a>';
echo '<a class="btn" href="/finance_entries_list.php">Ver Lançamentos</a>';
echo '<a class="btn" href="/finance_payable_list.php">Contas a Pagar</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/finance_receivable_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:220px">';
$labels = [
    '' => 'Todos',
    'pendente' => 'Pendente',
    'recebido' => 'Recebido',
    'inadimplente' => 'Inadimplente',
];
foreach ($labels as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (paciente/profissional/agendamento)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Agendamento</th><th>Data</th><th>Paciente</th><th>Profissional</th><th>Valor</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>#' . (int)$r['appointment_id'] . '</td>';
    echo '<td>' . h((string)$r['first_at']) . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['patient_name']) . '</td>';
    echo '<td>' . h((string)$r['professional_name']) . '</td>';
    echo '<td>' . h((string)$r['amount']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td style="text-align:right">';

    echo '<form method="post" action="/finance_receivable_set_status_post.php" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<select name="status" style="min-width:160px">';
    foreach (['pendente','recebido','inadimplente'] as $st) {
        $sel = ((string)$r['status'] === $st) ? ' selected' : '';
        echo '<option value="' . h($st) . '"' . $sel . '>' . h($st) . '</option>';
    }
    echo '</select>';
    echo '<button class="btn" type="submit" style="height:34px">Salvar</button>';
    echo '</form>';

    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="8" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
