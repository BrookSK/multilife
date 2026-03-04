<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

view_header('Novo documento');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Novo documento</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.6;margin-bottom:14px">O envio cria a versão v1. Atualizações criam v2, v3...</div>';

echo '<form method="post" action="/documents_upload_post.php" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:860px">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Tipo de entidade<select name="entity_type" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
echo '<option value="patient">patient</option>';
echo '<option value="professional">professional</option>';
echo '<option value="company">company</option>';
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">ID da entidade (vazio para company)<input name="entity_id" placeholder="Ex: 123" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Categoria<input name="category" required maxlength="60" placeholder="Ex: Faturamento" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Título (opcional)<input name="title" maxlength="160" placeholder="Ex: COREN" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Validade (opcional)<input type="date" name="valid_until" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Arquivo<input type="file" name="file" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.35);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Enviar</button>';
echo '<a class="btn" href="/documents_list.php">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();
