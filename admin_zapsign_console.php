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
echo '<div style="font-size:22px;font-weight:900">ZapSign — Console</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Criar documento e detalhar documento. Toda chamada gera log em Logs TI (provider=zapsign).</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:12px;line-height:1.6">Base URL atual: <code>' . h($baseUrl) . '</code></div>';
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
echo '<div style="font-weight:900;margin-bottom:8px">Criar documento (POST /api/v1/docs/)</div>';

echo '<form method="post" action="/admin_zapsign_create_doc_post.php" style="display:grid;gap:12px;max-width:980px">';

echo '<div class="grid">';
echo '<div class="col6"><label>Nome<input name="name" required maxlength="255" placeholder="Contrato"></label></div>';
echo '<div class="col6"><label>Lang<input name="lang" value="pt-br" placeholder="pt-br"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:8px">Arquivo</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>url_pdf (opcional)<input name="url_pdf" placeholder="https://...pdf"></label></div>';
echo '<div class="col6"><label>url_docx (opcional)<input name="url_docx" placeholder="https://...docx"></label></div>';
echo '<div class="col12"><label>markdown_text (opcional)<textarea name="markdown_text" rows="4" placeholder="# Título\n\nConteúdo..."></textarea></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:8px">Signers (mínimo 1)</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Signer 1 - Nome<input name="signer1_name" required placeholder="Fulano"></label></div>';
echo '<div class="col6"><label>Signer 1 - E-mail<input type="email" name="signer1_email" required placeholder="fulano@email.com"></label></div>';

echo '<div class="col6"><label>Signer 2 - Nome (opcional)<input name="signer2_name" placeholder="Ciclano"></label></div>';
echo '<div class="col6"><label>Signer 2 - E-mail (opcional)<input type="email" name="signer2_email" placeholder="ciclano@email.com"></label></div>';

echo '</div>';

echo '<label class="pill" style="display:flex;align-items:center;gap:10px;padding:12px">';
echo '<input type="checkbox" name="disable_signer_emails" value="1"> Desabilitar e-mails automáticos do ZapSign';
echo '</label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<button class="btn btnPrimary" type="submit">Criar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

// Detalhar doc

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Detalhar documento (GET /api/v1/docs/{doc_token}/)</div>';

echo '<form method="post" action="/admin_zapsign_detail_doc_post.php" style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="doc_token" required placeholder="doc_token" style="flex:1;min-width:280px">';
echo '<button class="btn" type="submit">Detalhar</button>';
echo '</form>';

echo '</section>';

echo '</div>';

view_footer();
