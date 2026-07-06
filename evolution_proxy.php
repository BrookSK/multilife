<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$instanceName = $_GET['instance'] ?? '';

$baseUrl = trim((string)admin_setting_get('evolution.base_url', ''));
$apiKey = trim((string)admin_setting_get('evolution.api_key', ''));

if (empty($baseUrl) || empty($apiKey)) {
    echo json_encode(['error' => 'Evolution API não configurada']);
    exit;
}

if (empty($instanceName)) {
    echo json_encode(['error' => 'Nome da instância não informado']);
    exit;
}

try {
    $url = '';
    
    switch ($action) {
        case 'connect':
            // Antes de conectar, garantir que webhook está configurado
            $publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
            if ($publicUrl === '') {
                $publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
            $whUrl = rtrim($publicUrl, '/') . '/chat_webhook.php';
            
            $whPayloadData = [
                'enabled' => true,
                'url' => $whUrl,
                'webhook_by_events' => false,
                'webhook_base64' => true,
                'events' => [
                    'MESSAGES_UPSERT',
                    'SEND_MESSAGE',
                    'CONTACTS_UPSERT',
                    'CONTACTS_UPDATE',
                    'CONNECTION_UPDATE',
                    'GROUPS_UPSERT',
                    'GROUP_UPDATE',
                    'GROUP_PARTICIPANTS_UPDATE',
                    'QRCODE_UPDATED',
                ],
            ];
            
            $whConfigured = false;
            
            // Tentar POST /webhook/set/{instance}
            $whSetUrl = $baseUrl . '/webhook/set/' . urlencode($instanceName);
            $chWh = curl_init($whSetUrl);
            curl_setopt($chWh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chWh, CURLOPT_POST, true);
            curl_setopt($chWh, CURLOPT_POSTFIELDS, json_encode($whPayloadData));
            curl_setopt($chWh, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
            curl_setopt($chWh, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($chWh, CURLOPT_TIMEOUT, 10);
            $whResp1 = curl_exec($chWh);
            $whCode1 = curl_getinfo($chWh, CURLINFO_HTTP_CODE);
            curl_close($chWh);
            
            if ($whCode1 >= 200 && $whCode1 < 300) {
                $whConfigured = true;
            }
            
            // Se POST falhou, tentar PUT /webhook/set/{instance} com wrapper
            if (!$whConfigured) {
                $whPayload2 = ['webhook' => $whPayloadData];
                $chWh2 = curl_init($whSetUrl);
                curl_setopt($chWh2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chWh2, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($chWh2, CURLOPT_POSTFIELDS, json_encode($whPayload2));
                curl_setopt($chWh2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
                curl_setopt($chWh2, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($chWh2, CURLOPT_TIMEOUT, 10);
                $whResp2 = curl_exec($chWh2);
                $whCode2 = curl_getinfo($chWh2, CURLINFO_HTTP_CODE);
                curl_close($chWh2);
                
                if ($whCode2 >= 200 && $whCode2 < 300) {
                    $whConfigured = true;
                }
            }
            
            // Se ambos falharam, tentar PUT /webhook/{instance} (sem /set/)
            if (!$whConfigured) {
                $whUrl3 = $baseUrl . '/webhook/' . urlencode($instanceName);
                $chWh3 = curl_init($whUrl3);
                curl_setopt($chWh3, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chWh3, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($chWh3, CURLOPT_POSTFIELDS, json_encode($whPayloadData));
                curl_setopt($chWh3, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
                curl_setopt($chWh3, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($chWh3, CURLOPT_TIMEOUT, 10);
                $whResp3 = curl_exec($chWh3);
                $whCode3 = curl_getinfo($chWh3, CURLINFO_HTTP_CODE);
                curl_close($chWh3);
                
                if ($whCode3 >= 200 && $whCode3 < 300) {
                    $whConfigured = true;
                }
            }
            
            $url = $baseUrl . '/instance/connect/' . urlencode($instanceName);
            break;

        case 'status':
            $url = $baseUrl . '/instance/connectionState/' . urlencode($instanceName);
            break;

        case 'debug_webhook':
            // Testa todos os endpoints possíveis de webhook e retorna os resultados
            $publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
            if ($publicUrl === '') {
                $publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
            $whUrl = rtrim($publicUrl, '/') . '/chat_webhook.php';
            $results = [];
            
            $whPayloadData = [
                'enabled' => true,
                'url' => $whUrl,
                'webhook_by_events' => false,
                'webhook_base64' => true,
                'events' => [
                    'MESSAGES_UPSERT',
                    'SEND_MESSAGE',
                    'CONTACTS_UPSERT',
                    'CONTACTS_UPDATE',
                    'CONNECTION_UPDATE',
                    'GROUPS_UPSERT',
                    'GROUP_UPDATE',
                    'GROUP_PARTICIPANTS_UPDATE',
                    'QRCODE_UPDATED',
                ],
            ];
            
            // Teste 1: POST /webhook/set/{instance}
            $testUrl1 = $baseUrl . '/webhook/set/' . urlencode($instanceName);
            $ch1 = curl_init($testUrl1);
            curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch1, CURLOPT_POST, true);
            curl_setopt($ch1, CURLOPT_POSTFIELDS, json_encode($whPayloadData));
            curl_setopt($ch1, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
            curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch1, CURLOPT_TIMEOUT, 10);
            $r1 = curl_exec($ch1);
            $c1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
            curl_close($ch1);
            $results[] = ['method' => 'POST', 'url' => $testUrl1, 'code' => $c1, 'response' => $r1];
            
            // Teste 2: PUT /webhook/set/{instance}
            $ch2 = curl_init($testUrl1);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($whPayloadData));
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
            $r2 = curl_exec($ch2);
            $c2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            $results[] = ['method' => 'PUT', 'url' => $testUrl1, 'code' => $c2, 'response' => $r2];
            
            // Teste 3: POST /webhook/{instance} (sem /set/)
            $testUrl3 = $baseUrl . '/webhook/' . urlencode($instanceName);
            $ch3 = curl_init($testUrl3);
            curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch3, CURLOPT_POST, true);
            curl_setopt($ch3, CURLOPT_POSTFIELDS, json_encode($whPayloadData));
            curl_setopt($ch3, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
            curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch3, CURLOPT_TIMEOUT, 10);
            $r3 = curl_exec($ch3);
            $c3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
            curl_close($ch3);
            $results[] = ['method' => 'POST', 'url' => $testUrl3, 'code' => $c3, 'response' => $r3];
            
            // Teste 4: GET /webhook/find/{instance} (ver webhook atual)
            $testUrl4 = $baseUrl . '/webhook/find/' . urlencode($instanceName);
            $ch4 = curl_init($testUrl4);
            curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch4, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
            curl_setopt($ch4, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch4, CURLOPT_TIMEOUT, 10);
            $r4 = curl_exec($ch4);
            $c4 = curl_getinfo($ch4, CURLINFO_HTTP_CODE);
            curl_close($ch4);
            $results[] = ['method' => 'GET', 'url' => $testUrl4, 'code' => $c4, 'response' => $r4];
            
            echo json_encode(['webhook_url' => $whUrl, 'results' => $results], JSON_PRETTY_PRINT);
            exit;

        case 'logout':
            $url = $baseUrl . '/instance/logout/' . urlencode($instanceName);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 300) {
                echo json_encode(['success' => true, 'message' => 'Desconectado com sucesso']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Erro ao desconectar. Código: ' . $httpCode]);
            }
            exit;

        case 'provision':
            // 1. Criar instância com payload mínimo (v2 da Evolution API)
            $createPayload = [
                'instanceName' => $instanceName,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ];
            
            $createUrl = $baseUrl . '/instance/create';
            $ch = curl_init($createUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($createPayload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode < 200 || $httpCode >= 300) {
                echo json_encode(['success' => false, 'error' => 'Falha ao criar instância. Código: ' . $httpCode, 'response' => $response]);
                exit;
            }
            
            // 2. Configurar webhook separadamente via /webhook/set/{instance}
            $publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
            if ($publicUrl === '') {
                $publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
            $webhookUrl = rtrim($publicUrl, '/') . '/chat_webhook.php';
            
            $webhookPayload = [
                'enabled' => true,
                'url' => $webhookUrl,
                'webhook_by_events' => false,
                'webhook_base64' => true,
                'events' => [
                    'MESSAGES_UPSERT',
                    'SEND_MESSAGE',
                    'CONTACTS_UPSERT',
                    'CONTACTS_UPDATE',
                    'CONNECTION_UPDATE',
                    'GROUPS_UPSERT',
                    'GROUP_UPDATE',
                    'GROUP_PARTICIPANTS_UPDATE',
                    'QRCODE_UPDATED',
                ],
            ];
            
            // Tentar POST primeiro
            $webhookSetUrl = $baseUrl . '/webhook/set/' . urlencode($instanceName);
            $ch2 = curl_init($webhookSetUrl);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($webhookPayload));
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
            $whResp = curl_exec($ch2);
            $whCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            
            // Se POST falhou, tentar PUT com wrapper
            if ($whCode < 200 || $whCode >= 300) {
                $webhookPayload2 = [
                    'webhook' => [
                        'enabled' => true,
                        'url' => $webhookUrl,
                        'events' => [
                            'MESSAGES_UPSERT',
                            'SEND_MESSAGE',
                            'CONTACTS_UPSERT',
                            'CONTACTS_UPDATE',
                            'CONNECTION_UPDATE',
                            'GROUPS_UPSERT',
                            'GROUP_UPDATE',
                            'GROUP_PARTICIPANTS_UPDATE',
                            'QRCODE_UPDATED',
                        ],
                        'webhook_by_events' => false,
                        'webhook_base64' => true,
                    ]
                ];
                $ch3 = curl_init($webhookSetUrl);
                curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch3, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch3, CURLOPT_POSTFIELDS, json_encode($webhookPayload2));
                curl_setopt($ch3, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
                curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch3, CURLOPT_TIMEOUT, 30);
                $whResp = curl_exec($ch3);
                $whCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
                curl_close($ch3);
            }
            
            $decoded = json_decode($response, true);
            echo json_encode([
                'success' => true,
                'instance' => $instanceName,
                'data' => $decoded,
                'webhook_configured' => ($whCode >= 200 && $whCode < 300),
                'webhook_url' => $webhookUrl,
            ]);
            exit;

        default:
            echo json_encode(['error' => 'Ação inválida']);
            exit;
    }
    
    // Executar request para connect/status
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        if ($action === 'status') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                if (isset($decoded['instance']['state'])) {
                    $decoded['state'] = $decoded['instance']['state'];
                }
                echo json_encode($decoded);
            } else {
                echo $response;
            }
        } else {
            echo $response;
        }
    } elseif ($httpCode === 404) {
        // Instância não existe — sinalizar para o frontend criar
        echo json_encode(['error' => 'instance_not_found', 'code' => 404, 'instance' => $instanceName]);
    } else {
        echo json_encode([
            'error' => 'Erro na API Evolution. Código: ' . $httpCode,
            'url' => $url,
            'response' => $response,
            'curl_error' => $curlError
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Erro ao conectar: ' . $e->getMessage()]);
}
