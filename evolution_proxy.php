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

/**
 * Configura webhook na instância via POST /webhook/set/{instance}
 * Formato v2.3: body deve conter { "webhook": { ... } }
 */
function configureWebhook(string $baseUrl, string $apiKey, string $instanceName): bool
{
    $publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
    if ($publicUrl === '') {
        $publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $webhookUrl = rtrim($publicUrl, '/') . '/chat_webhook.php';

    $payload = [
        'webhook' => [
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
        ],
    ];

    $url = $baseUrl . '/webhook/set/' . urlencode($instanceName);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

try {
    switch ($action) {

        case 'connect':
            // Configurar webhook antes de conectar
            configureWebhook($baseUrl, $apiKey, $instanceName);
            // Gerar QR Code
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
            echo json_encode([
                'success' => ($httpCode >= 200 && $httpCode < 300),
                'message' => ($httpCode >= 200 && $httpCode < 300) ? 'Desconectado' : 'Erro: ' . $httpCode,
            ]);
            exit;

        case 'provision':
            // 1. Criar instância
            $createPayload = [
                'instanceName' => $instanceName,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ];
            $ch = curl_init($baseUrl . '/instance/create');
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

            // 2. Configurar webhook
            $whOk = configureWebhook($baseUrl, $apiKey, $instanceName);

            $decoded = json_decode($response, true);
            echo json_encode([
                'success' => true,
                'instance' => $instanceName,
                'data' => $decoded,
                'webhook_configured' => $whOk,
            ]);
            exit;

        case 'debug_webhook':
            // Testa o endpoint de webhook e retorna resultado
            $publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
            if ($publicUrl === '') {
                $publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
            $whUrl = rtrim($publicUrl, '/') . '/chat_webhook.php';

            $payload = [
                'webhook' => [
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
                ],
            ];

            // POST /webhook/set/{instance} com wrapper
            $testUrl = $baseUrl . '/webhook/set/' . urlencode($instanceName);
            $ch = curl_init($testUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey, 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $r1 = curl_exec($ch);
            $c1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // GET /webhook/find/{instance} (ver webhook atual)
            $findUrl = $baseUrl . '/webhook/find/' . urlencode($instanceName);
            $ch2 = curl_init($findUrl);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
            $r2 = curl_exec($ch2);
            $c2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            echo json_encode([
                'webhook_url' => $whUrl,
                'set_result' => ['method' => 'POST', 'url' => $testUrl, 'code' => $c1, 'response' => $r1, 'payload_sent' => $payload],
                'find_result' => ['method' => 'GET', 'url' => $findUrl, 'code' => $c2, 'response' => $r2],
            ], JSON_PRETTY_PRINT);
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
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        if ($action === 'status') {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && isset($decoded['instance']['state'])) {
                $decoded['state'] = $decoded['instance']['state'];
            }
            echo json_encode($decoded);
        } else {
            echo $response;
        }
    } elseif ($httpCode === 404) {
        echo json_encode(['error' => 'instance_not_found', 'code' => 404, 'instance' => $instanceName]);
    } else {
        echo json_encode(['error' => 'Erro na API Evolution. Código: ' . $httpCode, 'response' => $response]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
}
