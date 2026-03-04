<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('zapsign.manage');

$baseUrl = admin_setting_get('zapsign.base_url', 'https://api.zapsign.com.br') ?? 'https://api.zapsign.com.br';

view_header('ZapSign Console');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">ZapSign — Console</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Criar documento e detalhar documento. Toda chamada gera log em Logs TI (provider=zapsign).</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:12px;line-height:1.6">Base URL atual: <code>' . h($baseUrl) . '</code></div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_integrations.php">Credenciais</a>';
echo '<a class="btn" href="/tech_logs_list.php?provider=zapsign">Logs TI</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Criar documento

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Criar documento (POST /api/v1/docs/)</div>';

echo '<form method="post" action="/admin_zapsign_create_doc_post.php" style="display:grid;gap:12px;max-width:980px">';

echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome<input name="name" required maxlength="255" placeholder="Contrato" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Lang<input name="lang" value="pt-br" placeholder="pt-br" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<div style="font-weight:800;margin-top:8px">Arquivo</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">url_pdf (opcional)<input name="url_pdf" placeholder="https://...pdf" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">url_docx (opcional)<input name="url_docx" placeholder="https://...docx" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col12"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">markdown_text (opcional)<textarea name="markdown_text" rows="4" placeholder="# Título\n\nConteúdo..." style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:13px"></textarea></label></div>';
echo '</div>';

echo '<div style="font-weight:800;margin-top:8px">Signers (mínimo 1)</div>';

echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Signer 1 - Nome<input name="signer1_name" required placeholder="Fulano" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Signer 1 - E-mail<input type="email" name="signer1_email" required placeholder="fulano@email.com" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';

echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Signer 2 - Nome (opcional)<input name="signer2_name" placeholder="Ciclano" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Signer 2 - E-mail (opcional)<input type="email" name="signer2_email" placeholder="ciclano@email.com" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';

echo '</div>';

echo '<label class="pill" style="display:flex;align-items:center;gap:10px;padding:12px">';
echo '<input type="checkbox" name="disable_signer_emails" value="1"> Desabilitar e-mails automáticos do ZapSign';
echo '</label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Criar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

// Detalhar doc

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Detalhar documento (GET /api/v1/docs/{doc_token}/)</div>';

echo '<form method="post" action="/admin_zapsign_detail_doc_post.php" style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="doc_token" required placeholder="doc_token" style="flex:1;min-width:280px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Detalhar</button>';
echo '</form>';

echo '</section>';

echo '</div>';

view_footer();
