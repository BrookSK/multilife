<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM documents WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Documento não encontrado.');
    header('Location: /documents_list.php');
    exit;
}

$stmt = db()->prepare('SELECT v.*, u.name AS user_name FROM document_versions v LEFT JOIN users u ON u.id = v.uploaded_by_user_id WHERE v.document_id = :id ORDER BY v.version_no DESC');
$stmt->execute(['id' => $id]);
$versions = $stmt->fetchAll();

view_header('Documento #' . (string)$d['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Documento</div>';
echo '<div style="font-size:22px;font-weight:900">#' . (int)$d['id'] . ' — ' . h((string)$d['category']) . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Entidade:</strong> ' . h((string)$d['entity_type']) . ' #' . h((string)($d['entity_id'] ?? '-')) . ' &nbsp; <strong>Status:</strong> ' . h((string)$d['status']);
echo '</div>';
if (!empty($d['title'])) {
    echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px"><strong>Título:</strong> ' . h((string)$d['title']) . '</div>';
}
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/documents_list.php">Voltar</a>';

echo '<form method="post" action="/documents_version_upload_post.php" enctype="multipart/form-data" style="display:inline-flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<input type="date" name="valid_until" style="width:160px">';
echo '<input type="file" name="file" required style="max-width:260px">';
echo '<button class="btn btnPrimary" type="submit">Nova versão</button>';
echo '</form>';

echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Versões</div>';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Versão</th><th>Quando</th><th>Usuário</th><th>Arquivo</th><th>Validade</th><th>Tamanho</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($versions as $v) {
    $path = (string)$v['stored_path'];
    $file = (string)$v['original_name'];

    echo '<tr>';
    echo '<td>v' . (int)$v['version_no'] . '</td>';
    echo '<td>' . h((string)$v['uploaded_at']) . '</td>';
    echo '<td>' . h((string)($v['user_name'] ?? '-')) . '</td>';
    echo '<td>' . h($file) . '</td>';
    echo '<td>' . h((string)($v['valid_until'] ?? '')) . '</td>';
    echo '<td>' . h((string)($v['file_size'] ?? '')) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/document_download.php?version_id=' . (int)$v['id'] . '" target="_blank" rel="noopener">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($versions) === 0) {
    echo '<tr><td colspan="7" class="pill" style="display:table-cell;padding:12px">Sem versões.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
