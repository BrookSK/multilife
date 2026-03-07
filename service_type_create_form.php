<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

view_header('Novo Tipo de Serviço');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Novo Tipo de Serviço</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Criar novo tipo de serviço disponível para todas as especialidades.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/service_types_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/service_type_create_post.php" style="max-width:720px">';

echo '<label>Nome do Tipo de Serviço<input name="name" required maxlength="100" placeholder="Ex: Atendimento Domiciliar"></label>';

echo '<label>Descrição<textarea name="description" rows="3" placeholder="Descreva o tipo de serviço..."></textarea></label>';

echo '<label>Ordem de Exibição<input type="number" name="display_order" value="0" min="0"></label>';

echo '<label>Status<select name="status">';
echo '<option value="active">Ativo</option>';
echo '<option value="inactive">Inativo</option>';
echo '</select></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/service_types_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
