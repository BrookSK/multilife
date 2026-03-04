<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('backups.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
if (!in_array($status, ['', 'running', 'success', 'error'], true)) {
    $status = '';
}

$sql = 'SELECT id, kind, status, started_at, finished_at, output_path, error_message
        FROM backup_runs';
$params = [];
if ($status !== '') {
    $sql .= ' WHERE status = :s';
    $params['s'] = $status;
}
$sql .= ' ORDER BY id DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Backups');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Backups</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Registro de execuções (stub). Não executa dump real ainda.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/patient_access_logs_list.php">Acessos</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="post" action="/backup_runs_run_post.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="kind" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<option value="db">db</option>';
echo '<option value="files">files</option>';
echo '</select>';
echo '<button class="btn btnPrimary" type="submit">Registrar execução</button>';
echo '</form>';

echo '<form method="get" action="/backup_runs_list.php" style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<option value=""' . ($status === '' ? ' selected' : '') . '>Todos</option>';
echo '<option value="running"' . ($status === 'running' ? ' selected' : '') . '>running</option>';
echo '<option value="success"' . ($status === 'success' ? ' selected' : '') . '>success</option>';
echo '<option value="error"' . ($status === 'error' ? ' selected' : '') . '>error</option>';
echo '</select>';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Kind</th><th>Status</th><th>Início</th><th>Fim</th><th>Saída</th><th>Erro</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['kind']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['status']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['started_at']) . '</td>';
    echo '<td style="padding:12px">' . h((string)($r['finished_at'] ?? '')) . '</td>';
    echo '<td style="padding:12px">' . h((string)($r['output_path'] ?? '')) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px">' . h((string)($r['error_message'] ?? '')) . '</td>';
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
