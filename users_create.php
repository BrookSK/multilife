<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

// Buscar especialidades
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

// Buscar roles disponíveis
$rolesStmt = db()->query("SELECT id, slug, name FROM roles ORDER BY name ASC");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

// Parâmetros pré-preenchidos (vindos do chat)
$prefName = trim((string)($_GET['name'] ?? ''));
$prefPhone = trim((string)($_GET['phone'] ?? ''));
$prefRole = trim((string)($_GET['role'] ?? ''));

view_header('Novo usuário');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo usuário</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Crie um usuário e atribua perfis de acesso.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/users_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/users_create_post.php" style="display:grid;gap:12px;max-width:680px">';
echo '<label>Nome<input name="name" required placeholder="Nome" value="' . h($prefName) . '"></label>';
echo '<label>E-mail<input type="email" name="email" required placeholder="email@empresa.com"></label>';
echo '<label>Telefone (para WhatsApp/Evolution)<input name="phone" maxlength="30" placeholder="5511999999999" value="' . h($prefPhone) . '"></label>';
echo '<label>Especialidade (para profissionais)<select name="specialty">';
echo '<option value="">Nenhuma / Não é profissional</option>';
foreach ($specialties as $spec) {
    echo '<option value="' . h((string)$spec['name']) . '">' . h((string)$spec['name']) . '</option>';
}
echo '</select></label>';
echo '<label>Senha<input type="password" name="password" required minlength="8" placeholder="Mínimo 8 caracteres"></label>';
echo '<label>Status<select name="status">';
echo '<option value="active">active</option>';
echo '<option value="inactive">inactive</option>';
echo '</select></label>';

// Seção de Perfis/Roles
echo '<div style="margin-top:8px;padding:16px;border:1px solid hsl(var(--border));border-radius:8px;background:hsla(var(--primary)/.03)">';
echo '<div style="font-weight:700;margin-bottom:10px">Perfis de Acesso</div>';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px">';
foreach ($roles as $role) {
    $checked = ($prefRole === $role['slug']) ? ' checked' : '';
    $roleLabel = ucfirst($role['name'] ?? $role['slug']);
    echo '<label style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid hsl(var(--border));border-radius:6px;cursor:pointer;font-size:13px">';
    echo '<input type="checkbox" name="roles[]" value="' . h($role['slug']) . '"' . $checked . '>';
    echo '<strong>' . h($roleLabel) . '</strong>';
    echo '</label>';
}
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/users_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
