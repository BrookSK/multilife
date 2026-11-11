<?php

declare(strict_types=1);

/**
 * Configuração do webhook das instâncias na Evolution (fonte única).
 *
 * Por que base64 DESLIGADO: com base64 ligado, a Evolution baixa e converte cada
 * foto/áudio/vídeo/documento ANTES de gravar a mensagem e avisar o sistema, uma
 * mensagem por vez por instância. Em WhatsApps com muitos grupos (captação) isso
 * formava fila de horas/dias (ml-grace 47 h, ml-tania 40 h, ml-maju 30 h de atraso).
 * Sem base64, a mensagem chega na hora e o sistema baixa a mídia depois, pela
 * própria Evolution (getBase64FromMediaMessage), sem travar a fila dela.
 *
 * Eventos: só os que o sistema usa. Cada evento a mais é uma chamada HTTP que a
 * Evolution faz e espera (ex.: status de entrega de cada mensagem de grupo).
 */

const WHATSAPP_WEBHOOK_EVENTS = [
    'MESSAGES_UPSERT',   // mensagens recebidas e enviadas pelo celular
    'SEND_MESSAGE',      // mensagens enviadas pela API (eco)
    'MESSAGES_DELETE',   // mensagem apagada
    'CONTACTS_UPSERT',   // nome/foto de contato
    'CONNECTION_UPDATE', // saúde da conexão (detecção de loop)
    'QRCODE_UPDATED',
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

/** Corpo do POST /webhook/set/{instance} (Evolution v2). */
function whatsapp_webhook_payload(string $url): array
{
    return [
        'webhook' => [
            'enabled' => true,
            'url' => $url,
            'byEvents' => false,
            'base64' => false,
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
        $res = http_json_request(
            'GET',
            rtrim((string)admin_setting_get('evolution.base_url'), '/') . '/webhook/find/' . rawurlencode($instanceName),
            ['apikey' => (string)admin_setting_get('evolution.api_key')],
            null,
            8
        );
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
        $b64 = $pick($j, ['webhookBase64', 'base64', 'webhook_base64']);
        $be = $pick($j, ['webhookByEvents', 'byEvents', 'webhook_by_events']);
        $out['enabled'] = $en === null ? null : (bool)$en;
        $out['base64'] = $b64 === null ? null : (bool)$b64;
        $out['byEvents'] = $be === null ? null : (bool)$be;
        $out['events'] = is_array($pick($j, ['events'])) ? array_values($pick($j, ['events'])) : [];
    } catch (Throwable $e) {
        $out['raw'] = $e->getMessage();
    }
    return $out;
}

/**
 * Aplica a configuração recomendada (base64 desligado, só os eventos usados).
 * Mantém a URL atual da instância se já houver uma; senão usa a do chat.
 *
 * @return array{ok:bool,message:string,before:array,after:array}
 */
function whatsapp_webhook_optimize(string $instanceName): array
{
    $before = whatsapp_webhook_find($instanceName);
    $url = $before['url'] !== '' ? $before['url'] : whatsapp_webhook_public_url();
    $base = rtrim((string)admin_setting_get('evolution.base_url'), '/');
    $headers = ['apikey' => (string)admin_setting_get('evolution.api_key')];

    $res = http_json_request('POST', $base . '/webhook/set/' . rawurlencode($instanceName), $headers, whatsapp_webhook_payload($url), 15);
    $status = (int)($res['status'] ?? 0);
    if ($status < 200 || $status >= 300) {
        // Versões anteriores da Evolution: chaves com outro nome
        $legacy = whatsapp_webhook_payload($url);
        $legacy['webhook']['webhookByEvents'] = false;
        $legacy['webhook']['webhookBase64'] = false;
        unset($legacy['webhook']['byEvents'], $legacy['webhook']['base64']);
        $res = http_json_request('POST', $base . '/webhook/set/' . rawurlencode($instanceName), $headers, $legacy, 15);
        $status = (int)($res['status'] ?? 0);
    }

    $after = whatsapp_webhook_find($instanceName);
    $ok = $status >= 200 && $status < 300 && $after['base64'] !== true;
    $msg = $ok
        ? 'Webhook otimizado (base64 desligado, ' . count($after['events'] ?: WHATSAPP_WEBHOOK_EVENTS) . ' eventos).'
        : ('Não foi possível confirmar a mudança. HTTP ' . $status . ': ' . mb_substr((string)($res['body_raw'] ?? ''), 0, 200));
    integration_log('evolution', 'webhook optimize ' . $instanceName, $ok ? 'success' : 'error', $status, ['url' => $url], $after, $ok ? null : $msg, 1);

    return ['ok' => $ok, 'message' => $msg, 'before' => $before, 'after' => $after];
}
