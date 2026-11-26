<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Proxy API do WhatsApp para o USUÁRIO COMUM operar SOMENTE as instâncias vinculadas
// a ele próprio (via vínculo N:N ou legado). Diferente de whatsapp_proxy.php,
// que exige a permissão admin.settings.manage, aqui basta estar logado — mas
// validamos que a instância solicitada pertence ao usuário.
auth_require_login();

header('Content-Type: application/json');

$action = (string)($_GET['action'] ?? '');
$instanceName = trim((string)($_GET['instance'] ?? ''));

$userId = auth_user_id();

$baseUrl = trim((string)wa_api_base_url());
$apiKey = trim((string)wa_api_key());

if ($baseUrl === '' || $apiKey === '') {
    echo json_encode(['error' => 'API do WhatsApp não configurada']);
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
 * Configura o webhook da instância (mesmo padrão do whatsapp_proxy.php),
 * apontando para /chat_webhook.php.
 */
function myWaConfigureWebhook(string $baseUrl, string $apiKey, string $instanceName): bool
{
    // EndereÃ§o configurado em ConfiguraÃ§Ãµes â†’ WhatsApp ConexÃ£o (ou o do sistema)
    $webhookUrl = whatsapp_webhook_public_url();

    // Configuração única (modelo helpdeskON): app/whatsapp_webhook_config.php
    $payload = whatsapp_webhook_payload($webhookUrl);

    $httpCode = wa_raw_request('POST', $baseUrl . '/webhook/set/' . urlencode($instanceName), $payload, 15)['status'];

    return ($httpCode >= 200 && $httpCode < 300);
}

try {
    if ($action === 'logout') {
        if (!wa_provider_can('logout')) {
            echo json_encode(['success' => false, 'message' => wa_provider_cannot_reason('logout')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $httpCode = wa_raw_request('DELETE', $baseUrl . '/instance/logout/' . urlencode($instanceName), null, 30)['status'];

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
        // Instância que ainda não existe no serviço (ex.: vinda da Evolution): cria
        wa_service_ensure_instance($instanceName);
        // Configurar webhook antes de gerar o QR.
        myWaConfigureWebhook($baseUrl, $apiKey, $instanceName);
        $url = $baseUrl . '/instance/connect/' . urlencode($instanceName);
    } else { // status
        $url = $baseUrl . '/instance/connectionState/' . urlencode($instanceName);
    }

    $rawRes = wa_raw_request('GET', $url, null, 30);
    $response = $rawRes['body'];
    $httpCode = $rawRes['status'];

    if ($httpCode >= 200 && $httpCode < 300) {
        if ($action === 'status') {
            $decoded = json_decode($response, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            if (isset($decoded['instance']['state'])) {
                $decoded['state'] = $decoded['instance']['state'];
            }
            $connState = $decoded['state'] ?? ($decoded['instance']['state'] ?? '');
            // QR Code recém-lido com número que já está em outra instância: conexão desfeita
            $dupGuard = ($connState === 'open' || $connState === 'connected') && wa_pairing_pending($instanceName)
                ? wa_pairing_guard($instanceName, (string)($decoded['instance']['owner'] ?? ($decoded['owner'] ?? '')))
                : null;
            if ($dupGuard && $dupGuard['blocked']) {
                $connState = 'close';
                $decoded['state'] = 'close';
                if (isset($decoded['instance']) && is_array($decoded['instance'])) {
                    $decoded['instance']['state'] = 'close';
                }
            }
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
            // Conexão real (watchdog, cache de 60 s) e aviso de número duplicado
            $decoded['watchdog'] = wa_watchdog_public($instanceName);
            $dupNote = wa_pairing_duplicate_note($instanceName);
            if ($dupNote !== null) {
                $decoded['duplicate'] = ['message' => $dupNote];
            }
            echo json_encode($decoded);
        } else {
            // QR Code gerado pelo sistema: confere o número quando for lido
            $connDecoded = json_decode((string)$response, true);
            if (is_array($connDecoded) && wa_connect_response_has_qr($connDecoded)) {
                wa_pairing_mark($instanceName);
            }
            echo $response;
        }
    } elseif ($httpCode === 404) {
        echo json_encode(['error' => 'instance_not_found', 'code' => 404, 'instance' => $instanceName]);
    } else {
        echo json_encode(['error' => 'Erro na API API do WhatsApp. Código: ' . $httpCode, 'response' => $response]);
    }
} catch (Throwable $e) {
    echo json_encode(['error' => 'Erro: ' . $e->getMessage()]);
}
