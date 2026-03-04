<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$path = is_string($path) ? $path : '';

$tabs = [
    ['key' => 'whatsapp_instances', 'label' => 'WhatsApp Instâncias', 'href' => '/admin_whatsapp_instances.php'],
    ['key' => 'whatsapp_console', 'label' => 'WhatsApp Console', 'href' => '/admin_whatsapp_console.php'],
    ['key' => 'openai', 'label' => 'OpenAI', 'href' => '/admin_openai_console.php'],
    ['key' => 'zapsign', 'label' => 'ZapSign', 'href' => '/admin_zapsign_console.php'],
    ['key' => 'credentials', 'label' => 'Credenciais APIs', 'href' => '/admin_integrations.php'],
    ['key' => 'smtp', 'label' => 'SMTP', 'href' => '/admin_settings.php'],
    ['key' => 'jobs', 'label' => 'Jobs', 'href' => '/integration_jobs_list.php'],
    ['key' => 'logs', 'label' => 'Logs Técnicos', 'href' => '/tech_logs_list.php'],
];

view_header('Integrações');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Integrações</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Acesse WhatsApp, OpenAI, ZapSign, SMTP, Jobs e Logs por abas.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
foreach ($tabs as $t) {
    $isActive = $path === $t['href'];
    $cls = 'btn' . ($isActive ? ' btnPrimary' : '');
    echo '<a class="' . $cls . '" href="' . h($t['href']) . '">' . h($t['label']) . '</a>';
}

echo '</div>';
echo '<div style="height:14px"></div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:13px;line-height:1.6">Dica: configure também <b>Token do CRON</b> e <b>SMTP</b> em Configurações do Admin para automatizações do fluxo de captação.</div>';
echo '</section>';

echo '</div>';

view_footer();
