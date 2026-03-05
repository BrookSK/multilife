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

// Primeiro, verificar se a instância existe
$instanceExists = false;
try {
    $fetchRes = $api->fetchInstances($instance);
    if (isset($fetchRes['json']) && is_array($fetchRes['json']) && count($fetchRes['json']) > 0) {
        $instanceExists = true;
    }
} catch (Throwable $e) {
    // Instância não existe ou erro ao buscar
    $instanceExists = false;
}

// Se não existe, criar a instância automaticamente
if (!$instanceExists) {
    try {
        // Payload mínimo conforme documentação
        $createPayload = [
            'instanceName' => $instance,
            'qrcode' => true,
        ];
        $createRes = $api->createInstanceBasic($createPayload);
        
        if (isset($createRes['status']) && $createRes['status'] >= 200 && $createRes['status'] < 300) {
            // Aguardar criação
            sleep(3);
            $instanceExists = true;
            flash_set('success', 'Instância "' . $instance . '" criada com sucesso!');
        } else {
            $error = 'Falha ao criar instância. Status: ' . ($createRes['status'] ?? 'desconhecido');
        }
    } catch (Throwable $e) {
        $error = 'Erro ao criar instância: ' . $e->getMessage();
    }
}

// Só tentar obter status se a instância existe
if ($instanceExists && $error === null) {
    try {
        $statusResponse = $api->getConnectionStatus();
        if (isset($statusResponse['json'])) {
            $connectionStatus = $statusResponse['json'];
        } else {
            $connectionStatus = $statusResponse;
        }
    } catch (Throwable $e) {
        // Ignorar erro de status se instância acabou de ser criada
        $connectionStatus = null;
    }
}

// Gerar QR Code se solicitado
if (isset($_GET['generate_qr']) && $_GET['generate_qr'] === '1' && $error === null) {
    // Verificar novamente se a instância existe antes de gerar QR
    $canGenerateQr = false;
    try {
        $checkRes = $api->fetchInstances($instance);
        if (isset($checkRes['json']) && is_array($checkRes['json']) && count($checkRes['json']) > 0) {
            $canGenerateQr = true;
        } else {
            // Instância não existe, tentar criar agora
            try {
                // Payload mínimo conforme documentação
                $createPayload = [
                    'instanceName' => $instance,
                    'qrcode' => true,
                ];
                $createRes = $api->createInstanceBasic($createPayload);
                
                if (isset($createRes['status']) && $createRes['status'] >= 200 && $createRes['status'] < 300) {
                    sleep(3);
                    $canGenerateQr = true;
                    flash_set('success', 'Instância "' . $instance . '" criada! Gerando QR Code...');
                } else {
                    $errorMsg = 'Falha ao criar instância. Status: ' . ($createRes['status'] ?? 'desconhecido');
                    if (isset($createRes['json']['message'])) {
                        $errorMsg .= ' - ' . (is_array($createRes['json']['message']) ? implode(', ', $createRes['json']['message']) : $createRes['json']['message']);
                    }
                    $error = $errorMsg;
                }
            } catch (Throwable $e) {
                $error = 'Erro ao criar instância: ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $error = 'Erro ao verificar instância: ' . $e->getMessage();
    }
    
    // Só gerar QR Code se a instância existe/foi criada
    if ($canGenerateQr && $error === null) {
        try {
            $qrResponse = $api->generateQrCode();
            // A resposta vem em $qrResponse['json']
            if (isset($qrResponse['json'])) {
                $qrCode = $qrResponse['json'];
            } else {
                $qrCode = $qrResponse;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
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

// Exibir mensagem de sucesso se houver
$flashSuccess = flash_get('success');
if ($flashSuccess !== null) {
    echo '<div class="alertSuccess" style="margin-top:14px">' . h($flashSuccess) . '</div>';
}

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
    
    // Tentar extrair QR Code de diferentes formatos possíveis
    $qrBase64 = null;
    $qrCodeText = null;
    $pairingCode = null;
    
    // Formato 1: qrcode.base64
    if (isset($qrCode['qrcode']['base64'])) {
        $qrBase64 = (string)$qrCode['qrcode']['base64'];
    }
    // Formato 2: base64 direto
    elseif (isset($qrCode['base64'])) {
        $qrBase64 = (string)$qrCode['base64'];
    }
    
    // Formato 3: qrcode.code
    if (isset($qrCode['qrcode']['code'])) {
        $qrCodeText = (string)$qrCode['qrcode']['code'];
    }
    // Formato 4: code direto
    elseif (isset($qrCode['code'])) {
        $qrCodeText = (string)$qrCode['code'];
    }
    
    // Formato 5: pairingCode
    if (isset($qrCode['pairingCode'])) {
        $pairingCode = (string)$qrCode['pairingCode'];
    }
    
    // Exibir QR Code em imagem se disponível
    if ($qrBase64 !== null && $qrBase64 !== '') {
        echo '<img src="data:image/png;base64,' . h($qrBase64) . '" alt="QR Code" style="max-width:400px;border:1px solid hsl(var(--border));border-radius:10px">';
        echo '<div style="margin-top:14px;color:hsl(var(--muted-foreground));font-size:13px">Escaneie este QR Code com o WhatsApp do seu celular</div>';
        echo '<div style="margin-top:10px">';
        echo '<a class="btn" href="/whatsapp_instances.php">Atualizar Status</a>';
        echo '</div>';
    }
    // Exibir código de texto se disponível
    elseif ($qrCodeText !== null && $qrCodeText !== '') {
        echo '<div style="font-family:monospace;font-size:11px;word-break:break-all;padding:12px;background:hsla(var(--muted)/.25);border-radius:8px">';
        echo h($qrCodeText);
        echo '</div>';
        echo '<div style="margin-top:14px;color:hsl(var(--muted-foreground));font-size:13px">Use este código no WhatsApp do seu celular</div>';
        echo '<div style="margin-top:10px">';
        echo '<a class="btn" href="/whatsapp_instances.php">Atualizar Status</a>';
        echo '</div>';
    }
    // Exibir pairing code se disponível
    elseif ($pairingCode !== null && $pairingCode !== '') {
        echo '<div style="padding:20px;background:hsla(var(--primary)/.10);border-radius:12px;border:2px solid hsl(var(--primary))">';
        echo '<div style="font-size:32px;font-weight:900;letter-spacing:8px;color:hsl(var(--primary))">' . h($pairingCode) . '</div>';
        echo '</div>';
        echo '<div style="margin-top:14px;color:hsl(var(--muted-foreground));font-size:13px">Digite este código no WhatsApp do seu celular</div>';
        echo '<div style="margin-top:10px">';
        echo '<a class="btn" href="/whatsapp_instances.php">Atualizar Status</a>';
        echo '</div>';
    }
    // Debug: mostrar estrutura da resposta
    else {
        echo '<div class="alertError">QR Code não disponível no formato esperado.</div>';
        echo '<details style="margin-top:14px;text-align:left">';
        echo '<summary style="cursor:pointer;font-weight:700">Debug: Ver resposta da API</summary>';
        echo '<pre style="background:hsla(var(--muted)/.25);padding:12px;border-radius:8px;overflow:auto;font-size:11px;margin-top:10px">';
        echo h(json_encode($qrCode, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo '</pre>';
        echo '</details>';
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
