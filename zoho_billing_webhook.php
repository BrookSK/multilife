<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

/**
 * Webhook do Zoho Forms - Formulário de faturamento.
 *
 * Este arquivo é chamado externamente pelo Zoho Forms, portanto NÃO exige
 * sessão/login. A proteção é feita por um token secreto opcional configurado
 * em Configurações → Zoho Faturamento (setting: zoho_billing.webhook_token).
 *
 * Ao receber o webhook: extrai os campos, monta a mensagem e envia via
 * WhatsApp (Evolution API) para o telefone recebido. Em modo teste, o telefone
 * recebido é ignorado e usamos o telefone de teste configurado.
 */

header('Content-Type: application/json; charset=utf-8');

// Aceitar apenas POST.
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Corpo cru para log e parsing.
$rawPayload = file_get_contents('php://input');
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
zoho_billing_log('==== NOVA REQUISIÇÃO ====');
zoho_billing_log('Content-Type: ' . ($contentType !== '' ? $contentType : '[vazio]'));
zoho_billing_log('Body cru: ' . ($rawPayload !== '' ? $rawPayload : '[corpo vazio]'));
if (!empty($_POST)) {
    zoho_billing_log('$_POST: ' . json_encode($_POST, JSON_UNESCAPED_UNICODE));
}
if (!empty($_GET)) {
    zoho_billing_log('$_GET: ' . json_encode($_GET, JSON_UNESCAPED_UNICODE));
}

// Validação do token secreto (se configurado).
$expectedToken = trim((string)admin_setting_get('zoho_billing.webhook_token', ''));
if ($expectedToken !== '') {
    $providedToken = zoho_billing_extract_request_token();
    if (!hash_equals($expectedToken, $providedToken)) {
        zoho_billing_log('Token inválido ou ausente. Requisição rejeitada.');
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// Parsear o payload: tenta JSON; se falhar, usa dados de formulário ($_POST).
$payload = null;
if ($rawPayload !== '') {
    $decoded = json_decode($rawPayload, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === null) {
    // Zoho pode enviar como application/x-www-form-urlencoded ou multipart.
    if (!empty($_POST)) {
        $payload = $_POST;
    }
}

if (!is_array($payload) || count($payload) === 0) {
    zoho_billing_log('Erro: payload vazio ou inválido.');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Extrair campos, resolver destino, montar a mensagem e ENFILEIRAR o envio.
// O envio em si é feito pelo worker (cron/integration_jobs_run.php), o que
// permite responder rápido ao Zoho e suportar muitos webhooks simultâneos.
$result = zoho_billing_enqueue($payload, false);

$fieldsLog = json_encode($result['fields'], JSON_UNESCAPED_UNICODE);
zoho_billing_log(
    'Recebido. Campos=' . $fieldsLog
    . ' | Destino=' . ($result['phone'] ?? 'null')
    . ' | ModoTeste=' . ($result['test_mode'] ? 'sim' : 'não')
    . ' | Enfileirado=' . ($result['queued'] ? 'sim (job #' . $result['job_id'] . ')' : 'não')
    . ' | ' . $result['message']
);

if (!$result['queued']) {
    // Telefone inválido/ausente = erro de dados (400). Não há o que reprocessar.
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $result['message'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(200);
echo json_encode([
    'status' => 'queued',
    'message' => $result['message'],
    'job_id' => $result['job_id'],
    'test_mode' => $result['test_mode'],
], JSON_UNESCAPED_UNICODE);
exit;

/**
 * Extrai o token da requisição, aceitando várias formas de envio:
 *   - Header: X-Webhook-Token / X-Zoho-Token / Authorization: Bearer <token>
 *   - Query string: ?token=...
 *   - Campo de formulário: token / webhook_token
 */
function zoho_billing_extract_request_token(): string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) {
            $headers[strtolower((string)$k)] = (string)$v;
        }
    }
    // Fallback via $_SERVER para ambientes sem getallheaders.
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with((string)$k, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr((string)$k, 5)));
            $headers[$name] = (string)$v;
        }
    }

    if (isset($headers['x-webhook-token']) && $headers['x-webhook-token'] !== '') {
        return trim($headers['x-webhook-token']);
    }
    if (isset($headers['x-zoho-token']) && $headers['x-zoho-token'] !== '') {
        return trim($headers['x-zoho-token']);
    }
    if (isset($headers['authorization']) && stripos($headers['authorization'], 'bearer ') === 0) {
        return trim(substr($headers['authorization'], 7));
    }
    if (isset($_GET['token']) && $_GET['token'] !== '') {
        return trim((string)$_GET['token']);
    }
    if (isset($_POST['token']) && $_POST['token'] !== '') {
        return trim((string)$_POST['token']);
    }
    if (isset($_POST['webhook_token']) && $_POST['webhook_token'] !== '') {
        return trim((string)$_POST['webhook_token']);
    }
    return '';
}
