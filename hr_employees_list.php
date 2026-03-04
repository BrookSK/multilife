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
echo '<div style="font-size:22px;font-weight:800">Funcionários (RH)</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Cadastro interno (stub para contratos/assinaturas no futuro).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/hr_employees_create.php">Novo funcionário</a>';
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/hr_employees_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<option value=""' . ($status === '' ? ' selected' : '') . '>Todos</option>';
echo '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>Ativos</option>';
echo '<option value="inactive"' . ($status === 'inactive' ? ' selected' : '') . '>Inativos</option>';
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar" style="flex:1;min-width:220px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Cargo</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['full_name']) . '</td>';
    echo '<td style="padding:12px">' . h((string)($r['email'] ?? '')) . '</td>';
    echo '<td style="padding:12px">' . h((string)($r['phone'] ?? '')) . '</td>';
    echo '<td style="padding:12px">' . h((string)($r['role_title'] ?? '')) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['status']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';
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
