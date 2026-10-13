<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Proxy Evolution para o USUÁRIO COMUM operar SOMENTE as instâncias vinculadas
// a ele próprio (via vínculo N:N ou legado). Diferente de evolution_proxy.php,
// que exige a permissão admin.settings.manage, aqui basta estar logado — mas
// validamos que a instância solicitada pertence ao usuário.
auth_require_login();

header('Content-Type: application/json');

$action = (string)($_GET['action'] ?? '');
$instanceName = trim((string)($_GET['instance'] ?? ''));

$userId = auth_user_id();

$baseUrl = trim((string)admin_setting_get('evolution.base_url', ''));
$apiKey = trim((string)admin_setting_get('evolution.api_key', ''));

if ($baseUrl === '' || $apiKey === '') {
    echo json_encode(['error' => 'Evolution API não configurada']);
    exit;
}
if ($instanceName === '') {
    echo json_encode(['error' => 'Nome da instância não informado']);
    exit;
}

// --- Autorização: a instância precisa estar vinculada ao usuário logado. ---
$ownInstances = whatsapp_list_user_instances((int)$userId);
$allowed = false;
foreach ($ownInstances as $inst) {
    if ((string)($inst['instance_name'] ?? '') === $instanceName) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'Esta instância não está vinculada ao seu usuário.']);
    exit;
}

// Ações permitidas ao usuário comum (não inclui provision/remove — isso é do admin).
if (!in_array($action, ['status', 'connect', 'logout'], true)) {
    echo json_encode(['error' => 'Ação inválida']);
    exit;
}

/**
 * Configura o webhook da instância (mesmo padrão do evolution_proxy.php),
 * apontando para /chat_webhook.php.
 */
function myWaConfigureWebhook(string $baseUrl, string $apiKey, string $instanceName): bool
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

    $ch = curl_init($baseUrl . '/webhook/set/' . urlencode($instanceName));
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
    if ($action === 'logout') {
        $ch = curl_init($baseUrl . '/instance/logout/' . urlencode($instanceName));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $httpCode >= 200 && $httpCode < 300;
        if ($ok) {
            whatsapp_update_connection_status($instanceName, 'disconnected');
        }
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? 'Desconectado' : 'Erro: ' . $httpCode,
        ]);
        exit;
    }

    if ($action === 'connect') {
        // Configurar webhook antes de gerar o QR.
        myWaConfigureWebhook($baseUrl, $apiKey, $instanceName);
        $url = $baseUrl . '/instance/connect/' . urlencode($instanceName);
    } else { // status
        $url = $baseUrl . '/instance/connectionState/' . urlencode($instanceName);
    }

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
            $connState = $decoded['state'] ?? ($decoded['instance']['state'] ?? '');
            if ($connState === 'open' || $connState === 'connected') {
                // Extrair número do owner (quando disponível) e persistir status.
                $ownerNumber = '';
                if (isset($decoded['instance']['owner'])) {
                    $ownerNumber = (string)$decoded['instance']['owner'];
                } elseif (isset($decoded['owner'])) {
                    $ownerNumber = (string)$decoded['owner'];
                }
                $ownerNumber = preg_replace('/@.*$/', '', $ownerNumber);
                $ownerNumber = preg_replace('/\D+/', '', $ownerNumber);
                if ($ownerNumber !== '' && strlen($ownerNumber) >= 10) {
                    if (strlen($ownerNumber) === 10 || strlen($ownerNumber) === 11) {
                        $ownerNumber = '55' . $ownerNumber;
                    }
                    whatsapp_update_connection_status($instanceName, 'connected', $ownerNumber);
                } else {
                    whatsapp_update_connection_status($instanceName, 'connected');
                }
            } elseif ($connState === 'close' || $connState === 'disconnected') {
                whatsapp_update_connection_status($instanceName, 'disconnected');
            } elseif ($connState === 'connecting') {
                whatsapp_update_connection_status($instanceName, 'connecting');
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
} catch (Throwable $e) {
    echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
}
