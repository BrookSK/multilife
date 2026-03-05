<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

view_header('Novo mínimo');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo mínimo por especialidade</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Define o valor mínimo para validar agendamentos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/specialty_minimums_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/specialty_minimums_create_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<label>Especialidade<input name="specialty" required maxlength="120" placeholder="Ex: Fisioterapia"></label>';
echo '<label>Mínimo<input type="number" step="0.01" min="0" name="minimum_value" required value="0"></label>';
echo '<label>Status<select name="status"><option value="active">active</option><option value="inactive">inactive</option></select></label>';
echo '<div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/specialty_minimums_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
