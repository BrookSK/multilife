<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$roleFilter = isset($_GET['role']) ? trim((string)$_GET['role']) : '';
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : ''; // equipe | profissional
$specialtyFilter = isset($_GET['specialty']) ? trim((string)$_GET['specialty']) : '';

// ITEM 16: Paginação no backend
$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Buscar todas as roles para o filtro de perfil (item 15)
$allRoles = db()->query("SELECT slug, name FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Especialidades cadastradas (para o filtro de especialidade)
$allSpecialties = db()->query("SELECT name FROM specialties WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Construção dinâmica do WHERE
$where = [];
$params = [];
$needsRoleJoin = false;

if ($roleFilter !== '') {
    $needsRoleJoin = true;
    $where[] = 'r.slug = :role';
    $params['role'] = $roleFilter;
}

// Filtro por tipo: profissional = tem role profissional; equipe = NÃO tem role profissional
if ($typeFilter === 'profissional') {
    $where[] = "EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON r2.id = ur2.role_id WHERE ur2.user_id = u.id AND r2.slug = 'profissional')";
} elseif ($typeFilter === 'equipe') {
    $where[] = "NOT EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON r2.id = ur2.role_id WHERE ur2.user_id = u.id AND r2.slug = 'profissional')";
}

if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}

// Filtro por especialidade
if ($specialtyFilter !== '') {
    $where[] = 'u.specialty = :specialty';
    $params['specialty'] = $specialtyFilter;
}

if ($q !== '') {
    $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2)';
    $qLike = '%' . $q . '%';
    $params['q1'] = $qLike;
    $params['q2'] = $qLike;
}

$joinSql = $needsRoleJoin ? ' LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id' : '';
$whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

// Total para paginação
$countSql = 'SELECT COUNT(DISTINCT u.id) FROM users u' . $joinSql . $whereSql;
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalUsers = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalUsers / $perPage));

// Query paginada
$sql = 'SELECT u.id, u.name, u.email, u.status, u.specialty, u.created_at FROM users u' . $joinSql . $whereSql
     . ' GROUP BY u.id ORDER BY u.id ASC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Helper para preservar filtros nos links de paginação
$buildPageUrl = function (int $p) use ($q, $roleFilter, $statusFilter, $typeFilter, $specialtyFilter): string {
    $qs = array_filter([
        'q' => $q, 'role' => $roleFilter, 'status' => $statusFilter, 'type' => $typeFilter,
        'specialty' => $specialtyFilter, 'page' => $p,
    ], fn($v) => $v !== '' && $v !== null);
    return '/users_list.php?' . http_build_query($qs);
};

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

echo '<form method="get" action="/users_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por nome ou e-mail" style="flex:1;min-width:200px">';

// Filtro tipo (equipe / profissional)
echo '<select name="type" style="min-width:150px">';
echo '<option value="">Todos os tipos</option>';
echo '<option value="equipe"' . ($typeFilter === 'equipe' ? ' selected' : '') . '>Equipe (interno)</option>';
echo '<option value="profissional"' . ($typeFilter === 'profissional' ? ' selected' : '') . '>Profissionais</option>';
echo '</select>';

// Filtro perfil (role)
echo '<select name="role" style="min-width:150px">';
echo '<option value="">Todos os perfis</option>';
foreach ($allRoles as $ar) {
    $sel = ($roleFilter === $ar['slug']) ? ' selected' : '';
    echo '<option value="' . h($ar['slug']) . '"' . $sel . '>' . h($ar['name']) . '</option>';
}
echo '</select>';

// Filtro especialidade
echo '<select name="specialty" style="min-width:170px">';
echo '<option value="">Todas as especialidades</option>';
foreach ($allSpecialties as $spName) {
    $sel = ($specialtyFilter === $spName) ? ' selected' : '';
    echo '<option value="' . h($spName) . '"' . $sel . '>' . h($spName) . '</option>';
}
echo '</select>';

// Filtro status
echo '<select name="status" style="min-width:130px">';
echo '<option value="">Todos os status</option>';
echo '<option value="active"' . ($statusFilter === 'active' ? ' selected' : '') . '>Ativo</option>';
echo '<option value="inactive"' . ($statusFilter === 'inactive' ? ' selected' : '') . '>Inativo</option>';
echo '</select>';

echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
if ($q !== '' || $roleFilter !== '' || $statusFilter !== '' || $typeFilter !== '' || $specialtyFilter !== '') {
    echo '<a class="btn" href="/users_list.php">Limpar</a>';
}
echo '</form>';

echo '<div style="margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">' . number_format($totalUsers, 0, ',', '.') . ' usuário(s) encontrado(s)</div>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>E-mail</th><th>Especialidade</th><th>Status</th><th>Criado</th><th style="text-align:right">Ações</th>';
echo '</tr></thead>';
echo '<tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['name']) . '</td>';
    echo '<td>' . h((string)$r['email']) . '</td>';
    echo '<td>' . h((string)($r['specialty'] ?? '') ?: '-') . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)$r['created_at']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/users_edit.php?id=' . (int)$r['id'] . '">Editar</a> ';
    echo '<a class="btn" href="/users_roles_edit.php?id=' . (int)$r['id'] . '">Perfis</a> ';
    
    // Botão Login as User (apenas para admins)
    $currentUserId = auth_user_id();
    if ((int)$r['id'] !== $currentUserId) {
        echo '<a class="btn" href="/login_as_user.php?user_id=' . (int)$r['id'] . '" style="background:hsl(var(--primary));color:white" onclick="return confirm(\'Fazer login como ' . h((string)$r['name']) . '?\')">Login as User</a> ';
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

// Controles de paginação (item 16)
if ($totalPages > 1) {
    echo '<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;flex-wrap:wrap">';
    if ($page > 1) {
        echo '<a class="btn" href="' . h($buildPageUrl($page - 1)) . '">← Anterior</a>';
    }
    // Janela de páginas
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    if ($start > 1) { echo '<a class="btn" href="' . h($buildPageUrl(1)) . '">1</a>'; if ($start > 2) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            echo '<span class="btn btnPrimary" style="pointer-events:none">' . $i . '</span>';
        } else {
            echo '<a class="btn" href="' . h($buildPageUrl($i)) . '">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; echo '<a class="btn" href="' . h($buildPageUrl($totalPages)) . '">' . $totalPages . '</a>'; }
    if ($page < $totalPages) {
        echo '<a class="btn" href="' . h($buildPageUrl($page + 1)) . '">Próxima →</a>';
    }
    echo '</div>';
    echo '<div style="text-align:center;margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $totalPages . '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
