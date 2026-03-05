<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

view_header('Gerenciar Instâncias Evolution');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Instâncias Evolution API</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Crie e gerencie instâncias WhatsApp.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Buscar configurações Evolution
$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$currentInstance = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey)) {
    echo '<section class="card col12">';
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
    echo 'Configure as credenciais da Evolution API em <a href="/admin_settings.php">Configurações → Evolution</a>';
    echo '</div>';
    echo '</section>';
    echo '</div>';
    view_footer();
    exit;
}

// Buscar instâncias
$instances = [];
try {
    $ch = curl_init($baseUrl . '/instance/fetchInstances');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $instances = $data ?? [];
    }
} catch (Exception $e) {
    // Ignorar erro
}

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">';
echo '<div style="font-weight:700;font-size:16px">Instâncias Ativas</div>';
echo '<a class="btn btnPrimary" href="/evolution_instance_create.php">Nova Instância</a>';
echo '</div>';

if (empty($instances)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhuma instância encontrada</div>';
} else {
    echo '<div style="display:grid;gap:12px">';
    foreach ($instances as $instance) {
        $instanceName = $instance['instance']['instanceName'] ?? '';
        $status = $instance['instance']['status'] ?? 'disconnected';
        $isConnected = $status === 'open';
        $isCurrent = $instanceName === $currentInstance;
        
        $statusColor = $isConnected ? 'hsl(142, 76%, 36%)' : 'hsl(var(--destructive))';
        $statusText = $isConnected ? 'Conectado' : 'Desconectado';
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px;border:1px solid hsl(var(--border));border-radius:8px' . ($isCurrent ? ';background:hsla(var(--primary)/.05)' : '') . '">';
        echo '<div style="flex:1">';
        echo '<div style="font-weight:600">' . h($instanceName) . '</div>';
        echo '<div style="font-size:12px;margin-top:4px">';
        echo '<span style="color:' . $statusColor . ';font-weight:600">● ' . $statusText . '</span>';
        if ($isCurrent) {
            echo ' <span style="margin-left:8px;padding:2px 8px;background:hsl(var(--primary));color:white;border-radius:4px;font-size:11px">ATUAL</span>';
        }
        echo '</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        if (!$isConnected) {
            echo '<a href="/evolution_qrcode.php?instance=' . urlencode($instanceName) . '" class="btn btnPrimary" style="font-size:12px;padding:6px 12px">Conectar</a>';
        } else {
            echo '<a href="/chat_sync_whatsapp.php" class="btn btnPrimary" style="font-size:12px;padding:6px 12px">Sincronizar Conversas</a>';
            if ($instanceName === 'multilife') {
                echo '<a href="/evolution_instance_disconnect.php?instance=' . urlencode($instanceName) . '" class="btn" style="font-size:12px;padding:6px 12px;background:hsl(var(--destructive));color:white" onclick="return confirm(\'Deseja desconectar esta instância?\')">Desconectar</a>';
            }
        }
        echo '<a href="/evolution_instance_delete.php?instance=' . urlencode($instanceName) . '" class="btn" style="font-size:12px;padding:6px 12px" onclick="return confirm(\'Deseja excluir esta instância?\')">Excluir</a>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
