<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

view_header('Novo grupo WhatsApp');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Novo grupo WhatsApp</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.6;margin-bottom:14px">Cadastre filtros: especialidade + cidade/UF.</div>';

echo '<form method="post" action="/whatsapp_groups_create_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome<input name="name" required maxlength="160" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Especialidade (opcional)<input name="specialty" maxlength="120" placeholder="Ex: Fisioterapia" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Status<select name="status" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
echo '<option value="active">active</option>';
echo '<option value="inactive">inactive</option>';
echo '</select></label>';
echo '</div>';
echo '</div>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Cidade (opcional)<input name="city" maxlength="120" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">UF (opcional)<input name="state" maxlength="2" placeholder="SP" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px;text-transform:uppercase"></label>';
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/whatsapp_groups_list.php">Cancelar</a>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
