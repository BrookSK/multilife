<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status = isset($_GET['status']) ? (string)$_GET['status'] : '';

if (!in_array($status, ['', 'active', 'inactive'], true)) {
    $status = '';
}

$sql = 'SELECT id, full_name, email, phone, role_title, status, created_at FROM hr_employees WHERE 1=1';
$params = [];

if ($status !== '') {
    $sql .= ' AND status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $sql .= ' AND (full_name LIKE :q OR email LIKE :q OR phone LIKE :q OR role_title LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('RH - Funcionários');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Funcionários (RH)</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastro interno (stub para contratos/assinaturas no futuro).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/hr_employees_create.php">Novo funcionário</a>';
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/hr_employees_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:220px">';
echo '<option value=""' . ($status === '' ? ' selected' : '') . '>Todos</option>';
echo '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>Ativos</option>';
echo '<option value="inactive"' . ($status === 'inactive' ? ' selected' : '') . '>Inativos</option>';
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar" style="flex:1;min-width:220px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Cargo</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['full_name']) . '</td>';
    echo '<td>' . h((string)($r['email'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['phone'] ?? '')) . '</td>';
    echo '<td>' . h((string)($r['role_title'] ?? '')) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/hr_employees_edit.php?id=' . (int)$r['id'] . '">Editar</a> ';
    echo '<form method="post" action="/hr_employees_delete_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Excluir este funcionário?\')">Excluir</button>';
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
