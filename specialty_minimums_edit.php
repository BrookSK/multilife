<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM specialty_minimums WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /specialty_minimums_list.php');
    exit;
}

view_header('Editar mínimo');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar mínimo</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualize o valor mínimo por especialidade.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/specialty_minimums_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/specialty_minimums_edit_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$row['id'] . '">';
echo '<label>Especialidade<input name="specialty" required maxlength="120" value="' . h((string)$row['specialty']) . '"></label>';
echo '<label>Mínimo<input type="number" step="0.01" min="0" name="minimum_value" required value="' . h((string)$row['minimum_value']) . '"></label>';
$st = (string)$row['status'];
echo '<label>Status<select name="status"><option value="active"' . ($st === 'active' ? ' selected' : '') . '>active</option><option value="inactive"' . ($st === 'inactive' ? ' selected' : '') . '>inactive</option></select></label>';

echo '<div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/specialty_minimums_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
