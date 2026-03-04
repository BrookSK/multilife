<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

view_header('Editar demanda');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Editar demanda</div>';

echo '<form method="post" action="/demands_edit_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Título<input name="title" required maxlength="200" value="' . h((string)$d['title']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Cidade<input name="location_city" maxlength="120" value="' . h((string)($d['location_city'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">UF<input name="location_state" maxlength="2" value="' . h((string)($d['location_state'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px;text-transform:uppercase"></label>';
echo '</div>';
echo '</div>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Especialidade<input name="specialty" maxlength="120" value="' . h((string)($d['specialty'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Origem (e-mail)<input type="email" name="origin_email" maxlength="190" value="' . h((string)($d['origin_email'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Descrição<textarea name="description" rows="6" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">' . h((string)($d['description'] ?? '')) . '</textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/demands_view.php?id=' . (int)$d['id'] . '">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();
