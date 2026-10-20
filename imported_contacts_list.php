<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
if (!rbac_user_can((int)auth_user_id(), 'chat.manage') && !rbac_user_can((int)auth_user_id(), 'demands.manage')) {
    rbac_require_permission('chat.manage');
}

require_once __DIR__ . '/app/imported_contacts_helper.php';

$seed = imported_contacts_seed_bundled(false);
if (!empty($seed['ran'])) {
    $done = imported_contacts_counts();
    flash_set('success', 'Agenda importada. Profissionais: ' . $done['profissional']
        . ', operadoras/clientes: ' . $done['operadora']
        . ', pacientes: ' . $done['paciente']
        . ', outros: ' . $done['outros'] . '.');
}

$labels = imported_contacts_categories();
$category = isset($_GET['category']) && isset($labels[$_GET['category']]) ? (string)$_GET['category'] : 'profissional';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($category === 'operadora') {
    $where[] = "category IN ('operadora','cliente')";
} else {
    $where[] = 'category = :cat';
    $params['cat'] = $category;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(name LIKE :q_name OR phone LIKE :q_phone OR phone_raw LIKE :q_raw)';
    $params['q_name'] = $like;
    $params['q_phone'] = $like;
    $params['q_raw'] = $like;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM imported_contacts $whereSql");
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $pages) {
    $page = $pages;
    $offset = ($page - 1) * $perPage;
}

$stmt = db()->prepare("SELECT * FROM imported_contacts $whereSql ORDER BY name ASC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$counts = imported_contacts_counts();

view_header('Contatos Importados');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Contatos Importados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Profissionais: <strong>' . $counts['profissional'] . '</strong> · Operadoras/Clientes: <strong>' . $counts['operadora'] . '</strong> · Pacientes: <strong>' . $counts['paciente'] . '</strong> · Outros: <strong>' . $counts['outros'] . '</strong></div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/chat_web.php?type=contatos&cat=' . urlencode($category) . '">Abrir no WhatsApp</a>';
echo '<a class="btn btnPrimary" href="/contacts_import.php">Importar</a>';
echo '</div>';
echo '</div>';

echo '<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">';
foreach ($labels as $key => $label) {
    $active = $category === $key;
    $style = $active
        ? 'background:#00a884;color:#fff;border-color:#00a884'
        : 'background:#fff;color:#54656f;border-color:hsl(var(--border))';
    $href = '/imported_contacts_list.php?category=' . urlencode($key) . ($q !== '' ? '&q=' . urlencode($q) : '');
    echo '<a href="' . h($href) . '" style="padding:8px 12px;border-radius:999px;border:1px solid;font-size:13px;font-weight:700;text-decoration:none;' . $style . '">' . h($label) . ' (' . (int)$counts[$key] . ')</a>';
}
echo '</div>';

echo '<form method="get" action="/imported_contacts_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="category" value="' . h($category) . '">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por nome ou telefone" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr><th>Nome</th><th>Telefone</th><th>Categoria</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $cat = (string)$r['category'];
    if ($cat === 'cliente') {
        $cat = 'operadora';
    }
    $badgeColor = $cat === 'paciente' ? 'hsl(221,83%,53%)' : ($cat === 'profissional' ? 'hsl(142,76%,36%)' : ($cat === 'operadora' ? 'hsl(32,95%,44%)' : 'hsl(var(--muted-foreground))'));
    $phone = (string)($r['phone'] ?? '');
    $phoneShow = $phone !== '' ? imported_contacts_format_phone($phone) : (string)($r['phone_raw'] ?? '-');
    $inactive = (string)($r['notes'] ?? '') === 'nao_atende';
    echo '<tr>';
    echo '<td>' . h((string)$r['name']);
    if ($inactive) {
        echo ' <span class="badge" style="background:#fde8e8;color:#c81e1e;font-size:11px">Não atende</span>';
    }
    echo '</td>';
    echo '<td>' . h($phoneShow) . '</td>';
    echo '<td><span class="badge" style="background:' . $badgeColor . ';color:#fff;font-size:12px">' . h($labels[$cat] ?? $cat) . '</span></td>';
    echo '<td style="white-space:nowrap">';
    if ($phone !== '') {
        echo '<a class="btn btnPrimary" href="/chat_open_imported_contact.php?id=' . (int)$r['id'] . '">Abrir conversa</a>';
    }
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="4" style="padding:14px">Nenhum contato neste grupo. Use Importar para carregar a agenda.</td></tr>';
}
echo '</tbody></table>';
echo '</div>';

if ($pages > 1) {
    echo '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">';
    $base = '/imported_contacts_list.php?category=' . urlencode($category) . ($q !== '' ? '&q=' . urlencode($q) : '');
    if ($page > 1) {
        echo '<a class="btn" href="' . h($base . '&page=' . ($page - 1)) . '">Anterior</a>';
    }
    echo '<span style="align-self:center;font-size:13px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $pages . '</span>';
    if ($page < $pages) {
        echo '<a class="btn" href="' . h($base . '&page=' . ($page + 1)) . '">Próxima</a>';
    }
    echo '</div>';
}
echo '</section>';
echo '</div>';

view_footer();
