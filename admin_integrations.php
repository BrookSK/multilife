<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$settings = admin_settings_get_prefix('');

function s(array $settings, string $key): string
{
    return isset($settings[$key]) ? (string)$settings[$key] : '';
}

view_header('Integrações');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Integrações (Credenciais)</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Evolution v1, OpenAI e ZapSign (e outros stubs).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">Config</a>';
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/admin_integrations_post.php" style="display:grid;gap:14px;max-width:980px">';

echo '<div style="font-weight:800">Evolution API (v1)</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Base URL<input name="settings[evolution.base_url]" value="' . h(s($settings,'evolution.base_url')) . '" placeholder="https://seu-servidor" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">API Key<input name="settings[evolution.api_key]" value="' . h(s($settings,'evolution.api_key')) . '" placeholder="apikey" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Instance<input name="settings[evolution.instance]" value="' . h(s($settings,'evolution.instance')) . '" placeholder="nome-da-instancia" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<div style="font-weight:800;margin-top:8px">OpenAI</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">API Key<input name="settings[openai.api_key]" value="' . h(s($settings,'openai.api_key')) . '" placeholder="sk-..." style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Base URL<input name="settings[openai.base_url]" value="' . h(s($settings,'openai.base_url')) . '" placeholder="https://api.openai.com" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Model<input name="settings[openai.model]" value="' . h(s($settings,'openai.model')) . '" placeholder="gpt-4o-mini" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<div style="font-weight:800;margin-top:8px">ZapSign</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">API Token<input name="settings[zapsign.api_token]" value="' . h(s($settings,'zapsign.api_token')) . '" placeholder="token" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Base URL<input name="settings[zapsign.base_url]" value="' . h(s($settings,'zapsign.base_url')) . '" placeholder="https://api.zapsign.com.br" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/integration_jobs_enqueue_demo.php">Criar job demo</a>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
