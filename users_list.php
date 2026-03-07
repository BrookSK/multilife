<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$roleFilter = isset($_GET['role']) ? trim((string)$_GET['role']) : '';

$sql = 'SELECT u.id, u.name, u.email, u.status, u.created_at FROM users u';
$params = [];
$where = [];

if ($roleFilter !== '') {
    $sql .= ' LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id';
    $where[] = 'r.slug = :role';
    $params['role'] = $roleFilter;
}

if ($q !== '') {
    $where[] = '(u.name LIKE :q OR u.email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' GROUP BY u.id ORDER BY u.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = $roleFilter === 'profissional' ? 'Profissionais' : 'Usuários';
$pageDescription = $roleFilter === 'profissional' ? 'Gerencie profissionais e seus acessos.' : 'Gerencie usuários e seus acessos.';

view_header($pageTitle);

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">' . h($pageTitle) . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">' . h($pageDescription) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/users_create.php">Novo usuário</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/users_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
if ($roleFilter !== '') {
    echo '<input type="hidden" name="role" value="' . h($roleFilter) . '">';
}
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por nome ou e-mail" style="flex:1;min-width:220px">';
echo '<button class="btn" type="submit">Buscar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>E-mail</th><th>Status</th><th>Criado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead>';
echo '<tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['name']) . '</td>';
    echo '<td>' . h((string)$r['email']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/users_edit.php?id=' . (int)$r['id'] . '">Editar</a> ';
    echo '<a class="btn" href="/users_roles_edit.php?id=' . (int)$r['id'] . '">Perfis</a> ';
    
    // Botão Login as User (apenas para admins)
    $currentUserId = auth_user_id();
    if ((int)$r['id'] !== $currentUserId) {
        echo '<a class="btn" href="/login_as_user.php?user_id=' . (int)$r['id'] . '" style="background:#667eea;color:white" onclick="return confirm(\'Fazer login como ' . h((string)$r['name']) . '?\')">Login as User</a> ';
    }
    
    echo '<form method="post" action="/users_delete_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Excluir este usuário?\')">Excluir</button>';
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
