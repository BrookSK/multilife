<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('permissions.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$sql = 'SELECT id, name, slug, created_at FROM permissions';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE name LIKE :q OR slug LIKE :q';
    $params['q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Permissões');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Permissões</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.5">Gerencie permissões (ex: users.manage).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/permissions_create.php">Nova permissão</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/permissions_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por nome ou slug" style="flex:1;min-width:220px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Buscar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Nome</th><th>Slug</th><th>Criado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead>';
echo '<tbody>';
foreach ($rows as $r) {
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['name']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['slug']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['created_at']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';
    echo '<a class="btn" href="/permissions_edit.php?id=' . (int)$r['id'] . '">Editar</a> ';
    echo '<form method="post" action="/permissions_delete_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Excluir esta permissão?\')">Excluir</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
