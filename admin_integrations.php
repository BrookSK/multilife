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

function integrations_tabs(): void
{
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<a class="btn" href="/admin_whatsapp_instances.php">WhatsApp Instâncias</a>';
    echo '<a class="btn" href="/admin_whatsapp_console.php">WhatsApp Console</a>';
    echo '<a class="btn" href="/admin_openai_console.php">OpenAI</a>';
    echo '<a class="btn" href="/admin_zapsign_console.php">ZapSign</a>';
    echo '<a class="btn btnPrimary" href="/admin_integrations.php">Credenciais APIs</a>';
    echo '<a class="btn" href="/admin_settings.php">SMTP</a>';
    echo '<a class="btn" href="/specialty_minimums_list.php">Mínimos</a>';
    echo '<a class="btn" href="/appointment_value_authorizations_list.php">Autorizações</a>';
    echo '<a class="btn" href="/integration_jobs_list.php">Jobs</a>';
    echo '<a class="btn" href="/tech_logs_list.php">Logs Técnicos</a>';
    echo '</div>';
}

view_header('Integrações');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Integrações (Credenciais)</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Evolution v1, OpenAI e ZapSign (e outros stubs).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_integrations_hub.php">Hub</a>';
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
integrations_tabs();
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/admin_integrations_post.php" style="display:grid;gap:14px;max-width:980px">';

echo '<div style="font-weight:900">Evolution API (v1)</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Base URL<input name="settings[evolution.base_url]" value="' . h(s($settings,'evolution.base_url')) . '" placeholder="https://seu-servidor"></label></div>';
echo '<div class="col6"><label>API Key<input type="password" name="settings[evolution.api_key]" value="" placeholder="(mantém se vazio)"></label></div>';
echo '<div class="col6"><label>Instance<input name="settings[evolution.instance]" value="' . h(s($settings,'evolution.instance')) . '" placeholder="nome-da-instancia"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:8px">OpenAI</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>API Key<input type="password" name="settings[openai.api_key]" value="" placeholder="(mantém se vazio)"></label></div>';
echo '<div class="col6"><label>Base URL<input name="settings[openai.base_url]" value="' . h(s($settings,'openai.base_url')) . '" placeholder="https://api.openai.com"></label></div>';
echo '<div class="col6"><label>Model<input name="settings[openai.model]" value="' . h(s($settings,'openai.model')) . '" placeholder="gpt-4o-mini"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:8px">ZapSign</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>API Token<input type="password" name="settings[zapsign.api_token]" value="" placeholder="(mantém se vazio)"></label></div>';
echo '<div class="col6"><label>Base URL<input name="settings[zapsign.base_url]" value="' . h(s($settings,'zapsign.base_url')) . '" placeholder="https://api.zapsign.com.br"></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:10px">';
echo '<a class="btn" href="/integration_jobs_enqueue_demo.php">Criar job demo</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
