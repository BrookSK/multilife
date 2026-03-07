<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    header('Location: /service_types_list.php');
    exit;
}

$stmt = db()->prepare("SELECT * FROM service_types WHERE id = ?");
$stmt->execute([$id]);
$serviceType = $stmt->fetch();

if (!$serviceType) {
    header('Location: /service_types_list.php?error=' . urlencode('Tipo de serviço não encontrado'));
    exit;
}

view_header('Editar Tipo de Serviço');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Editar Tipo de Serviço</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualizar informações do tipo de serviço.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/service_types_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/service_type_edit_post.php" style="max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$serviceType['id'] . '">';

echo '<label>Nome do Tipo de Serviço<input name="name" required maxlength="100" value="' . h((string)$serviceType['name']) . '"></label>';

echo '<label>Descrição<textarea name="description" rows="3">' . h((string)$serviceType['description']) . '</textarea></label>';

echo '<label>Ordem de Exibição<input type="number" name="display_order" value="' . (int)$serviceType['display_order'] . '" min="0"></label>';

echo '<label>Status<select name="status">';
echo '<option value="active"' . ($serviceType['status'] === 'active' ? ' selected' : '') . '>Ativo</option>';
echo '<option value="inactive"' . ($serviceType['status'] === 'inactive' ? ' selected' : '') . '>Inativo</option>';
echo '</select></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/service_types_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
