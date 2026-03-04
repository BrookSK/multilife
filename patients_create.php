<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

view_header('Novo paciente');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Novo paciente</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.6;margin-bottom:14px">Cadastro inicial do paciente.</div>';

echo '<form method="post" action="/patients_create_post.php" style="display:grid;gap:12px;max-width:860px">';

echo '<div style="font-weight:800;margin-top:6px">Identificação</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome completo<input name="full_name" required maxlength="160" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">CPF<input name="cpf" maxlength="20" placeholder="000.000.000-00" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">RG<input name="rg" maxlength="30" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Data de nascimento<input type="date" name="birth_date" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<div style="font-weight:800;margin-top:6px">Contato</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">WhatsApp<input name="whatsapp" maxlength="30" placeholder="5511999999999" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">E-mail<input type="email" name="email" maxlength="190" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/patients_list.php">Cancelar</a>';
echo '</div>';

echo '</form>';
echo '</div>';

view_footer();
