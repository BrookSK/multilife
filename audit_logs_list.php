<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('audit.view');

$module = isset($_GET['module']) ? trim((string)$_GET['module']) : '';
$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$userId = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';
$dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}
if ($userId !== '' && !ctype_digit($userId)) {
    $userId = '';
}

$sql = 'SELECT a.id, a.created_at, a.action, a.module, a.record_id, a.ip, a.user_id,
               u.name AS user_name, u.email AS user_email
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE 1=1';
$params = [];

if ($module !== '') {
    $sql .= ' AND a.module LIKE :module';
    $params['module'] = '%' . $module . '%';
}
if ($action !== '') {
    $sql .= ' AND a.action = :action';
    $params['action'] = $action;
}
if ($userId !== '') {
    $sql .= ' AND a.user_id = :uid';
    $params['uid'] = (int)$userId;
}
if ($dateFrom !== '') {
    $sql .= ' AND a.created_at >= :df';
    $params['df'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $sql .= ' AND a.created_at <= :dt';
    $params['dt'] = $dateTo . ' 23:59:59';
}
if ($q !== '') {
    $sql .= ' AND (a.module LIKE :q OR a.record_id LIKE :q OR a.ip LIKE :q OR CAST(a.old_data AS CHAR) LIKE :q OR CAST(a.new_data AS CHAR) LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY a.id DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$modules = db()->query('SELECT DISTINCT module FROM audit_logs ORDER BY module ASC LIMIT 200')->fetchAll();
$actions = db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC LIMIT 50')->fetchAll();
$users = db()->query("SELECT id, name, email FROM users WHERE status = 'active' ORDER BY name ASC LIMIT 300")->fetchAll();

view_header('Auditoria');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Auditoria</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Logs de ações do sistema.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/audit_logs_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';

echo '<select name="module" style="min-width:220px">';
echo '<option value="">Módulo (todos)</option>';
foreach ($modules as $m) {
    $val = (string)$m['module'];
    $sel = ($module === $val) ? ' selected' : '';
    echo '<option value="' . h($val) . '"' . $sel . '>' . h($val) . '</option>';
}
echo '</select>';

echo '<select name="action" style="min-width:160px">';
echo '<option value="">Ação (todas)</option>';
foreach ($actions as $a) {
    $val = (string)$a['action'];
    $sel = ($action === $val) ? ' selected' : '';
    echo '<option value="' . h($val) . '"' . $sel . '>' . h($val) . '</option>';
}
echo '</select>';

echo '<select name="user_id" style="min-width:240px">';
echo '<option value="">Usuário (todos)</option>';
foreach ($users as $u) {
    $val = (string)$u['id'];
    $label = (string)$u['name'] . ' — ' . (string)$u['email'];
    $sel = ($userId === $val) ? ' selected' : '';
    echo '<option value="' . h($val) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';

echo '<input type="date" name="date_from" value="' . h($dateFrom) . '" style="width:160px">';
echo '<input type="date" name="date_to" value="' . h($dateTo) . '" style="width:160px">';

echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (id, ip, JSON...)" style="flex:1;min-width:220px">';

echo '<button class="btn" type="submit">Filtrar</button>';

echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Data</th><th>Ação</th><th>Módulo</th><th>Registro</th><th>Usuário</th><th>IP</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $userTxt = '-';
    if ($r['user_id'] !== null) {
        $userTxt = (string)($r['user_name'] ?? 'Usuário') . ' (#' . (int)$r['user_id'] . ')';
    }

    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';
    echo '<td>' . h((string)$r['action']) . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['module']) . '</td>';
    echo '<td>' . h((string)($r['record_id'] ?? '')) . '</td>';
    echo '<td>' . h($userTxt) . '</td>';
    echo '<td>' . h((string)($r['ip'] ?? '')) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/audit_logs_view.php?id=' . (int)$r['id'] . '">Ver</a>';
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
