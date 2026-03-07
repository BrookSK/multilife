<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM specialties WHERE id = :id');
$stmt->execute(['id' => $id]);
$s = $stmt->fetch();

if (!$s) {
    flash_set('error', 'Especialidade não encontrada.');
    header('Location: /specialties_list.php');
    exit;
}

view_header('Editar Especialidade');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Editar Especialidade</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualizar dados da especialidade.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/specialties_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/specialties_edit_post.php" style="max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$s['id'] . '">';

echo '<label>Nome da Especialidade<input name="name" required maxlength="120" value="' . h((string)$s['name']) . '"></label>';

echo '<label>Valor Mínimo por Sessão (R$)<input type="number" step="0.01" min="0" name="minimum_value" required value="' . h(number_format((float)$s['minimum_value'], 2, '.', '')) . '"></label>';

echo '<label>Valor de Custo Interno (R$)<input type="number" step="0.01" min="0" name="internal_cost" required value="' . h(number_format((float)($s['internal_cost'] ?? 0), 2, '.', '')) . '"></label>';

echo '<label>Status<select name="status">';
$statuses = ['active' => 'Ativa', 'inactive' => 'Inativa'];
foreach ($statuses as $k => $label) {
    $sel = ((string)$s['status'] === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/specialties_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
