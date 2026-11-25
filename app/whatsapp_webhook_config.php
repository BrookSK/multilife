<?php

declare(strict_types=1);

/**
 * Configuração do webhook das instâncias no serviço WhatsApp (Baileys) — fonte única.
 *
 * Um único endereço (chat_webhook.php), base64 ligado (a mídia já vem junto com a
 * mensagem) e só os eventos usados. Particularidades do multilife: SEND_MESSAGE
 * (eco do envio pela API, usado para confirmar o disparo de captação) e
 * CONTACTS_UPSERT (nome/foto). O formato do corpo é o mesmo da Evolution v2.
 */

const WHATSAPP_WEBHOOK_EVENTS = [
    'MESSAGES_UPSERT',   // mensagens recebidas e enviadas pelo celular
    'SEND_MESSAGE',      // mensagens enviadas pela API (eco)
    'MESSAGES_DELETE',   // mensagem apagada
    'CONTACTS_UPSERT',   // nome/foto de contato
    'CONNECTION_UPDATE', // saúde da conexão (detecção de loop)
    'QRCODE_UPDATED',    // QR Code novo (aparece no painel)
];

/** URL pública do webhook do chat. */
function whatsapp_webhook_public_url(): string
{
    $publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
    if ($publicUrl === '') {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $publicUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return rtrim($publicUrl, '/') . '/chat_webhook.php';
}

/** Corpo do POST /webhook/set/{instance}. */
function whatsapp_webhook_payload(string $url): array
{
    return [
        'webhook' => [
            'enabled' => true,
            'url' => $url,
            'byEvents' => false,
            'base64' => true,
            'events' => WHATSAPP_WEBHOOK_EVENTS,
        ],
    ];
}

/**
 * Configuração atual do webhook da instância, normalizada.
 *
 * @return array{ok:bool,http:int,url:string,enabled:?bool,base64:?bool,byEvents:?bool,events:array,raw:string}
 */
function whatsapp_webhook_find(string $instanceName): array
{
    $out = ['ok' => false, 'http' => 0, 'url' => '', 'enabled' => null, 'base64' => null, 'byEvents' => null, 'events' => [], 'raw' => ''];
    try {
        $res = (new WhatsAppApi(null, null, $instanceName))->findWebhook($instanceName);
        $out['http'] = (int)($res['status'] ?? 0);
        $out['raw'] = mb_substr((string)($res['body_raw'] ?? ''), 0, 400);
        $j = is_array($res['json'] ?? null) ? $res['json'] : [];
        if (isset($j['webhook']) && is_array($j['webhook'])) {
            $j = $j['webhook'];
        }
        $pick = static function (array $a, array $keys) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $a)) {
                    return $a[$k];
                }
            }
            return null;
        };
        $out['ok'] = $out['http'] >= 200 && $out['http'] < 300 && $j !== [];
        $out['url'] = (string)($pick($j, ['url']) ?? '');
        $en = $pick($j, ['enabled']);
        $b64 = $pick($j, ['webhookBase64', 'base64']);
        $be = $pick($j, ['webhookByEvents', 'byEvents']);
        $out['enabled'] = $en === null ? null : (bool)$en;
        $out['base64'] = $b64 === null ? null : (bool)$b64;
        $out['byEvents'] = $be === null ? null : (bool)$be;
        $out['events'] = is_array($pick($j, ['events'])) ? array_values($pick($j, ['events'])) : [];
    } catch (Throwable $e) {
        $out['raw'] = $e->getMessage();
    }
    return $out;
}

/** Grava o webhook padrão (URL do chat) na instância. */
function whatsapp_webhook_configure(string $instanceName, ?string $url = null): bool
{
    try {
        $res = (new WhatsAppApi(null, null, $instanceName))->setWebhook(whatsapp_webhook_payload($url ?? whatsapp_webhook_public_url()), $instanceName);
        $status = (int)($res['status'] ?? 0);
        return $status >= 200 && $status < 300;
    } catch (Throwable $e) {
        error_log('[WEBHOOK_CONFIG] ' . $instanceName . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Aplica a configuração padrão (base64 ligado, só os eventos usados).
 * Mantém a URL atual da instância se já houver uma; senão usa a do chat.
 *
 * @return array{ok:bool,message:string,before:array,after:array}
 */
function whatsapp_webhook_optimize(string $instanceName): array
{
    $before = whatsapp_webhook_find($instanceName);
    $url = $before['url'] !== '' ? $before['url'] : whatsapp_webhook_public_url();
    $status = 0;
    $body = '';
    try {
        $res = (new WhatsAppApi(null, null, $instanceName))->setWebhook(whatsapp_webhook_payload($url), $instanceName);
        $status = (int)($res['status'] ?? 0);
        $body = (string)($res['body_raw'] ?? '');
    } catch (Throwable $e) {
        $body = $e->getMessage();
    }

    $after = whatsapp_webhook_find($instanceName);
    $ok = $status >= 200 && $status < 300 && $after['byEvents'] !== true;
    $msg = $ok
        ? 'Webhook configurado no padrão (base64 ligado, ' . count($after['events'] ?: WHATSAPP_WEBHOOK_EVENTS) . ' eventos).'
        : ('Não foi possível confirmar a mudança. HTTP ' . $status . ': ' . mb_substr($body, 0, 200));
    integration_log('whatsapp', 'webhook optimize ' . $instanceName, $ok ? 'success' : 'error', $status, ['url' => $url], $after, $ok ? null : $msg, 1);

    return ['ok' => $ok, 'message' => $msg, 'before' => $before, 'after' => $after];
}
