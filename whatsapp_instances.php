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
$debugInfo = [];
try {
    $fetchRes = $api->fetchInstances($instance);
    $debugInfo[] = 'fetchInstances executado - Status: ' . ($fetchRes['status'] ?? 'N/A');
    $debugInfo[] = 'fetchInstances JSON: ' . json_encode($fetchRes['json'] ?? null);
    
    // Só considerar que existe se status for 2xx E tiver dados
    if (isset($fetchRes['status']) && $fetchRes['status'] >= 200 && $fetchRes['status'] < 300) {
        if (isset($fetchRes['json']) && is_array($fetchRes['json']) && count($fetchRes['json']) > 0) {
            $instanceExists = true;
            $debugInfo[] = 'Instância encontrada: SIM';
        } else {
            $debugInfo[] = 'Instância encontrada: NÃO (resposta vazia)';
        }
    } else {
        $debugInfo[] = 'Instância encontrada: NÃO (status ' . ($fetchRes['status'] ?? 'N/A') . ' indica erro)';
    }
} catch (Throwable $e) {
    // Instância não existe ou erro ao buscar
    $instanceExists = false;
    $debugInfo[] = 'Erro ao buscar instância: ' . $e->getMessage();
}

// Se não existe, criar a instância automaticamente
if (!$instanceExists) {
    $debugInfo[] = 'Tentando criar instância automaticamente...';
    try {
        // Payload mínimo conforme documentação
        $createPayload = [
            'instanceName' => $instance,
            'qrcode' => true,
        ];
        $debugInfo[] = 'Payload: ' . json_encode($createPayload);
        $createRes = $api->createInstanceBasic($createPayload);
        $debugInfo[] = 'createInstanceBasic executado - Status: ' . ($createRes['status'] ?? 'N/A');
        
        if (isset($createRes['status']) && $createRes['status'] >= 200 && $createRes['status'] < 300) {
            // Aguardar criação
            sleep(3);
            $instanceExists = true;
            
            // Verificar se QR Code veio na resposta
            if (isset($createRes['json']['qrcode'])) {
                $qrCode = $createRes['json'];
            }
            
            flash_set('success', 'Instância "' . $instance . '" criada com sucesso!');
        } else {
            $errorMsg = 'Falha ao criar instância. Status: ' . ($createRes['status'] ?? 'desconhecido');
            if (isset($createRes['json']['message'])) {
                $errorMsg .= ' - ' . (is_array($createRes['json']['message']) ? implode(', ', $createRes['json']['message']) : $createRes['json']['message']);
            }
            $errorMsg .= '<br><br><strong>Debug - Resposta completa da API:</strong><br><pre style="background:#000;color:#0f0;padding:10px;border-radius:5px;overflow:auto;max-height:300px">' . json_encode($createRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
            $error = $errorMsg;
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
            // Instância existe, tentar reiniciar antes de conectar
            $debugInfo[] = 'Instância existe, reiniciando antes de conectar...';
            try {
                $restartRes = $api->restartInstance();
                $debugInfo[] = 'Restart executado - Status: ' . ($restartRes['status'] ?? 'N/A');
                sleep(2); // Aguardar reinicialização
            } catch (Throwable $e) {
                $debugInfo[] = 'Aviso: Não foi possível reiniciar - ' . $e->getMessage();
            }
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
                    // Instância criada com sucesso
                    // O QR Code pode vir na resposta da criação se qrcode:true
                    if (isset($createRes['json']['qrcode'])) {
                        $qrCode = $createRes['json'];
                        $canGenerateQr = false; // Não precisa gerar, já veio
                        flash_set('success', 'Instância "' . $instance . '" criada com sucesso!');
                    } else {
                        // QR Code não veio, aguardar e tentar conectar
                        sleep(5);
                        $canGenerateQr = true;
                        flash_set('success', 'Instância "' . $instance . '" criada! Conectando...');
                    }
                } else {
                    $errorMsg = 'Falha ao criar instância. Status: ' . ($createRes['status'] ?? 'desconhecido');
                    if (isset($createRes['json']['message'])) {
                        $errorMsg .= ' - ' . (is_array($createRes['json']['message']) ? implode(', ', $createRes['json']['message']) : $createRes['json']['message']);
                    }
                    $errorMsg .= '<br><br><strong>Debug - Resposta completa da API:</strong><br><pre style="background:#000;color:#0f0;padding:10px;border-radius:5px;overflow:auto;max-height:300px">' . json_encode($createRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
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
            $debugInfo[] = 'Chamando generateQrCode (GET /instance/connect)...';
            $qrResponse = $api->generateQrCode();
            $debugInfo[] = 'generateQrCode executado - Status: ' . ($qrResponse['status'] ?? 'N/A');
            
            // Verificar se houve erro 404
            if (isset($qrResponse['status']) && $qrResponse['status'] === 404) {
                $errorMsg = 'Erro 404 ao conectar à instância.';
                $errorMsg .= '<br><br><strong>Debug - Resposta:</strong><br><pre style="background:#000;color:#0f0;padding:10px;border-radius:5px;overflow:auto;max-height:300px">' . json_encode($qrResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                $error = $errorMsg;
            } else if (isset($qrResponse['status']) && $qrResponse['status'] >= 200 && $qrResponse['status'] < 300) {
                // Sucesso - a resposta vem em $qrResponse['json']
                if (isset($qrResponse['json'])) {
                    $qrCode = $qrResponse['json'];
                    $debugInfo[] = 'QR Code/Pairing Code recebido com sucesso!';
                } else {
                    $qrCode = $qrResponse;
                }
            } else {
                $errorMsg = 'Erro ao gerar QR Code. Status: ' . ($qrResponse['status'] ?? 'desconhecido');
                $errorMsg .= '<br><br><strong>Debug - Resposta:</strong><br><pre style="background:#000;color:#0f0;padding:10px;border-radius:5px;overflow:auto;max-height:300px">' . json_encode($qrResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                $error = $errorMsg;
            }
        } catch (Throwable $e) {
            $error = 'Exceção ao gerar QR Code: ' . $e->getMessage();
            $debugInfo[] = 'Exceção: ' . $e->getMessage();
        }
    }
}

view_header('Instâncias WhatsApp');

echo '<div class="grid">';

// Mostrar debug info
if (!empty($debugInfo)) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:700;margin-bottom:10px;color:hsl(var(--primary))">🔍 Debug - Processo de Verificação/Criação</div>';
    echo '<div style="background:#f5f5f5;padding:12px;border-radius:8px;font-family:monospace;font-size:12px">';
    foreach ($debugInfo as $info) {
        echo '<div style="margin-bottom:4px">• ' . h($info) . '</div>';
    }
    echo '</div>';
    
    // Se instância existe, mostrar mensagem positiva
    if ($instanceExists) {
        echo '<div style="margin-top:12px;padding:10px;background:hsla(var(--success)/.10);border:1px solid hsla(var(--success)/.20);border-radius:8px;color:hsl(var(--success))">';
        echo '<strong>✓ Boa notícia!</strong> A instância "' . h($instance) . '" já existe na Evolution API.';
        echo '<br>Clique em "Gerar QR Code" abaixo para conectar.';
        echo '</div>';
    }
    
    echo '</section>';
}

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
    echo '<div style="margin-top:14px;padding:12px;background:hsla(var(--muted)/.25);border-radius:8px">';
    echo '<strong>Solução:</strong> A instância "' . h($instance) . '" precisa ser criada manualmente na Evolution API.';
    echo '<br><br>';
    echo '<strong>Opção 1:</strong> Acesse o painel da Evolution API em: <a href="http://31.97.83.150:8080/manager/" target="_blank" style="color:hsl(var(--primary))">http://31.97.83.150:8080/manager/</a>';
    echo '<br><strong>Opção 2:</strong> Use a página de administração: <a href="/admin_whatsapp_instances.php" class="btn" style="display:inline-flex;margin-top:8px">Gerenciar Instâncias</a>';
    echo '</div>';
    echo '</section>';
}

// Status da conexão
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:14px">Status da Instância: ' . h($instance) . '</div>';

// Mostrar aviso se instância não existe
if (!$instanceExists && $error === null) {
    echo '<div style="padding:12px;border-radius:10px;background:hsla(var(--warning)/.10);border:1px solid hsla(var(--warning)/.20);margin-bottom:14px">';
    echo '<div style="font-weight:700;color:hsl(var(--warning));margin-bottom:6px">⚠ Instância Não Encontrada</div>';
    echo '<div style="font-size:13px;color:hsl(var(--foreground))">';
    echo 'A instância "' . h($instance) . '" não existe na Evolution API.<br>';
    echo 'Clique em "Gerar QR Code" abaixo para criar automaticamente.';
    echo '</div>';
    echo '</div>';
}


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
        // Remover prefixo duplicado se existir
        $qrBase64Clean = $qrBase64;
        if (strpos($qrBase64, 'data:image/png;base64,') === 0) {
            $qrBase64Clean = substr($qrBase64, strlen('data:image/png;base64,'));
        }
        
        echo '<div style="padding:20px;background:#fff;border-radius:12px;display:inline-block">';
        echo '<img src="data:image/png;base64,' . h($qrBase64Clean) . '" alt="QR Code" style="max-width:400px;display:block">';
        echo '</div>';
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
