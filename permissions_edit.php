<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('permissions.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT id, name, slug FROM permissions WHERE id = :id');
$stmt->execute(['id' => $id]);
$perm = $stmt->fetch();

if (!$perm) {
    flash_set('error', 'Permissão não encontrada.');
    header('Location: /permissions_list.php');
    exit;
}

view_header('Editar permissão');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar permissão</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualize os dados da permissão.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/permissions_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/permissions_edit_post.php" style="display:grid;gap:12px;max-width:680px">';
echo '<input type="hidden" name="id" value="' . (int)$perm['id'] . '">';
echo '<label>Nome<input name="name" required value="' . h((string)$perm['name']) . '" placeholder="Nome"></label>';
echo '<label>Slug<input name="slug" required value="' . h((string)$perm['slug']) . '" placeholder="ex: users.manage"></label>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/permissions_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
