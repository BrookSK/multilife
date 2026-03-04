<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('reports.view');

$from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$where = [];
$params = [];
if ($from !== '') {
    $where[] = 'a.first_at >= :fromd';
    $params['fromd'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'a.first_at <= :tod';
    $params['tod'] = $to . ' 23:59:59';
}

$wSql = (count($where) > 0) ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = db()->prepare(
    'SELECT COUNT(*) c FROM appointments a ' . $wSql
);
$stmt->execute($params);
$kpiAppointments = (int)$stmt->fetch()['c'];

$stmt = db()->prepare(
    'SELECT COALESCE(SUM(ar.amount),0) s FROM finance_accounts_receivable ar INNER JOIN appointments a ON a.id = ar.appointment_id ' . $wSql
);
$stmt->execute($params);
$kpiArTotal = (string)$stmt->fetch()['s'];

$stmt = db()->prepare(
    "SELECT COALESCE(SUM(ar.amount),0) s FROM finance_accounts_receivable ar INNER JOIN appointments a ON a.id = ar.appointment_id $wSql AND ar.status = 'pendente'"
);
$stmt->execute($params);
$kpiArPending = (string)$stmt->fetch()['s'];

$stmt = db()->prepare(
    'SELECT COALESCE(SUM(ap.amount),0) s FROM finance_accounts_payable ap INNER JOIN appointments a ON a.id = ap.appointment_id ' . $wSql
);
$stmt->execute($params);
$kpiApTotal = (string)$stmt->fetch()['s'];

view_header('Relatórios');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Relatórios e BI</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">KPIs e exportações CSV.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/reports_dashboard.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="date" name="from" value="' . h($from) . '" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<input type="date" name="to" value="' . h($to) . '" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Aplicar</button>';
echo '</form>';

echo '</section>';

$cards = [
    ['Agendamentos', $kpiAppointments],
    ['Recebíveis (total)', $kpiArTotal],
    ['Recebíveis (pendente)', $kpiArPending],
    ['Repasses (total)', $kpiApTotal],
];

echo '<section class="card col12">';
echo '<div class="grid">';
foreach ($cards as $c) {
    echo '<div class="col6">';
    echo '<div class="pill" style="display:block;padding:14px">';
    echo '<div style="font-size:12px;color:rgba(234,240,255,.72)">' . h((string)$c[0]) . '</div>';
    echo '<div style="font-size:28px;font-weight:900;margin-top:6px">' . h((string)$c[1]) . '</div>';
    echo '</div>';
    echo '</div>';
}
echo '</div>';
echo '</section>';


echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Exportações CSV</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';

echo '<a class="btn" href="/reports_export_appointments_csv.php?from=' . urlencode($from) . '&to=' . urlencode($to) . '">Agendamentos</a>';
echo '<a class="btn" href="/reports_export_receivable_csv.php?from=' . urlencode($from) . '&to=' . urlencode($to) . '">Contas a Receber</a>';
echo '<a class="btn" href="/reports_export_payable_csv.php?from=' . urlencode($from) . '&to=' . urlencode($to) . '">Contas a Pagar</a>';

echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
