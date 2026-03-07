<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

// Buscar especialidades
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

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
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar demanda</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualize os dados do card para o fluxo de captação.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_view.php?id=' . (int)$d['id'] . '">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/demands_edit_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<label>Título<input name="title" required maxlength="200" value="' . h((string)$d['title']) . '" placeholder="Nome do paciente / título"></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>Cidade<input name="location_city" maxlength="120" value="' . h((string)($d['location_city'] ?? '')) . '" placeholder="Ex: São Paulo"></label></div>';
echo '<div class="col6"><label>UF<input name="location_state" maxlength="2" value="' . h((string)($d['location_state'] ?? '')) . '" placeholder="SP" style="text-transform:uppercase"></label></div>';
echo '<div class="col6"><label>Especialidade<select name="specialty">';
echo '<option value="">Selecione...</option>';
foreach ($specialties as $spec) {
    $selected = ((string)($d['specialty'] ?? '') === (string)$spec['name']) ? ' selected' : '';
    echo '<option value="' . h((string)$spec['name']) . '"' . $selected . '>' . h((string)$spec['name']) . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Origem (e-mail)<input type="email" name="origin_email" maxlength="190" value="' . h((string)($d['origin_email'] ?? '')) . '" placeholder="origem@empresa.com"></label></div>';
echo '</div>';

echo '<label>Descrição<textarea name="description" rows="6" placeholder="Observações...">' . h((string)($d['description'] ?? '')) . '</textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/demands_view.php?id=' . (int)$d['id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();
