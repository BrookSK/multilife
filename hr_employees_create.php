<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

view_header('Novo funcionário');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Novo funcionário</div>';

echo '<form method="post" action="/hr_employees_create_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome completo<input name="full_name" required maxlength="160" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">E-mail<input type="email" name="email" maxlength="190" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Telefone<input name="phone" maxlength="30" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Cargo/Função<input name="role_title" maxlength="120" placeholder="Ex: Captador" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Status<select name="status" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
echo '<option value="active">active</option>';
echo '<option value="inactive">inactive</option>';
echo '</select></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Observações<textarea name="notes" rows="3" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/hr_employees_list.php">Cancelar</a>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
