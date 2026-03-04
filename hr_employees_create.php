<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

view_header('Novo funcionário');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo funcionário</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastro interno (stub para contratos/assinaturas no futuro).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/hr_employees_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/hr_employees_create_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<label>Nome completo<input name="full_name" required maxlength="160" placeholder="Nome completo"></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>E-mail<input type="email" name="email" maxlength="190" placeholder="email@empresa.com"></label></div>';
echo '<div class="col6"><label>Telefone<input name="phone" maxlength="30" placeholder="(00) 00000-0000"></label></div>';
echo '</div>';

echo '<label>Cargo/Função<input name="role_title" maxlength="120" placeholder="Ex: Captador"></label>';

echo '<label>Status<select name="status">';
echo '<option value="active">active</option>';
echo '<option value="inactive">inactive</option>';
echo '</select></label>';

echo '<label>Observações<textarea name="notes" rows="3"></textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/hr_employees_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
