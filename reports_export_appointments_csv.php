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
    'SELECT a.id, a.first_at, a.recurrence_type, a.status, a.value_per_session,
            p.full_name AS patient_name,
            u.name AS professional_name
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     INNER JOIN users u ON u.id = a.professional_user_id
     ' . $wSql . '
     ORDER BY a.id DESC'
);
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="appointments.csv"');

$out = fopen('php://output', 'w');

fputcsv($out, ['id','first_at','recurrence_type','status','value_per_session','patient_name','professional_name']);
while ($r = $stmt->fetch()) {
    fputcsv($out, [
        (string)$r['id'],
        (string)$r['first_at'],
        (string)$r['recurrence_type'],
        (string)$r['status'],
        (string)$r['value_per_session'],
        (string)$r['patient_name'],
        (string)$r['professional_name'],
    ]);
}

fclose($out);
exit;
