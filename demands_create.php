<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

view_header('Novo card');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Novo card de demanda</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.6;margin-bottom:14px">Use quando a IA não criar automaticamente (ou para registro manual).</div>';

echo '<form method="post" action="/demands_create_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Título<input name="title" required maxlength="200" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Cidade<input name="location_city" maxlength="120" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">UF<input name="location_state" maxlength="2" placeholder="SP" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px;text-transform:uppercase"></label>';
echo '</div>';
echo '</div>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Especialidade<input name="specialty" maxlength="120" placeholder="Ex: Fisioterapia" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Origem (e-mail)<input type="email" name="origin_email" maxlength="190" placeholder="origem@empresa.com" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Descrição<textarea name="description" rows="5" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></textarea></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Status inicial<select name="status" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
echo '<option value="aguardando_captacao">aguardando_captacao</option>';
echo '<option value="tratamento_manual">tratamento_manual</option>';
echo '</select></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/demands_list.php">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();
