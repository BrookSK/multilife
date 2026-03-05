<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

view_header('WhatsApp - Central de Gerenciamento');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">WhatsApp - Central de Gerenciamento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gerencie instâncias, grupos e configurações do WhatsApp via Evolution API.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Cards de acesso rápido
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:14px">Acesso Rápido</div>';
echo '<div class="kpiGrid" style="grid-template-columns:repeat(3,minmax(0,1fr))">';

// Card 1: Instâncias
echo '<a href="/whatsapp_instances.php" class="kpiCard" style="text-decoration:none">';
echo '<div class="kpiBody">';
echo '<div class="kpiTop">';
echo '<div class="kpiIcon" style="background:hsla(var(--primary)/.10);color:hsl(var(--primary))">📱</div>';
echo '</div>';
echo '<div class="kpiValue">Instâncias</div>';
echo '<div class="kpiLabel">Conectar e gerenciar instâncias WhatsApp</div>';
echo '</div>';
echo '</a>';

// Card 2: Grupos
echo '<a href="/whatsapp_groups_list.php" class="kpiCard" style="text-decoration:none">';
echo '<div class="kpiBody">';
echo '<div class="kpiTop">';
echo '<div class="kpiIcon" style="background:hsla(var(--success)/.10);color:hsl(var(--success))">👥</div>';
echo '</div>';
echo '<div class="kpiValue">Grupos</div>';
echo '<div class="kpiLabel">Gerenciar grupos de WhatsApp</div>';
echo '</div>';
echo '</a>';

// Card 3: Mensagens
echo '<a href="/whatsapp_messages_log.php" class="kpiCard" style="text-decoration:none">';
echo '<div class="kpiBody">';
echo '<div class="kpiTop">';
echo '<div class="kpiIcon" style="background:hsla(var(--info)/.10);color:hsl(var(--info))">💬</div>';
echo '</div>';
echo '<div class="kpiValue">Mensagens</div>';
echo '<div class="kpiLabel">Histórico de mensagens enviadas</div>';
echo '</div>';
echo '</a>';

echo '</div>';
echo '</section>';

// Informações da API
$baseUrl = admin_setting_get('evolution.base_url', '');
$apiKey = admin_setting_get('evolution.api_key', '');
$instance = admin_setting_get('evolution.instance', '');

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:14px">Status da Conexão Evolution API</div>';

if ($baseUrl !== '' && $apiKey !== '' && $instance !== '') {
    echo '<div style="padding:12px;border-radius:10px;background:hsla(var(--success)/.10);border:1px solid hsla(var(--success)/.20)">';
    echo '<div style="font-weight:700;color:hsl(var(--success));margin-bottom:6px">✓ API Configurada</div>';
    echo '<div style="font-size:13px;color:hsl(var(--foreground))">';
    echo '<strong>Base URL:</strong> ' . h($baseUrl) . '<br>';
    echo '<strong>Instância:</strong> ' . h($instance);
    echo '</div>';
    echo '</div>';
} else {
    echo '<div style="padding:12px;border-radius:10px;background:hsla(var(--warning)/.10);border:1px solid hsla(var(--warning)/.20)">';
    echo '<div style="font-weight:700;color:hsl(var(--warning));margin-bottom:6px">⚠ API Não Configurada</div>';
    echo '<div style="font-size:13px;color:hsl(var(--foreground))">Configure a Evolution API em <a href="/admin_settings.php" style="color:hsl(var(--primary))">Configurações</a>.</div>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
