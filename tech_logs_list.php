<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('tech_logs.view');

$provider = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';
$status = isset($_GET['status']) ? (string)$_GET['status'] : '';

if (!in_array($status, ['', 'success', 'error'], true)) {
    $status = '';
}

$sql = 'SELECT id, provider, action, status, http_status, error_message, attempts, created_at
        FROM integration_logs
        WHERE 1=1';
$params = [];

if ($provider !== '') {
    $sql .= ' AND provider LIKE :p';
    $params['p'] = '%' . $provider . '%';
}

if ($status !== '') {
    $sql .= ' AND status = :s';
    $params['s'] = $status;
}

$sql .= ' ORDER BY id DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Logs Técnicos');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Logs Técnicos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Integrações (OpenAI/Evolution/ZapSign/SMTP/Webhooks).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/integration_jobs_list.php">Jobs</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/tech_logs_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="provider" value="' . h($provider) . '" placeholder="Provider" style="flex:1;min-width:220px">';
echo '<select name="status" style="min-width:200px">';
echo '<option value=""' . ($status === '' ? ' selected' : '') . '>Todos</option>';
echo '<option value="success"' . ($status === 'success' ? ' selected' : '') . '>success</option>';
echo '<option value="error"' . ($status === 'error' ? ' selected' : '') . '>error</option>';
echo '</select>';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Provider</th><th>Ação</th><th>Status</th><th>HTTP</th><th>Tentativas</th><th>Erro</th><th>Quando</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['provider']) . '</td>';
    echo '<td>' . h((string)$r['action']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)($r['http_status'] ?? '')) . '</td>';
    echo '<td>' . h((string)$r['attempts']) . '</td>';
    echo '<td>' . h(mb_strimwidth((string)($r['error_message'] ?? ''), 0, 80, '...')) . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/tech_logs_view.php?id=' . (int)$r['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="9" class="pill" style="display:table-cell;padding:12px">Sem logs.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
