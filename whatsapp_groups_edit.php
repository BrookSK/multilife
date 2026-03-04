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
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar grupo WhatsApp</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualize filtros: especialidade + cidade/UF.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/whatsapp_groups_edit_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<input type="hidden" name="id" value="' . (int)$g['id'] . '">';
echo '<label>Nome<input name="name" required maxlength="160" value="' . h((string)$g['name']) . '" placeholder="Nome do grupo"></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>Especialidade (opcional)<input name="specialty" maxlength="120" value="' . h((string)($g['specialty'] ?? '')) . '" placeholder="Ex: Fisioterapia"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Status<select name="status">';
$st = (string)$g['status'];
echo '<option value="active"' . ($st === 'active' ? ' selected' : '') . '>active</option>';
echo '<option value="inactive"' . ($st === 'inactive' ? ' selected' : '') . '>inactive</option>';
echo '</select></label>';
echo '</div>';
echo '</div>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>Cidade (opcional)<input name="city" maxlength="120" value="' . h((string)($g['city'] ?? '')) . '" placeholder="Ex: São Paulo"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>UF (opcional)<input name="state" maxlength="2" value="' . h((string)($g['state'] ?? '')) . '" placeholder="SP" style="text-transform:uppercase"></label>';
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
