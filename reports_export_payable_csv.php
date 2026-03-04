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
    'SELECT ap.id, ap.appointment_id, ap.amount, ap.due_at, ap.status, ap.paid_at,
            u.name AS professional_name,
            a.first_at
     FROM finance_accounts_payable ap
     INNER JOIN appointments a ON a.id = ap.appointment_id
     INNER JOIN users u ON u.id = ap.professional_user_id
     ' . $wSql . '
     ORDER BY ap.id DESC'
);
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="accounts_payable.csv"');

$out = fopen('php://output', 'w');

fputcsv($out, ['id','appointment_id','amount','due_at','status','paid_at','first_at','professional_name']);
while ($r = $stmt->fetch()) {
    fputcsv($out, [
        (string)$r['id'],
        (string)$r['appointment_id'],
        (string)$r['amount'],
        (string)($r['due_at'] ?? ''),
        (string)$r['status'],
        (string)($r['paid_at'] ?? ''),
        (string)$r['first_at'],
        (string)$r['professional_name'],
    ]);
}

fclose($out);
exit;
