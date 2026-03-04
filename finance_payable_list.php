<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowed = ['', 'pendente', 'pago'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$sql = 'SELECT ap.id, ap.amount, ap.due_at, ap.status, ap.paid_at,
               a.id AS appointment_id, a.first_at,
               u.name AS professional_name
        FROM finance_accounts_payable ap
        INNER JOIN appointments a ON a.id = ap.appointment_id
        INNER JOIN users u ON u.id = ap.professional_user_id
        ORDER BY ap.id DESC';

$where = [];
$params = [];

if ($status !== '') {
    $where[] = 'ap.status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $where[] = '(u.name LIKE :q OR ap.appointment_id LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

if (count($where) > 0) {
    $sql = str_replace('ORDER BY', 'WHERE ' . implode(' AND ', $where) . ' ORDER BY', $sql);
}

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Financeiro - Contas a Pagar');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Contas a Pagar (Repasse)</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Geradas quando a documentação é aprovada.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/finance_receivable_list.php">Contas a Receber</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/finance_payable_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:220px">';
$labels = [
    '' => 'Todos',
    'pendente' => 'Pendente',
    'pago' => 'Pago',
];
foreach ($labels as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (profissional/agendamento)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Agendamento</th><th>Data</th><th>Profissional</th><th>Valor</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>#' . (int)$r['appointment_id'] . '</td>';
    echo '<td>' . h((string)$r['first_at']) . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['professional_name']) . '</td>';
    echo '<td>' . h((string)$r['amount']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td style="text-align:right">';

    echo '<form method="post" action="/finance_payable_mark_paid_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    $disabled = ((string)$r['status'] === 'pago') ? ' disabled' : '';
    echo '<button class="btn" type="submit" style="height:34px"' . $disabled . '>Marcar como pago</button>';
    echo '</form>';

    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="7" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
