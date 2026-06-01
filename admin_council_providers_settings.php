<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/council_validator.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

// Carrega configurações atuais
$cfg = admin_settings_get_prefix('council_provider.');

$consultarioKey      = $cfg['council_provider.consultario.api_key'] ?? '';
$consultarioBaseUrl  = $cfg['council_provider.consultario.base_url'] ?? 'https://api.consultar.io/v1';
$consultarioEnabled  = ($cfg['council_provider.consultario.enabled'] ?? '0') === '1';
$consultarioPriority = (int)($cfg['council_provider.consultario.priority'] ?? 10);

$infosimplesToken    = $cfg['council_provider.infosimples.api_token'] ?? '';
$infosimplesBaseUrl  = $cfg['council_provider.infosimples.base_url'] ?? 'https://api.infosimples.com/api/v2';
$infosimplesEnabled  = ($cfg['council_provider.infosimples.enabled'] ?? '0') === '1';
$infosimplesPriority = (int)($cfg['council_provider.infosimples.priority'] ?? 20);

$portalDirectEnabled  = ($cfg['council_provider.portal_direct.enabled'] ?? '1') === '1';
$portalDirectPriority = (int)($cfg['council_provider.portal_direct.priority'] ?? 99);

view_header('Configurar Provedores de Validação');

echo '<div class="grid">';

// Cabeçalho
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Configurar Provedores</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Configure as credenciais e prioridades dos provedores de validação de registros profissionais.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px">';
echo '<a class="btn" href="/admin_council_providers.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<form method="post" action="/admin_council_providers_post.php">';

// Consultar.IO
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px;font-size:16px">Consultar.IO</div>';
echo '<div style="display:grid;gap:12px;max-width:600px">';

echo '<label style="display:flex;align-items:center;gap:8px">';
echo '<input type="checkbox" name="consultario_enabled" value="1"' . ($consultarioEnabled ? ' checked' : '') . '>';
echo '<span style="font-weight:600">Habilitado</span>';
echo '</label>';

echo '<div>';
echo '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">API Key</label>';
echo '<input type="password" name="consultario_api_key" value="" placeholder="' . ($consultarioKey !== '' ? '••••••••' : 'Insira a chave de API') . '" style="width:100%">';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Deixe em branco para manter a chave atual.</div>';
echo '</div>';

echo '<div>';
echo '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">URL Base</label>';
echo '<input type="text" name="consultario_base_url" value="' . h($consultarioBaseUrl) . '" style="width:100%">';
echo '</div>';

echo '<div>';
echo '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">Prioridade (menor = primeiro)</label>';
echo '<input type="number" name="consultario_priority" value="' . $consultarioPriority . '" min="1" max="999" style="width:100px">';
echo '</div>';

echo '</div>';
echo '</section>';

// Infosimples
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px;font-size:16px">Infosimples</div>';
echo '<div style="display:grid;gap:12px;max-width:600px">';

echo '<label style="display:flex;align-items:center;gap:8px">';
echo '<input type="checkbox" name="infosimples_enabled" value="1"' . ($infosimplesEnabled ? ' checked' : '') . '>';
echo '<span style="font-weight:600">Habilitado</span>';
echo '</label>';

echo '<div>';
echo '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">Token de API</label>';
echo '<input type="password" name="infosimples_api_token" value="" placeholder="' . ($infosimplesToken !== '' ? '••••••••' : 'Insira o token de API') . '" style="width:100%">';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Deixe em branco para manter o token atual.</div>';
echo '</div>';

echo '<div>';
echo '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">URL Base</label>';
echo '<input type="text" name="infosimples_base_url" value="' . h($infosimplesBaseUrl) . '" style="width:100%">';
echo '</div>';

echo '<div>';
echo '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">Prioridade (menor = primeiro)</label>';
echo '<input type="number" name="infosimples_priority" value="' . $infosimplesPriority . '" min="1" max="999" style="width:100px">';
echo '</div>';

echo '</div>';
echo '</section>';

// Portal Direto
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px;font-size:16px">Portal Direto (Fallback)</div>';
echo '<div style="font-size:14px;color:hsl(var(--muted-foreground));margin-bottom:12px">Consulta diretamente os portais oficiais dos conselhos via scraping. Usado como último recurso.</div>';
echo '<div style="display:grid;gap:12px;max-width:600px">';

echo '<label style="display:flex;align-items:center;gap:8px">';
echo '<input type="checkbox" name="portal_direct_enabled" value="1"' . ($portalDirectEnabled ? ' checked' : '') . '>';
echo '<span style="font-weight:600">Habilitado</span>';
echo '</label>';

echo '<div>';
echo '<label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">Prioridade (menor = primeiro)</label>';
echo '<input type="number" name="portal_direct_priority" value="' . $portalDirectPriority . '" min="1" max="999" style="width:100px">';
echo '</div>';

echo '</div>';
echo '</section>';

// Botão salvar
echo '<section class="card col12">';
echo '<button class="btn btnPrimary" type="submit">Salvar Configurações</button>';
echo '</section>';

echo '</form>';

echo '</div>';

view_footer();
