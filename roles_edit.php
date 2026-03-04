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

view_header('Editar perfil');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Editar perfil</div>';

echo '<form method="post" action="/roles_edit_post.php" style="display:grid;gap:12px;max-width:560px">';
echo '<input type="hidden" name="id" value="' . (int)$role['id'] . '">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome<input name="name" required value="' . h((string)$role['name']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Slug<input name="slug" required value="' . h((string)$role['slug']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/roles_list.php">Cancelar</a>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
