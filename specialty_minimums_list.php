<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$sql = 'SELECT id, specialty, minimum_value, status, created_at FROM specialty_minimums';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE specialty LIKE :q';
    $params['q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY specialty ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Mínimos por Especialidade');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Valor mínimo por especialidade</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Usado para bloquear agendamentos abaixo do mínimo e solicitar autorização do Admin.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/specialty_minimums_create.php">Novo</a>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/specialty_minimums_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar especialidade" style="flex:1;min-width:260px">';
echo '<button class="btn" type="submit">Buscar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr><th>Especialidade</th><th>Mínimo</th><th>Status</th><th>Criado</th><th style="text-align:right">Ações</th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td style="font-weight:700">' . h((string)$r['specialty']) . '</td>';
    echo '<td>' . h((string)$r['minimum_value']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/specialty_minimums_edit.php?id=' . (int)$r['id'] . '">Editar</a> ';
    echo '<form method="post" action="/specialty_minimums_delete_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Inativar este mínimo?\')">Inativar</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
