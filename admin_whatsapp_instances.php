<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

// Verificar se Evolution API está configurada
$baseUrl = admin_setting_get('evolution.base_url', '');
$apiKey = admin_setting_get('evolution.api_key', '');
$instance = admin_setting_get('evolution.instance', '');

if ($baseUrl === '' || $apiKey === '' || $instance === '') {
    flash_set('error', 'Evolution API não configurada. Configure em Configurações.');
    header('Location: /admin_settings.php');
    exit;
}

$instanceFilter = isset($_GET['instance']) ? trim((string)$_GET['instance']) : '';

$evo = new EvolutionApiV1();
$res = $evo->fetchInstances($instanceFilter !== '' ? $instanceFilter : null);

$instances = [];
if (is_array($res['json'])) {
    $instances = $res['json'];
}

view_header('WhatsApp (Evolution)');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">WhatsApp (Evolution)</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Instâncias, QR code, status e ações.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_integrations.php">Credenciais</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/admin_whatsapp_instances.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="instance" value="' . h($instanceFilter) . '" placeholder="Filtrar por instanceName" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

// Criar instância

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Criar instância</div>';

echo '<form method="post" action="/admin_whatsapp_instance_create_post.php" style="display:grid;gap:12px;max-width:920px">';

echo '<div class="grid">';
echo '<div class="col6"><label>Instance name<input name="instanceName" required placeholder="ex: multilife"></label></div>';

echo '<div class="col6"><label>Token (opcional)<input name="token" placeholder="deixe vazio para gerar"></label></div>';

echo '<div class="col6"><label>Número dono (com DDI)<input name="number" placeholder="559999999999"></label></div>';

echo '<div class="col6"><label>Webhook URL (opcional)<input name="webhook" placeholder="https://..."></label></div>';

echo '</div>';

echo '<label class="pill" style="display:flex;align-items:center;gap:10px;padding:12px">';
echo '<input type="checkbox" name="qrcode" value="1" checked> Gerar QR Code ao criar';
echo '</label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<button class="btn btnPrimary" type="submit">Criar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

// Lista

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Instâncias</div>';

echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Instance</th><th>Status</th><th>Dono</th><th>Engine</th><th>Webhook</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';

foreach ($instances as $row) {
    $i = $row['instance'] ?? $row;
    if (!is_array($i)) {
        continue;
    }

    $name = (string)($i['instanceName'] ?? '');
    $status = (string)($i['status'] ?? '');
    $owner = (string)($i['owner'] ?? '');
    $engine = '';
    $wh = '';
    if (isset($i['integration']) && is_array($i['integration'])) {
        $engine = (string)($i['integration']['integration'] ?? '');
        $wh = (string)($i['integration']['webhook_wa_business'] ?? '');
    }

    echo '<tr>';
    echo '<td style="font-weight:700">' . h($name) . '</td>';
    echo '<td>' . h($status) . '</td>';
    echo '<td>' . h($owner) . '</td>';
    echo '<td>' . h($engine) . '</td>';
    echo '<td>' . h($wh) . '</td>';
    echo '<td style="text-align:right">';

    echo '<a class="btn" href="/admin_whatsapp_instance_view.php?instance=' . urlencode($name) . '">Abrir</a> ';

    echo '</td>';
    echo '</tr>';
}

if (count($instances) === 0) {
    echo '<tr><td colspan="6" class="pill" style="display:table-cell;padding:12px">Sem instâncias (ou resposta vazia).</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
