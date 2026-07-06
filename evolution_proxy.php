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
            $url = $baseUrl . '/instance/connect/' . urlencode($instanceName);
            break;

        case 'status':
            $url = $baseUrl . '/instance/connectionState/' . urlencode($instanceName);
            break;

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
            // Criar instância automaticamente com webhook configurado
            $publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
            if ($publicUrl === '') {
                $publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
            $webhookUrl = rtrim($publicUrl, '/') . '/chat_webhook.php';
            
            $createPayload = [
                'instanceName' => $instanceName,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
                'webhook' => $webhookUrl,
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
            
            if ($httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode($response, true);
                echo json_encode(['success' => true, 'instance' => $instanceName, 'data' => $decoded]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Falha ao criar instância. Código: ' . $httpCode, 'response' => $response]);
            }
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
