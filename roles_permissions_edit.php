<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('roles.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT id, name, slug FROM roles WHERE id = :id');
$stmt->execute(['id' => $id]);
$role = $stmt->fetch();

if (!$role) {
    flash_set('error', 'Perfil não encontrado.');
    header('Location: /roles_list.php');
    exit;
}

$perms = db()->query('SELECT id, name, slug FROM permissions ORDER BY slug ASC')->fetchAll();

$stmt = db()->prepare('SELECT permission_id FROM role_permissions WHERE role_id = :rid');
$stmt->execute(['rid' => $id]);
$current = [];
foreach ($stmt->fetchAll() as $r) {
    $current[(int)$r['permission_id']] = true;
}

view_header('Permissões do perfil');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Permissões do perfil</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.5">' . h((string)$role['name']) . ' — ' . h((string)$role['slug']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/roles_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="post" action="/roles_permissions_edit_post.php" style="margin-top:14px;display:grid;gap:10px;max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$role['id'] . '">';
foreach ($perms as $perm) {
    $pid = (int)$perm['id'];
    $checked = isset($current[$pid]) ? ' checked' : '';
    echo '<label class="pill" style="display:flex;align-items:center;gap:10px;padding:10px 12px">';
    echo '<input type="checkbox" name="permission_ids[]" value="' . $pid . '"' . $checked . '> ';
    echo '<span><strong>' . h((string)$perm['slug']) . '</strong> <span style="color:rgba(234,240,255,.72)">— ' . h((string)$perm['name']) . '</span></span>';
    echo '</label>';
}

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/roles_list.php">Cancelar</a>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
