<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT id, name, email, status FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /users_list.php');
    exit;
}

view_header('Editar usuário');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Editar usuário</div>';

echo '<form method="post" action="/users_edit_post.php" style="display:grid;gap:12px;max-width:560px">';
echo '<input type="hidden" name="id" value="' . (int)$user['id'] . '">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome<input name="name" required value="' . h((string)$user['name']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">E-mail<input type="email" name="email" required value="' . h((string)$user['email']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nova senha (opcional)<input type="password" name="password" minlength="8" placeholder="Deixe em branco para manter" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Status<select name="status" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
$st = (string)$user['status'];
echo '<option value="active"' . ($st === 'active' ? ' selected' : '') . '>active</option>';
echo '<option value="inactive"' . ($st === 'inactive' ? ' selected' : '') . '>inactive</option>';
echo '</select></label>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/users_list.php">Cancelar</a>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
