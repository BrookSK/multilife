<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

view_header('Novo grupo WhatsApp');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo grupo WhatsApp</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastre filtros: especialidade + cidade/UF.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/whatsapp_groups_create_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<label>Nome<input name="name" required maxlength="160" placeholder="Nome do grupo"></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>Especialidade (opcional)<input name="specialty" maxlength="120" placeholder="Ex: Fisioterapia"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Status<select name="status">';
echo '<option value="active">active</option>';
echo '<option value="inactive">inactive</option>';
echo '</select></label>';
echo '</div>';
echo '</div>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>Cidade (opcional)<input name="city" maxlength="120" placeholder="Ex: São Paulo"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>UF (opcional)<input name="state" maxlength="2" placeholder="SP" style="text-transform:uppercase"></label>';
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
