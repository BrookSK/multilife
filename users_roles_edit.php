<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /users_list.php');
    exit;
}

$roles = db()->query('SELECT id, name, slug FROM roles ORDER BY name ASC')->fetchAll();

$stmt = db()->prepare('SELECT role_id FROM user_roles WHERE user_id = :uid');
$stmt->execute(['uid' => $id]);
$current = [];
foreach ($stmt->fetchAll() as $r) {
    $current[(int)$r['role_id']] = true;
}

view_header('Perfis do usuário');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Perfis do usuário</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">' . h((string)$user['name']) . ' — ' . h((string)$user['email']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/users_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/users_roles_edit_post.php" style="display:grid;gap:10px;max-width:680px">';
echo '<input type="hidden" name="id" value="' . (int)$user['id'] . '">';
foreach ($roles as $role) {
    $rid = (int)$role['id'];
    $checked = isset($current[$rid]) ? ' checked' : '';
    echo '<label class="pill" style="display:flex;align-items:center;gap:10px;padding:10px 12px">';
    echo '<input type="checkbox" name="role_ids[]" value="' . $rid . '"' . $checked . '> ';
    echo '<span><strong>' . h((string)$role['name']) . '</strong> <span style="color:hsl(var(--muted-foreground))">(' . h((string)$role['slug']) . ')</span></span>';
    echo '</label>';
}

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/users_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
