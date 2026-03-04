<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $id]);
$g = $stmt->fetch();

if (!$g) {
    flash_set('error', 'Grupo não encontrado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

view_header('Editar grupo');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Editar grupo WhatsApp</div>';

echo '<form method="post" action="/whatsapp_groups_edit_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$g['id'] . '">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome<input name="name" required maxlength="160" value="' . h((string)$g['name']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Especialidade (opcional)<input name="specialty" maxlength="120" value="' . h((string)($g['specialty'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Status<select name="status" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
$st = (string)$g['status'];
echo '<option value="active"' . ($st === 'active' ? ' selected' : '') . '>active</option>';
echo '<option value="inactive"' . ($st === 'inactive' ? ' selected' : '') . '>inactive</option>';
echo '</select></label>';
echo '</div>';
echo '</div>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Cidade (opcional)<input name="city" maxlength="120" value="' . h((string)($g['city'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">UF (opcional)<input name="state" maxlength="2" value="' . h((string)($g['state'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px;text-transform:uppercase"></label>';
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/whatsapp_groups_list.php">Cancelar</a>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
