<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

// Buscar especialidades
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT id, name, email, phone, specialty, status FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /users_list.php');
    exit;
}

view_header('Editar usuário');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar usuário</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualize dados e senha (opcional).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/users_list.php">Voltar</a>';
echo '<a class="btn" href="/users_roles_edit.php?id=' . (int)$user['id'] . '">Perfis</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/users_edit_post.php" style="display:grid;gap:12px;max-width:680px">';
echo '<input type="hidden" name="id" value="' . (int)$user['id'] . '">';
echo '<label>Nome<input name="name" required value="' . h((string)$user['name']) . '" placeholder="Nome"></label>';
echo '<label>E-mail<input type="email" name="email" required value="' . h((string)$user['email']) . '" placeholder="email@empresa.com"></label>';
echo '<label>Telefone (para WhatsApp/Evolution)<input name="phone" maxlength="30" value="' . h((string)($user['phone'] ?? '')) . '" placeholder="5511999999999"></label>';
echo '<label>Especialidade (para profissionais)<select name="specialty">';
echo '<option value="">Nenhuma / Não é profissional</option>';
foreach ($specialties as $spec) {
    $selected = ((string)($user['specialty'] ?? '') === (string)$spec['name']) ? ' selected' : '';
    echo '<option value="' . h((string)$spec['name']) . '"' . $selected . '>' . h((string)$spec['name']) . '</option>';
}
echo '</select></label>';
echo '<label>Nova senha (opcional)<input type="password" name="password" minlength="8" placeholder="Deixe em branco para manter"></label>';
echo '<label>Status<select name="status">';
$st = (string)$user['status'];
echo '<option value="active"' . ($st === 'active' ? ' selected' : '') . '>active</option>';
echo '<option value="inactive"' . ($st === 'inactive' ? ' selected' : '') . '>inactive</option>';
echo '</select></label>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/users_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
