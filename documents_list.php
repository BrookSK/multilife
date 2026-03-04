<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$entityType = isset($_GET['entity_type']) ? (string)$_GET['entity_type'] : '';
$entityId = isset($_GET['entity_id']) ? trim((string)$_GET['entity_id']) : '';
$category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';

$allowedTypes = ['', 'patient', 'professional', 'company'];
if (!in_array($entityType, $allowedTypes, true)) {
    $entityType = '';
}

$sql = 'SELECT d.id, d.entity_type, d.entity_id, d.category, d.title, d.status, d.created_at,
               (SELECT MAX(v.version_no) FROM document_versions v WHERE v.document_id = d.id) AS last_version
        FROM documents d
        WHERE 1=1';
$params = [];

if ($entityType !== '') {
    $sql .= ' AND d.entity_type = :entity_type';
    $params['entity_type'] = $entityType;
}

if ($entityId !== '') {
    $sql .= ' AND d.entity_id = :entity_id';
    $params['entity_id'] = (int)$entityId;
}

if ($category !== '') {
    $sql .= ' AND d.category LIKE :category';
    $params['category'] = '%' . $category . '%';
}

$sql .= ' ORDER BY d.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Gestão Documental');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gestão Documental</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Documentos por entidade + versões + validade.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/documents_upload.php">Novo documento</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/documents_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';

echo '<select name="entity_type" style="min-width:200px">';
$labels = ['' => 'Todos', 'patient' => 'Paciente', 'professional' => 'Profissional', 'company' => 'Empresa'];
foreach ($labels as $k => $label) {
    $sel = ($entityType === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';

echo '<input name="entity_id" value="' . h($entityId) . '" placeholder="ID entidade" style="width:160px">';

echo '<input name="category" value="' . h($category) . '" placeholder="Categoria (ex: Faturamento)" style="flex:1;min-width:220px">';

echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Entidade</th><th>Categoria</th><th>Título</th><th>Versão</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $ent = (string)$r['entity_type'] . ' #' . (string)($r['entity_id'] ?? '-');
    $ver = $r['last_version'] !== null ? 'v' . (int)$r['last_version'] : '-';

    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h($ent) . '</td>';
    echo '<td>' . h((string)$r['category']) . '</td>';
    echo '<td>' . h((string)($r['title'] ?? '')) . '</td>';
    echo '<td>' . h($ver) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/documents_view.php?id=' . (int)$r['id'] . '">Abrir</a> ';
    echo '<form method="post" action="/documents_archive_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" style="height:34px">Arquivar</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="7" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
