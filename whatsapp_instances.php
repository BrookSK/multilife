<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

// Verificar se Evolution API está configurada
$baseUrl = admin_setting_get('evolution.base_url', '');
$apiKey = admin_setting_get('evolution.api_key', '');
$instance = admin_setting_get('evolution.instance', '');

if ($baseUrl === '' || $apiKey === '' || $instance === '') {
    flash_set('error', 'Evolution API não configurada. Configure em Configurações.');
    header('Location: /admin_settings.php');
    exit;
}

require_once __DIR__ . '/app/evolution_api_v1.php';

$api = new EvolutionApiV1();
$qrCode = null;
$connectionStatus = null;
$error = null;

// Tentar obter status da conexão
try {
    $connectionStatus = $api->getConnectionStatus();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// Gerar QR Code se solicitado
if (isset($_GET['generate_qr']) && $_GET['generate_qr'] === '1') {
    try {
        $qrCode = $api->generateQrCode();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

view_header('Instâncias WhatsApp');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Instâncias WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Conecte e gerencie suas instâncias do WhatsApp via Evolution API.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_hub.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Exibir erro se houver
if ($error !== null) {
    echo '<section class="card col12">';
    echo '<div class="alertError">';
    echo '<strong>Erro:</strong> ' . h($error);
    echo '</div>';
    echo '</section>';
}

// Status da conexão
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:14px">Status da Instância: ' . h($instance) . '</div>';

if ($connectionStatus !== null && isset($connectionStatus['state'])) {
    $state = (string)$connectionStatus['state'];
    $isConnected = ($state === 'open' || $state === 'connected');
    
    if ($isConnected) {
        echo '<div style="padding:12px;border-radius:10px;background:hsla(var(--success)/.10);border:1px solid hsla(var(--success)/.20)">';
        echo '<div style="font-weight:700;color:hsl(var(--success));margin-bottom:6px">✓ Conectado</div>';
        echo '<div style="font-size:13px;color:hsl(var(--foreground))">';
        echo '<strong>Estado:</strong> ' . h($state);
        if (isset($connectionStatus['instance'])) {
            echo '<br><strong>Instância:</strong> ' . h((string)$connectionStatus['instance']);
        }
        echo '</div>';
        echo '</div>';
    } else {
        echo '<div style="padding:12px;border-radius:10px;background:hsla(var(--warning)/.10);border:1px solid hsla(var(--warning)/.20)">';
        echo '<div style="font-weight:700;color:hsl(var(--warning));margin-bottom:6px">⚠ Desconectado</div>';
        echo '<div style="font-size:13px;color:hsl(var(--foreground))">';
        echo '<strong>Estado:</strong> ' . h($state);
        echo '</div>';
        echo '<div style="margin-top:10px">';
        echo '<a class="btn btnPrimary" href="/whatsapp_instances.php?generate_qr=1">Gerar QR Code para Conectar</a>';
        echo '</div>';
        echo '</div>';
    }
} else {
    echo '<div style="padding:12px;border-radius:10px;background:hsla(var(--muted)/.25);border:1px solid hsl(var(--border))">';
    echo '<div style="font-weight:700;margin-bottom:6px">Status Desconhecido</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Não foi possível obter o status da conexão.</div>';
    echo '<div style="margin-top:10px">';
    echo '<a class="btn btnPrimary" href="/whatsapp_instances.php?generate_qr=1">Gerar QR Code</a>';
    echo '</div>';
    echo '</div>';
}

echo '</section>';

// Exibir QR Code se gerado
if ($qrCode !== null) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:14px">QR Code para Conexão</div>';
    echo '<div style="padding:20px;text-align:center;background:hsl(var(--card));border-radius:12px">';
    
    if (isset($qrCode['base64'])) {
        echo '<img src="data:image/png;base64,' . h((string)$qrCode['base64']) . '" alt="QR Code" style="max-width:400px;border:1px solid hsl(var(--border));border-radius:10px">';
        echo '<div style="margin-top:14px;color:hsl(var(--muted-foreground));font-size:13px">Escaneie este QR Code com o WhatsApp do seu celular</div>';
        echo '<div style="margin-top:10px">';
        echo '<a class="btn" href="/whatsapp_instances.php">Atualizar Status</a>';
        echo '</div>';
    } elseif (isset($qrCode['code'])) {
        echo '<div style="font-family:monospace;font-size:11px;word-break:break-all;padding:12px;background:hsla(var(--muted)/.25);border-radius:8px">';
        echo h((string)$qrCode['code']);
        echo '</div>';
        echo '<div style="margin-top:14px;color:hsl(var(--muted-foreground));font-size:13px">Use este código no WhatsApp do seu celular</div>';
    } else {
        echo '<div class="alertError">QR Code não disponível no formato esperado.</div>';
    }
    
    echo '</div>';
    echo '</section>';
}

// Informações da API
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:14px">Configuração da API</div>';
echo '<div style="font-size:13px;line-height:1.8">';
echo '<strong>Base URL:</strong> ' . h($baseUrl) . '<br>';
echo '<strong>Instância:</strong> ' . h($instance) . '<br>';
echo '<strong>API Key:</strong> ' . str_repeat('•', 40) . '<br>';
echo '</div>';
echo '<div style="margin-top:10px">';
echo '<a class="btn" href="/admin_settings.php">Editar Configurações</a>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
