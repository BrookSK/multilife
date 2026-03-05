<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

view_header('Nova Especialidade');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Nova Especialidade</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastrar nova especialidade com valor mínimo.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/specialties_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/specialties_create_post.php" style="max-width:720px">';

echo '<label>Nome da Especialidade<input name="name" required maxlength="120" placeholder="Ex: Fisioterapia"></label>';

echo '<label>Valor Mínimo por Sessão (R$)<input type="number" step="0.01" min="0" name="minimum_value" required value="0.00"></label>';

echo '<label>Status<select name="status">';
echo '<option value="active">Ativa</option>';
echo '<option value="inactive">Inativa</option>';
echo '</select></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/specialties_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
