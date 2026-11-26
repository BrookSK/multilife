<?php

declare(strict_types=1);

/**
 * Webhook da API oficial do WhatsApp (Meta).
 *
 * No app da Meta (developers.facebook.com → seu app → WhatsApp → Configuração):
 *   URL de retorno de chamada: https://<sistema>/whatsapp_cloud_webhook.php
 *   Token de verificação: o que aparece em Configurações → WhatsApp → API oficial
 *   Campos: messages, smb_message_echoes, message_template_status_update,
 *           group_lifecycle_update, group_participants_update,
 *           group_settings_update, group_status_update, account_update,
 *           phone_number_quality_update
 *
 * GET  = verificação (a Meta manda hub.challenge e espera o mesmo valor de volta).
 * POST = eventos: confere a assinatura (X-Hub-Signature-256), converte para o
 *        formato do sistema (app/whatsapp_cloud_inbound.php), grava na mesma fila
 *        do chat_webhook.php, responde 200 na hora e processa depois.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/whatsapp_webhook_handler.php';
require_once __DIR__ . '/app/whatsapp_webhook_inbox.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_COOKIE[session_name()])) {
        @session_destroy();
    } else {
        session_write_close();
    }
}

// ---- Verificação do webhook ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $mode = (string)($_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? ''));
    $token = (string)($_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? ''));
    $challenge = (string)($_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? ''));
    if ($mode === 'subscribe' && $token !== '' && hash_equals(wa_cloud_verify_token(), $token)) {
        wa_event_log('info', 'webhook', 'webhook_verificado', ['summary' => 'A Meta verificou o endereço do webhook da API oficial']);
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }
    wa_event_log('warn', 'webhook', 'verificacao_recusada', ['summary' => 'Verificação do webhook da API oficial recusada (token diferente do configurado)',
        'details' => ['modo' => $mode, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]]);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'token de verificação inválido';
    exit;
}

ignore_user_abort(true);
@set_time_limit(120);
@ini_set('memory_limit', '256M');

$t0 = microtime(true);
$raw = (string)file_get_contents('php://input');
$signature = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if ($raw === '' || !wa_cloud_signature_ok($raw, $signature)) {
    wa_event_log('error', 'webhook', 'assinatura_invalida', [
        'summary' => $raw === '' ? 'Chamada ao webhook da API oficial sem corpo' : 'Webhook da API oficial com assinatura inválida: ignorado (confira a "Chave secreta do app" em Configurações → API oficial)',
        'details' => ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'bytes' => strlen($raw), 'temAssinatura' => $signature !== ''],
    ]);
    http_response_code($raw === '' ? 400 : 401);
    exit;
}
// Último evento recebido (tela da API oficial); grava no máximo 1 vez por minuto
try {
    if (time() - (int)wa_cloud_setting('last_webhook_at', '0') >= 60) {
        wa_cloud_setting_save('last_webhook_at', (string)time());
    }
} catch (Throwable $e) {}
$meta = json_decode($raw, true);
if (!is_array($meta) || ($meta['object'] ?? '') !== 'whatsapp_business_account') {
    http_response_code(200); // a Meta não deve reenviar o que não é do WhatsApp
    exit;
}

// Com a API oficial desligada (outra integração em uso), registra e não processa
if (!wa_is_cloud()) {
    wa_event_log('warn', 'webhook', 'ignorado_integracao', ['summary' => 'Evento da API oficial recebido, mas a integração em uso é ' . wa_provider_label() . ': ignorado']);
    http_response_code(200);
    exit;
}

$queued = [];
$direct = [];
try {
    $payloads = wa_cloud_translate($meta);
} catch (Throwable $e) {
    $payloads = [];
    wa_event_log('error', 'webhook', 'conversao_falhou', ['summary' => 'Erro ao ler o webhook da API oficial: ' . mb_substr($e->getMessage(), 0, 200), 'error' => $e,
        'details' => ['inicio' => mb_substr($raw, 0, 500)]]);
}
foreach ($payloads as $p) {
    // Enviada/entregue/lida chegam aos montes e só atualizam contadores: sem fila
    if (($p['event'] ?? '') === 'cloud.status' && strtolower((string)($p['data']['status'] ?? '')) !== 'failed') {
        $direct[] = $p;
        continue;
    }
    try {
        $rawP = (string)json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $stored = wa_inbox_store($rawP, $p);
        if ($stored['status'] === 'queued') {
            $queued[] = [(int)$stored['id'], $p];
        } elseif ($stored['status'] === 'unavailable') {
            $direct[] = $p;
        }
        $ev = (string)($p['event'] ?? '');
        if ($ev !== 'cloud.status') {
            wa_event_log('debug', 'webhook', $stored['status'] === 'duplicate' ? 'recebido_repetido' : 'recebido', [
                'instance' => (string)($p['instance'] ?? '') ?: null,
                'message_id' => (string)($p['data']['key']['id'] ?? '') ?: null,
                'jid' => (string)($p['data']['key']['remoteJid'] ?? '') ?: null,
                'status' => $ev, 'ms' => (microtime(true) - $t0) * 1000,
                'summary' => 'API oficial: ' . $ev . ($stored['status'] === 'duplicate' ? ' REPETIDO (ignorado)' : ' recebido e colocado na fila (#' . (int)$stored['id'] . ')'),
            ]);
        }
    } catch (Throwable $e) {
        $direct[] = $p;
    }
}

// Resposta imediata (a Meta reenvia se demorar)
$body = '{"status":"ok"}';
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: ' . strlen($body));
header('Connection: close');
echo $body;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
}

foreach ($queued as [$id, $p]) {
    try {
        wa_inbox_process($id, $p);
    } catch (Throwable $e) {
        error_log('[CLOUD_WEBHOOK] ' . $e->getMessage());
    }
}
foreach ($direct as $p) {
    try {
        wa_webhook_handle($p);
    } catch (Throwable $e) {
        wa_event_log('error', 'processamento', 'erro_webhook', ['summary' => 'Erro ao processar evento da API oficial: ' . mb_substr($e->getMessage(), 0, 200), 'error' => $e]);
    }
}
try {
    wa_inbox_process_pending(3, 20);
} catch (Throwable $e) {
    error_log('[CLOUD_WEBHOOK] pendentes: ' . $e->getMessage());
}
