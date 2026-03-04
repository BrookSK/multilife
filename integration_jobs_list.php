<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('integration_jobs.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'pending';
$provider = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';

$allowed = ['pending','running','success','error','dead',''];
if (!in_array($status, $allowed, true)) {
    $status = 'pending';
}

$sql = 'SELECT id, provider, action, status, attempts, max_attempts, next_run_at, last_run_at, last_error, created_at
        FROM integration_jobs
        WHERE 1=1';
$params = [];

if ($status !== '') {
    $sql .= ' AND status = :s';
    $params['s'] = $status;
}

if ($provider !== '') {
    $sql .= ' AND provider LIKE :p';
    $params['p'] = '%' . $provider . '%';
}

$sql .= ' ORDER BY id DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Jobs de Integração');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Jobs de Integração</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Fila para retentativas (até 3x) e reprocessamento manual.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/tech_logs_list.php">Logs</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/integration_jobs_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:220px">';
$labels = [
    '' => 'Todos',
    'pending' => 'pending',
    'running' => 'running',
    'success' => 'success',
    'error' => 'error',
    'dead' => 'dead',
];
foreach ($labels as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="provider" value="' . h($provider) . '" placeholder="Provider" style="flex:1;min-width:220px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Provider</th><th>Ação</th><th>Status</th><th>Tentativas</th><th>Próx. execução</th><th>Erro</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['provider']) . '</td>';
    echo '<td>' . h((string)$r['action']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)$r['attempts']) . '/' . h((string)$r['max_attempts']) . '</td>';
    echo '<td>' . h((string)($r['next_run_at'] ?? '')) . '</td>';
    echo '<td>' . h(mb_strimwidth((string)($r['last_error'] ?? ''), 0, 80, '...')) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/integration_jobs_view.php?id=' . (int)$r['id'] . '">Abrir</a> ';
    echo '<form method="post" action="/integration_jobs_run_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" style="height:34px">Rodar</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="8" class="pill" style="display:table-cell;padding:12px">Sem jobs.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
