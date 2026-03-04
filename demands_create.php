<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

view_header('Novo card');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo card de demanda</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Use quando a IA não criar automaticamente (ou para registro manual).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/demands_create_post.php" style="display:grid;gap:12px;max-width:820px">';

echo '<label>Título<input name="title" required maxlength="200" placeholder="Nome do paciente / título"></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>Cidade<input name="location_city" maxlength="120" placeholder="Ex: São Paulo"></label></div>';
echo '<div class="col6"><label>UF<input name="location_state" maxlength="2" placeholder="SP" style="text-transform:uppercase"></label></div>';
echo '<div class="col6"><label>Especialidade<input name="specialty" maxlength="120" placeholder="Ex: Fisioterapia"></label></div>';
echo '<div class="col6"><label>Origem (e-mail)<input type="email" name="origin_email" maxlength="190" placeholder="origem@empresa.com"></label></div>';
echo '</div>';

echo '<label>Descrição<textarea name="description" rows="5" placeholder="Observações..."></textarea></label>';

echo '<label>Status inicial<select name="status">';
echo '<option value="aguardando_captacao">aguardando_captacao</option>';
echo '<option value="tratamento_manual">tratamento_manual</option>';
echo '</select></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/demands_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();
