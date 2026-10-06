<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

require_once __DIR__ . '/app/imported_contacts_helper.php';
imported_contacts_ensure_schema();

$category = isset($_GET['category']) && in_array($_GET['category'], ['cliente', 'profissional', 'outros'], true) ? (string)$_GET['category'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$where = [];
$params = [];
if ($category !== '') {
    $where[] = 'category = :cat';
    $params['cat'] = $category;
}
if ($q !== '') {
    $where[] = '(name LIKE :q OR phone LIKE :q OR phone_raw LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = db()->prepare("SELECT * FROM imported_contacts $whereSql ORDER BY name ASC LIMIT 1000");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contagens por categoria.
$counts = ['cliente' => 0, 'profissional' => 0, 'outros' => 0];
try {
    foreach (db()->query("SELECT category, COUNT(*) AS c FROM imported_contacts GROUP BY category")->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $counts[(string)$cr['category']] = (int)$cr['c'];
    }
} catch (Throwable $e) {}

view_header('Contatos Importados');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Contatos Importados</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Clientes: <strong>' . $counts['cliente'] . '</strong> · Profissionais: <strong>' . $counts['profissional'] . '</strong> · Outros: <strong>' . $counts['outros'] . '</strong></div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/contacts_import.php">Importar mais</a>';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/imported_contacts_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="category" style="min-width:200px">';
$catOpts = ['' => 'Todas as categorias', 'cliente' => 'Clientes', 'profissional' => 'Profissionais', 'outros' => 'Outros'];
foreach ($catOpts as $k => $label) {
    $sel = ($category === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (nome ou telefone)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr><th>Nome</th><th>Telefone</th><th>Categoria</th><th>Origem</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $cat = (string)$r['category'];
    $catBadge = $cat === 'cliente'
        ? '<span class="badge" style="background:hsl(221,83%,53%);color:#fff;font-size:12px">Cliente</span>'
        : ($cat === 'profissional'
            ? '<span class="badge" style="background:hsl(142,76%,36%);color:#fff;font-size:12px">Profissional</span>'
            : '<span class="badge" style="background:hsl(var(--accent));font-size:12px">Outros</span>');
    $phoneShow = (string)($r['phone'] ?? '');
    if ($phoneShow === '') { $phoneShow = (string)($r['phone_raw'] ?? '-'); }
    echo '<tr>';
    echo '<td>' . h((string)$r['name']) . '</td>';
    echo '<td>' . h($phoneShow) . '</td>';
    echo '<td>' . $catBadge . '</td>';
    echo '<td>' . h((string)($r['source'] ?? '-')) . '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="4" style="padding:14px">Nenhum contato. Use "Importar mais" para começar.</td></tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '</section>';
echo '</div>';

view_footer();
