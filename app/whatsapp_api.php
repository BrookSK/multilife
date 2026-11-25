<?php

declare(strict_types=1);

/**
 * Cliente do WhatsApp do sistema: serviço ml-baileys-service (Node + Baileys),
 * pasta ml-baileys-service/ deste repositório.
 *
 * O serviço fala o MESMO contrato de API e de webhook da Evolution v2 (rotas,
 * corpos e respostas), então os métodos abaixo mantêm os nomes e formatos que
 * o resto do sistema já usa.
 *
 * Configuração AUTOMÁTICA quando o serviço roda na mesma hospedagem (painel
 * Node.js do Plesk/cPanel, guia CONFIGURAR_WHATSAPP_NA_HOSPEDAGEM.md):
 *   - URL: vazia = https://whatsapp.<domínio do sistema> (conferida sozinha);
 *   - chave: gerada aqui na primeira vez;
 *   - o serviço recebe banco, chave, URLs e retenção pelo arquivo
 *     ml-baileys-service/runtime-config.php, escrito por wa_service_runtime_sync().
 * Tudo pode ser trocado em Configurações → WhatsApp Conexão (ex.: serviço numa VPS):
 *   baileys.base_url, baileys.api_key, baileys.instance,
 *   baileys.message_retention_days (0 = guardar as mensagens para sempre).
 */

/** Pasta do serviço dentro do sistema. */
function wa_service_dir(): string
{
    return dirname(__DIR__) . '/ml-baileys-service';
}

/** O serviço está nesta mesma hospedagem (pasta ml-baileys-service do sistema)? */
function wa_service_embedded(): bool
{
    return is_file(wa_service_dir() . '/app.js');
}

/**
 * Mídia grande não vai dentro do pedido ao serviço: a hospedagem recusa corpos
 * acima de ~1 MB (HTTP 413 "Request Entity Too Large"). Acima deste tamanho de
 * base64 (Configurações: baileys.inline_media_max_kb, padrão 600), o arquivo vai
 * para uploads/wa_outbox/ com nome aleatório e o serviço recebe só o link (ele baixa).
 * Devolve o próprio valor quando não precisa (pequeno, já é URL, ou falhou).
 */
function wa_media_for_api(string $media): string
{
    if ($media === '' || preg_match('#^https?://#i', $media)) {
        return $media;
    }
    $limitRaw = wa_setting('inline_media_max_kb', '600');
    $limitKb = is_numeric($limitRaw) ? (int)$limitRaw : 600; // 0 = sempre junto (desligado)
    if ($limitKb <= 0 || strlen($media) <= $limitKb * 1024) {
        return $media;
    }
    // O arquivo fica na pasta DESTA instalação: o link usa o endereço da tela em uso
    // (com duas instalações no mesmo banco, o "endereço público" das Configurações
    // pode ser o da outra, onde o arquivo não existe).
    $reqHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    $public = wa_system_public_url();
    if (PHP_SAPI !== 'cli' && $reqHost !== '' && preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', $reqHost)
        && strcasecmp((string)parse_url($public, PHP_URL_HOST), (string)preg_replace('/:\d+$/', '', $reqHost)) !== 0) {
        $public = 'https://' . strtolower($reqHost);
    }
    if ($public === '') {
        return $media;
    }
    $b64 = (string)preg_replace('#^data:[^;,]*;base64,#', '', $media);
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') {
        return $media;
    }
    $dir = dirname(__DIR__) . '/uploads/wa_outbox';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return $media;
    }
    // Pasta só de arquivos de passagem: nunca executa nada e não lista o conteúdo
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Options -Indexes -ExecCGI\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8\nRemoveType .php .phtml\n");
    }
    // Limpeza: o serviço baixa na hora; nada aqui precisa durar mais de 1 dia
    if (random_int(1, 20) === 1) {
        foreach ((array)@glob($dir . '/*.bin') as $old) {
            if (is_string($old) && @filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    }
    $name = bin2hex(random_bytes(20)) . '.bin';
    if (@file_put_contents($dir . '/' . $name, $bin, LOCK_EX) === false) {
        return $media;
    }
    return $public . '/uploads/wa_outbox/' . $name;
}

/** Apaga o arquivo de passagem criado por wa_media_for_api() (o serviço já baixou). */
function wa_media_outbox_release(string $value): void
{
    if (preg_match('#/uploads/wa_outbox/([a-f0-9]{40}\.bin)$#', $value, $m)) {
        @unlink(dirname(__DIR__) . '/uploads/wa_outbox/' . $m[1]);
    }
}

/**
 * Erro da API do WhatsApp em texto que dá para ler (sem HTML da hospedagem).
 * @param array $res retorno de WhatsAppApi::request()
 */
function wa_api_error_message(array $res): string
{
    $status = (int)($res['status'] ?? 0);
    if ($status === 413) {
        return 'o arquivo é grande demais para o servidor do WhatsApp (limite da hospedagem). Tente um arquivo menor.';
    }
    $j = is_array($res['json'] ?? null) ? $res['json'] : null;
    if ($j) {
        $m = $j['response']['message'] ?? ($j['message'] ?? ($j['error'] ?? null));
        if (is_array($m)) {
            $parts = [];
            foreach ($m as $item) {
                if (is_array($item) && array_key_exists('exists', $item) && !$item['exists']) {
                    $parts[] = 'o número ' . ($item['number'] ?? '') . ' não tem WhatsApp';
                } else {
                    $parts[] = is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_UNICODE);
                }
            }
            $m = implode('; ', $parts);
        }
        if (is_string($m) && trim($m) !== '') {
            return trim($m);
        }
    }
    $raw = trim((string)($res['body_raw'] ?? ''));
    if ($raw !== '' && stripos($raw, '<html') !== false) {
        // Página de erro da hospedagem: só o título
        $raw = preg_match('#<title>(.*?)</title>#is', $raw, $t) ? trim(html_entity_decode(strip_tags($t[1]))) : trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
    }
    $raw = mb_strimwidth((string)preg_replace('/\s+/', ' ', $raw), 0, 200, '…');
    return $raw !== '' ? $raw . ($status ? ' (HTTP ' . $status . ')' : '') : ($status ? 'HTTP ' . $status : 'sem resposta do serviço WhatsApp');
}

/** Endereço público do sistema (sem barra no final), ou '' se não der para saber. */
function wa_system_public_url(): string
{
    $public = trim((string)admin_setting_get('app.public_base_url', ''));
    if ($public === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $public = ($https ? 'https' : 'http') . '://' . $host;
    }
    return rtrim($public, '/');
}

/** URLs candidatas do serviço na hospedagem: subdomínio "whatsapp." do sistema. */
function wa_service_candidate_urls(): array
{
    $p = parse_url(wa_system_public_url());
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return [];
    }
    $host = (string)preg_replace('/^www\./', '', $host);
    if (str_starts_with($host, 'whatsapp.')) {
        $host = substr($host, 9);
    }
    return ['https://whatsapp.' . $host, 'http://whatsapp.' . $host];
}

/**
 * URL base do serviço WhatsApp (sem barra no final). Ordem: a digitada em
 * Configurações; a descoberta automaticamente (baileys.detected_url); o
 * subdomínio padrão https://whatsapp.<domínio>.
 */
function wa_api_base_url(): string
{
    $set = rtrim(trim((string)admin_setting_get('baileys.base_url', '')), '/');
    if ($set !== '') {
        return $set;
    }
    $detected = rtrim(trim((string)admin_setting_get('baileys.detected_url', '')), '/');
    if ($detected !== '') {
        return $detected;
    }
    return wa_service_candidate_urls()[0] ?? '';
}

/** A URL veio de Configurações (e não da detecção automática)? */
function wa_api_base_url_is_manual(): bool
{
    return trim((string)admin_setting_get('baileys.base_url', '')) !== '';
}

/** Grava uma configuração (admin_settings). */
function wa_setting_save(string $key, string $value): void
{
    db()->prepare('INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
}

/**
 * Chave da API do serviço WhatsApp. Com o serviço na mesma hospedagem, é
 * gerada sozinha na primeira vez (e entregue ao serviço pelo runtime-config.php).
 */
function wa_api_key(): string
{
    static $cache = null;
    if ($cache !== null && $cache !== '') {
        return $cache;
    }
    $key = trim((string)admin_setting_get('baileys.api_key', ''));
    if ($key === '' && wa_service_embedded()) {
        $key = bin2hex(random_bytes(24));
        try {
            wa_setting_save('baileys.api_key', $key);
            $cache = $key;
            wa_service_runtime_sync(true);
        } catch (Throwable $e) {
            error_log('[WHATSAPP_API] não foi possível gravar a chave gerada: ' . $e->getMessage());
            return '';
        }
    }
    return $cache = $key;
}

/** Dias que o serviço guarda as mensagens. 0 = para sempre (padrão). */
function wa_message_retention_days(): int
{
    return max(0, (int)wa_setting('message_retention_days', '0'));
}

/** Conteúdo do runtime-config.php (o que o serviço precisa para funcionar sozinho). */
function wa_service_runtime_payload(): array
{
    $cfg = require dirname(__DIR__) . '/config/config.php';
    $db = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
    $socket = (string)(ini_get('pdo_mysql.default_socket') ?: (ini_get('mysqli.default_socket') ?: ''));
    $system = wa_system_public_url();
    return [
        'db' => [
            'host' => (string)($db['host'] ?? 'localhost'),
            'port' => (int)($db['port'] ?? 3306),
            'name' => (string)($db['name'] ?? ''),
            'user' => (string)($db['user'] ?? ''),
            'pass' => (string)($db['pass'] ?? ''),
            'socket_hint' => $socket,
        ],
        'api_key' => wa_api_key(),
        'public_url' => wa_api_base_url(),
        'default_webhook_url' => ($wh = rtrim(trim((string)admin_setting_get('baileys.webhook_url', '')), '/')) !== ''
            ? $wh : ($system !== '' ? $system . '/chat_webhook.php' : ''),
        'message_retention_days' => wa_message_retention_days(),
        'status_retention_days' => max(0, (int)wa_setting('status_retention_days', '90')),
        'event_log_retention_days' => function_exists('wa_event_log_retention_days') ? wa_event_log_retention_days() : 90,
        'event_log_debug' => (string)admin_setting_get('baileys.event_log_debug', '1') === '0' ? '0' : '1',
        'system_local_ip' => (string)admin_setting_get('baileys.system_local_ip', ''),
    ];
}

/**
 * Escreve ml-baileys-service/runtime-config.php (só se mudou).
 * 1ª linha PHP que sai na hora: aberto pelo navegador, não mostra nada.
 */
function wa_service_runtime_sync(bool $force = false): bool
{
    static $done = false;
    if (!wa_service_embedded() || ($done && !$force)) {
        return false;
    }
    $done = true;
    $file = wa_service_dir() . '/runtime-config.php';
    try {
        $payload = wa_service_runtime_payload();
        if ($payload['api_key'] === '') {
            return false;
        }
        // Rodando pelo cron (sem endereço do site) não apaga o que já estava gravado
        if ($payload['public_url'] === '' || $payload['default_webhook_url'] === '') {
            $old = wa_service_runtime_read();
            if ($payload['public_url'] === '') {
                $payload['public_url'] = (string)($old['public_url'] ?? '');
            }
            if ($payload['default_webhook_url'] === '') {
                $payload['default_webhook_url'] = (string)($old['default_webhook_url'] ?? '');
            }
        }
        $content = "<?php http_response_code(404); exit; ?>\n" . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (is_file($file) && @file_get_contents($file) === $content) {
            return true;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            error_log('[WHATSAPP_API] sem permissão para gravar ' . $file);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('[WHATSAPP_API] runtime-config: ' . $e->getMessage());
        return false;
    }
}

/** Cria no serviço a instância do sistema que ainda não existe lá. */
function wa_service_ensure_instance(string $instanceName): bool
{
    try {
        return (new WhatsAppApi(null, null, $instanceName))->ensureInstance($instanceName);
    } catch (Throwable $e) {
        error_log('[WHATSAPP_API] ' . $e->getMessage());
        return false;
    }
}

/** Lê o runtime-config.php atual (ou []). */
function wa_service_runtime_read(): array
{
    $raw = @file_get_contents(wa_service_dir() . '/runtime-config.php');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $nl = strpos($raw, "\n");
    $json = $nl !== false && str_starts_with($raw, '<?php') ? substr($raw, $nl + 1) : $raw;
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * /health do serviço (sem chave).
 *
 * @return array{ok:bool, url:string, http:int, data:?array, error:string}
 */
function wa_service_health(?string $url = null, int $timeoutSeconds = 6): array
{
    $url = rtrim($url ?? wa_api_base_url(), '/');
    if ($url === '') {
        return ['ok' => false, 'url' => '', 'http' => 0, 'data' => null, 'error' => 'endereço do serviço desconhecido'];
    }
    try {
        $res = http_json_request('GET', $url . '/health', [], null, $timeoutSeconds);
        $j = is_array($res['json'] ?? null) ? $res['json'] : null;
        $ok = (int)$res['status'] === 200 && ($j['clientName'] ?? '') === 'ml-baileys-service';
        return ['ok' => $ok, 'url' => $url, 'http' => (int)$res['status'], 'data' => $j,
            'error' => $ok ? '' : ((int)$res['status'] === 200 ? 'o endereço não é o serviço WhatsApp' : 'HTTP ' . (int)$res['status'])];
    } catch (Throwable $e) {
        return ['ok' => false, 'url' => $url, 'http' => 0, 'data' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Mantém o serviço ligado e configurado. Chamado pelo cron e pelas telas
 * abertas (no máximo 1 vez a cada 50 s para o sistema todo):
 *  - grava o runtime-config.php (banco, chave, URLs, retenção);
 *  - acessa /health: na hospedagem com painel Node.js, é o acesso que liga o
 *    serviço depois de um reinício do servidor;
 *  - URL automática: descobre se o subdomínio responde em https ou http.
 *
 * @return array|null resultado do /health (null = pulado, rodou há pouco)
 */
function wa_service_keepalive(bool $force = false): ?array
{
    static $ran = false;
    if ($ran && !$force) {
        return null;
    }
    $ran = true;
    wa_system_local_ip_remember();
    try {
        $last = (int)admin_setting_get('baileys.keepalive_at', '0');
        if (!$force && time() - $last < 50) {
            return null;
        }
        wa_setting_save('baileys.keepalive_at', (string)time());
    } catch (Throwable $e) {
        return null;
    }
    wa_service_runtime_sync();
    $h = wa_service_health(null, 6);
    if (!$h['ok'] && !wa_api_base_url_is_manual()) {
        foreach (wa_service_candidate_urls() as $cand) {
            if ($cand === $h['url']) {
                continue;
            }
            $try = wa_service_health($cand, 5);
            if ($try['ok']) {
                $h = $try;
                break;
            }
        }
    }
    wa_service_keepalive_log($h);
    if ($h['ok']) {
        wa_webhook_self_heal();
    }
    if ($h['ok'] && !wa_api_base_url_is_manual() && $h['url'] !== rtrim((string)admin_setting_get('baileys.detected_url', ''), '/')) {
        try {
            wa_setting_save('baileys.detected_url', $h['url']);
            wa_service_runtime_sync(true);
        } catch (Throwable $e) {}
    }
    return $h;
}

/**
 * Guarda o IP em que o site atende neste servidor (SERVER_ADDR). O serviço
 * WhatsApp usa esse IP para entregar o webhook quando a hospedagem não deixa o
 * servidor abrir o próprio endereço público (conexão recusada).
 */
function wa_system_local_ip_remember(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $ip = trim((string)($_SERVER['SERVER_ADDR'] ?? ''));
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return;
    }
    try {
        if ((string)admin_setting_get('baileys.system_local_ip', '') !== $ip) {
            wa_setting_save('baileys.system_local_ip', $ip);
            wa_service_runtime_sync(true);
        }
    } catch (Throwable $e) {
    }
}

/**
 * Confere (a cada 5 min) se cada número entrega no endereço de webhook das
 * Configurações e corrige o que estiver diferente. Sem isso, trocar o campo
 * "Endereço que recebe as mensagens" podia não chegar ao número (ex.: a regravação
 * falhou na hora de salvar) e as mensagens continuavam indo para o endereço antigo.
 */
function wa_webhook_self_heal(bool $force = false): array
{
    $out = ['checked' => 0, 'fixed' => 0, 'failed' => 0];
    if (!function_exists('whatsapp_webhook_public_url') || !function_exists('whatsapp_list_all_instances')) {
        return $out;
    }
    try {
        $last = (int)admin_setting_get('baileys.webhook_check_at', '0');
        if (!$force && time() - $last < 300) {
            return $out;
        }
        wa_setting_save('baileys.webhook_check_at', (string)time());
        $want = whatsapp_webhook_public_url();
        if ($want === '' || !preg_match('#^https?://#i', $want)) {
            return $out;
        }
        foreach (whatsapp_list_all_instances() as $wi) {
            $name = (string)($wi['instance_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $cur = whatsapp_webhook_find($name);
            if (!$cur['ok']) {
                continue; // número que ainda não existe no serviço
            }
            $out['checked']++;
            if (rtrim($cur['url'], '/') === rtrim($want, '/') && $cur['enabled'] !== false) {
                continue;
            }
            $ok = whatsapp_webhook_configure($name, $want);
            $out[$ok ? 'fixed' : 'failed']++;
            if (function_exists('wa_event_log')) {
                wa_event_log($ok ? 'warn' : 'error', 'webhook', $ok ? 'webhook_corrigido' : 'webhook_nao_corrigido', [
                    'instance' => $name,
                    'summary' => $ok
                        ? 'O número entregava as mensagens em ' . ($cur['url'] !== '' ? $cur['url'] : '(nenhum endereço)') . '; corrigido para ' . $want . ' (Configurações)'
                        : 'O número entrega as mensagens em ' . ($cur['url'] !== '' ? $cur['url'] : '(nenhum endereço)') . ' e NÃO foi possível trocar para ' . $want,
                    'details' => ['antes' => $cur['url'], 'depois' => $want, 'ativoAntes' => $cur['enabled']],
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('[WEBHOOK_HEAL] ' . $e->getMessage());
    }
    return $out;
}

/**
 * Log do keepalive: registra quando o serviço sai do ar, enquanto continua
 * fora (a cada 30 min) e quando volta. Não registra cada verificação boa.
 */
function wa_service_keepalive_log(array $h): void
{
    if (!function_exists('wa_event_log')) {
        return;
    }
    try {
        $since = (int)admin_setting_get('baileys.keepalive_fail_since', '0');
        $lastLog = (int)admin_setting_get('baileys.keepalive_fail_logged', '0');
        $d = is_array($h['data'] ?? null) ? $h['data'] : [];
        if ($h['ok']) {
            if ($since > 0) {
                wa_setting_save('baileys.keepalive_fail_since', '0');
                wa_event_log('info', 'sistema', 'servico_voltou', [
                    'summary' => 'O serviço WhatsApp voltou a responder (ficou fora ' . max(1, (int)round((time() - $since) / 60)) . ' min)',
                    'details' => ['url' => $h['url'], 'fase' => $d['phase'] ?? null],
                ]);
            } elseif (empty($d['ready']) && time() - $lastLog > 1800) {
                // No ar, mas parado numa fase (aguardando chave, banco, outro processo...)
                wa_setting_save('baileys.keepalive_fail_logged', (string)time());
                wa_event_log('warn', 'sistema', 'servico_nao_pronto', [
                    'summary' => 'O serviço WhatsApp está no ar mas ainda não ficou pronto: ' . (string)($d['phase'] ?? '?'),
                    'error' => $d['error'] ?? null,
                    'details' => ['url' => $h['url'], 'fase' => $d['phase'] ?? null, 'ligadoHaSeg' => $d['uptimeSec'] ?? null],
                ]);
            }
            return;
        }
        if ($since <= 0) {
            wa_setting_save('baileys.keepalive_fail_since', (string)time());
        }
        if ($since <= 0 || time() - $lastLog > 1800) {
            wa_setting_save('baileys.keepalive_fail_logged', (string)time());
            wa_event_log('error', 'sistema', 'servico_fora_do_ar', [
                'summary' => 'O serviço WhatsApp NÃO respondeu ' . ($since > 0 ? '(fora há ' . (int)round((time() - $since) / 60) . ' min)' : '') . ': ' . (string)$h['error'] . '. Sem ele nada é enviado nem recebido.',
                'http' => (int)($h['http'] ?? 0) ?: null,
                'error' => (string)$h['error'],
                'details' => ['url' => $h['url'], 'urlManual' => wa_api_base_url_is_manual(), 'candidatas' => wa_service_candidate_urls()],
            ]);
        }
    } catch (Throwable $e) {
    }
}

/**
 * Configuração do WhatsApp que não depende do servidor (instância padrão,
 * telefone do admin...). Bases que ainda não salvaram a chave nova usam o valor
 * antigo (evolution.*), para não perder a configuração na troca.
 */
function wa_setting(string $name, string $default = ''): string
{
    $v = admin_setting_get('baileys.' . $name, null);
    if ($v === null || trim($v) === '') {
        $v = admin_setting_get('evolution.' . $name, null);
    }
    return $v === null || trim($v) === '' ? $default : trim($v);
}

/** WhatsApp padrão das mensagens automáticas (cron, aprovações, avisos). */
function wa_default_instance(): string
{
    return wa_setting('instance');
}

/** Serviço configurado (URL + chave)? */
function wa_api_configured(): bool
{
    return wa_api_base_url() !== '' && wa_api_key() !== '';
}

/** Cliente para uma instância (padrão: a instância padrão). */
function wa_api(?string $instance = null): WhatsAppApi
{
    return new WhatsAppApi(null, null, $instance);
}

/** Cabeçalhos de autenticação do serviço (para chamadas cURL diretas). */
function wa_api_headers(): array
{
    return ['apikey' => wa_api_key()];
}

final class WhatsAppApi
{
    private $baseUrl;
    private $apiKey;
    private $instance;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?string $instance = null)
    {
        $this->baseUrl = rtrim((string)($baseUrl !== null && $baseUrl !== '' ? $baseUrl : wa_api_base_url()), '/');
        $this->apiKey = (string)($apiKey !== null && $apiKey !== '' ? $apiKey : wa_api_key());
        $this->instance = (string)($instance !== null && $instance !== '' ? $instance : wa_default_instance());

        if ($this->baseUrl === '' || $this->apiKey === '' || $this->instance === '') {
            throw new RuntimeException('Serviço WhatsApp (Baileys) não configurado: confira Configurações → WhatsApp Conexão (endereço do serviço e WhatsApp padrão).');
        }
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function getBaseUrl(): string { return $this->baseUrl; }
    public function getApiKey(): string { return $this->apiKey; }
    public function getInstance(): string { return $this->instance; }

    private function inst(?string $instanceName = null): string
    {
        $i = (string)($instanceName ?? $this->instance);
        if ($i === '') {
            throw new RuntimeException('Instance não informada.');
        }
        return rawurlencode($i);
    }

    /** @return array{status:int, headers_raw:string, body_raw:string, json:mixed} */
    public function request(string $method, string $path, array $query = [], $body = null, int $timeoutSeconds = 30): array
    {
        $url = $this->url($path);
        if (count($query) > 0) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $t0 = microtime(true);

        try {
            $res = http_json_request($method, $url, ['apikey' => $this->apiKey], $body, $timeoutSeconds);
            // 503 = serviço acabando de ligar (painel da hospedagem sobe o app no
            // primeiro acesso): espera um pouco e tenta de novo uma vez.
            if ((int)$res['status'] === 503 && $timeoutSeconds >= 8) {
                sleep(4);
                $res = http_json_request($method, $url, ['apikey' => $this->apiKey], $body, $timeoutSeconds);
            }
        } catch (Throwable $e) {
            integration_log('whatsapp', $method . ' ' . $path, 'error', null, ['query' => $query, 'body' => $body], null, mb_strimwidth($e->getMessage(), 0, 250, ''), 1);
            $this->logEvent($method, $path, $body, null, $t0, $e);
            throw $e;
        }

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $this->logEvent($method, $path, $body, $res, $t0, null);
        integration_log(
            'whatsapp',
            $method . ' ' . $path,
            $ok ? 'success' : 'error',
            (int)$res['status'],
            ['query' => $query, 'body' => $body],
            $res['json'] ?? $res['body_raw'],
            $ok ? null : 'HTTP ' . (string)$res['status'],
            1
        );

        return $res;
    }

    /**
     * Log detalhado (/admin_whatsapp_eventos.php): erro em qualquer chamada ao
     * serviço (inclusive serviço fora do ar, que o serviço não tem como registrar)
     * e cada pedido de envio, com a parte do sistema que pediu.
     */
    private function logEvent(string $method, string $path, $body, ?array $res, float $t0, ?Throwable $e): void
    {
        if (!function_exists('wa_event_log')) {
            return;
        }
        try {
            $ms = (int)round((microtime(true) - $t0) * 1000);
            $status = $res ? (int)$res['status'] : 0;
            $route = $method . ' ' . preg_replace('#/[^/]+$#', '', parse_url($path, PHP_URL_PATH) ?: $path);
            $isSend = (bool)preg_match('#^/?(message/send|chat/(deleteMessageForEveryone|updateMessage)|group/sendInvite)#', ltrim($path, '/'));
            $b = is_array($body) ? $body : [];
            $number = (string)($b['number'] ?? ($b['groupJid'] ?? ($b['key']['remoteJid'] ?? ($b['remoteJid'] ?? ''))));
            $j = $res && is_array($res['json'] ?? null) ? $res['json'] : [];
            $msgId = (string)($j['key']['id'] ?? '');
            $base = [
                'instance' => $this->instance,
                'jid' => $number !== '' ? $number : null,
                'message_id' => $msgId !== '' ? $msgId : null,
                'http' => $status ?: null,
                'ms' => $ms,
                'status' => mb_substr($route, 0, 40),
            ];
            $caller = wa_event_log_caller();
            if ($e !== null || $status === 0) {
                wa_event_log('error', 'api', 'servico_inacessivel', $base + [
                    'summary' => 'O sistema NÃO conseguiu falar com o serviço WhatsApp (' . $route . '): ' . mb_substr($e ? $e->getMessage() : 'sem resposta', 0, 250),
                    'error' => $e,
                    'details' => ['url' => $this->baseUrl, 'rota' => $method . ' ' . $path, 'quemChamou' => $caller, 'tempoMs' => $ms],
                ]);
                return;
            }
            if ($status >= 400) {
                $msg = $j['response']['message'] ?? ($j['message'] ?? ($j['error'] ?? ''));
                if (is_array($msg)) {
                    $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
                }
                $msg = (string)$msg;
                // 404 de instância na conexão é esperado: o cliente cria a instância e tenta de novo
                $level = $status >= 500 ? 'error' : ($isSend ? 'error' : 'warn');
                wa_event_log($level, $isSend ? 'enviada' : 'api', $isSend ? 'envio_recusado' : 'api_erro', $base + [
                    'summary' => ($isSend ? 'Envio NÃO feito' : 'Chamada ao serviço falhou') . ' (' . $route . ' → HTTP ' . $status . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 250) : ''),
                    'error' => $msg !== '' ? $msg : 'HTTP ' . $status,
                    'details' => ['rota' => $method . ' ' . $path, 'quemChamou' => $caller, 'resposta' => mb_substr((string)($res['body_raw'] ?? ''), 0, 1500),
                        'texto' => isset($b['text']) ? mb_substr((string)$b['text'], 0, 200) : null],
                ]);
                return;
            }
            if ($isSend) {
                wa_event_log('debug', 'enviada', 'pedido_envio', $base + [
                    'summary' => 'Sistema pediu o envio (' . $route . ') · ' . $caller . (isset($b['text']) ? ' · "' . mb_substr((string)$b['text'], 0, 80) . '"' : ''),
                    'details' => ['quemChamou' => $caller, 'script' => (string)($_SERVER['SCRIPT_NAME'] ?? ''), 'tempoMs' => $ms],
                ]);
            }
        } catch (Throwable $t) {
            // o log nunca atrapalha o envio
        }
    }

    /** Informações do serviço (versão do Baileys / WhatsApp Web). */
    public function getInformation(): array
    {
        return $this->request('GET', '/', [], null, 8);
    }

    // --------------------
    // Instâncias (QR Code / status)
    // --------------------

    public function fetchInstances(?string $instanceName = null): array
    {
        $query = [];
        if ($instanceName !== null && $instanceName !== '') {
            $query['instanceName'] = $instanceName;
        }
        return $this->request('GET', '/instance/fetchInstances', $query, null, 12);
    }

    public function createInstanceBasic(array $payload): array
    {
        return $this->request('POST', '/instance/create', [], $payload, 40);
    }

    /**
     * QR Code (e código de pareamento se vier o número). Instância que existe no
     * sistema mas ainda não no serviço (ex.: vinda da Evolution) é criada na hora.
     */
    public function connectInstance(?string $instanceName = null, ?string $number = null): array
    {
        $query = [];
        if ($number !== null && $number !== '') {
            $query['number'] = $number;
        }
        $path = '/instance/connect/' . $this->inst($instanceName);
        $res = $this->request('GET', $path, $query, null, 40);
        if ((int)($res['status'] ?? 0) === 404 && $this->ensureInstance($instanceName)) {
            $res = $this->request('GET', $path, $query, null, 40);
        }
        return $res;
    }

    /**
     * Garante que a instância existe no serviço WhatsApp (cria se faltar) e
     * grava o webhook do sistema nela. true = existe/foi criada.
     */
    public function ensureInstance(?string $instanceName = null): bool
    {
        $name = (string)($instanceName ?? $this->instance);
        if ($name === '') {
            return false;
        }
        try {
            $found = $this->fetchInstances($name);
            $j = $found['json'] ?? null;
            if ((int)($found['status'] ?? 0) >= 200 && (int)($found['status'] ?? 0) < 300 && is_array($j) && count($j) > 0) {
                return true;
            }
            $created = $this->createInstanceBasic(['instanceName' => $name, 'qrcode' => false, 'integration' => 'WHATSAPP-BAILEYS']);
            $st = (int)($created['status'] ?? 0);
            $ok = ($st >= 200 && $st < 300) || $st === 403; // 403 = já existe
            if ($ok && function_exists('whatsapp_webhook_configure')) {
                whatsapp_webhook_configure($name);
            }
            if (!$ok) {
                error_log('[WHATSAPP_API] não foi possível criar a instância ' . $name . ' no serviço: HTTP ' . $st . ' ' . mb_substr((string)($created['body_raw'] ?? ''), 0, 200));
            }
            return $ok;
        } catch (Throwable $e) {
            error_log('[WHATSAPP_API] ensureInstance ' . $name . ': ' . $e->getMessage());
            return false;
        }
    }

    public function connectionState(?string $instanceName = null, int $timeoutSeconds = 10): array
    {
        return $this->request('GET', '/instance/connectionState/' . $this->inst($instanceName), [], null, $timeoutSeconds);
    }

    public function getConnectionStatus(?string $instanceName = null): array
    {
        return $this->connectionState($instanceName);
    }

    public function generateQrCode(?string $instanceName = null): array
    {
        return $this->connectInstance($instanceName);
    }

    /** Reinicia a conexão com a sessão salva (não pede QR Code de novo). */
    public function restartInstance(?string $instanceName = null): array
    {
        return $this->request('POST', '/instance/restart/' . $this->inst($instanceName), [], null, 30);
    }

    public function logoutInstance(?string $instanceName = null): array
    {
        return $this->request('DELETE', '/instance/logout/' . $this->inst($instanceName), [], null, 20);
    }

    public function deleteInstance(?string $instanceName = null): array
    {
        return $this->request('DELETE', '/instance/delete/' . $this->inst($instanceName), [], null, 20);
    }

    // --------------------
    // Webhook e opções da instância
    // --------------------

    public function setWebhook(array $payload, ?string $instanceName = null): array
    {
        return $this->request('POST', '/webhook/set/' . $this->inst($instanceName), [], $payload, 15);
    }

    public function findWebhook(?string $instanceName = null): array
    {
        return $this->request('GET', '/webhook/find/' . $this->inst($instanceName), [], null, 8);
    }

    public function setSettings(array $settings, ?string $instanceName = null): array
    {
        return $this->request('POST', '/settings/set/' . $this->inst($instanceName), [], $settings, 15);
    }

    public function findSettings(?string $instanceName = null): array
    {
        return $this->request('GET', '/settings/find/' . $this->inst($instanceName), [], null, 8);
    }

    // --------------------
    // Conversas
    // --------------------

    public function findChats(): array
    {
        return $this->request('POST', '/chat/findChats/' . $this->inst(), [], new stdClass());
    }

    public function findMessages(string $remoteJid): array
    {
        return $this->request('POST', '/chat/findMessages/' . $this->inst(), [], [
            'where' => ['key' => ['remoteJid' => $remoteJid]],
        ]);
    }

    /** Últimas mensagens de uma conversa (mais nova primeiro). */
    public function findRecentMessages(string $remoteJid, int $count = 30, int $timeoutSeconds = 8): array
    {
        return $this->request('POST', '/chat/findMessages/' . $this->inst(), [], [
            'where' => ['key' => ['remoteJid' => $remoteJid]],
            'page' => 1,
            'offset' => max(1, $count),
        ], $timeoutSeconds);
    }

    /** Baixa a mídia de uma mensagem (base64). */
    public function getBase64FromMediaMessage(array $key, array $message = [], int $timeoutSeconds = 20): array
    {
        return $this->request('POST', '/chat/getBase64FromMediaMessage/' . $this->inst(), [], [
            'message' => ['key' => $key, 'message' => $message ?: new stdClass()],
            'convertToMp4' => false,
        ], $timeoutSeconds);
    }

    public function fetchProfile(string $number, int $timeoutSeconds = 10): array
    {
        return $this->request('POST', '/chat/fetchProfile/' . $this->inst(), [], ['number' => $number], $timeoutSeconds);
    }

    public function fetchProfilePictureUrl(string $number, int $timeoutSeconds = 10): array
    {
        return $this->request('POST', '/chat/fetchProfilePictureUrl/' . $this->inst(), [], ['number' => $number], $timeoutSeconds);
    }

    public function archiveChat(array $lastMessageKey, bool $archive = true): array
    {
        return $this->request('POST', '/chat/archiveChat/' . $this->inst(), [], [
            'lastMessage' => ['key' => $lastMessageKey],
            'archive' => $archive,
        ]);
    }

    public function checkIsWhatsapp(array $numbers): array
    {
        return $this->request('POST', '/chat/whatsappNumbers/' . $this->inst(), [], ['numbers' => $numbers]);
    }

    public function markMessageAsRead(array $readMessages): array
    {
        return $this->request('POST', '/chat/markMessageAsRead/' . $this->inst(), [], ['readMessages' => $readMessages]);
    }

    public function deleteMessageForEveryone(array $key): array
    {
        return $this->request('DELETE', '/chat/deleteMessageForEveryone/' . $this->inst(), [], $key);
    }

    public function updateMessage(int $number, string $text, array $key): array
    {
        return $this->request('POST', '/chat/updateMessage/' . $this->inst(), [], [
            'number' => $number,
            'text' => $text,
            'key' => $key,
        ]);
    }

    public function findContacts(?string $id = null): array
    {
        $where = [];
        if ($id !== null && $id !== '') {
            $where['id'] = $id;
        }
        return $this->request('POST', '/chat/findContacts/' . $this->inst(), [], ['where' => (object)$where]);
    }

    public function findStatusMessage(array $where, ?int $limit = null): array
    {
        $body = ['where' => $where];
        if ($limit !== null) {
            $body['limit'] = $limit;
        }
        return $this->request('POST', '/chat/findStatusMessage/' . $this->inst(), [], $body);
    }

    // --------------------
    // Mensagens (conversas privadas + grupos)
    // --------------------

    /** Corpo do sendText (campo "text"; "textMessage.text" também é aceito pelo serviço). */
    private function textBody(string $number, string $text): array
    {
        return ['number' => $number, 'text' => $text];
    }

    public function sendText(string $number, string $text, array $options = []): array
    {
        $body = $this->textBody($number, $text);
        // delay/quoted/menções vão na raiz do corpo; "options" fica para chamadas antigas
        foreach (['linkPreview', 'delay', 'quoted', 'mentionsEveryOne', 'mentioned'] as $k) {
            if (array_key_exists($k, $options)) {
                $body[$k] = $options[$k];
                unset($options[$k]);
            }
        }
        if (!empty($options)) {
            $body['options'] = (object)$options;
        }
        return $this->request('POST', '/message/sendText/' . $this->inst(), [], $body);
    }

    /**
     * Texto para grupo (JID completo @g.us). Timeout maior: no primeiro envio
     * depois de conectar, o WhatsApp abre a criptografia com cada aparelho de
     * cada participante, o que leva bem mais de 30 s em grupo grande.
     */
    public function sendTextToGroup(string $groupJid, string $text, int $timeoutSeconds = 150): array
    {
        return $this->request('POST', '/message/sendText/' . $this->inst(), [], $this->textBody($groupJid, $text), $timeoutSeconds);
    }

    public function sendMedia(string $number, string $mediaType, string $fileName, string $media, ?string $caption = null, array $options = [], ?string $mimeType = null): array
    {
        $body = [
            'number' => $number,
            'mediatype' => $mediaType,
            'fileName' => $fileName,
            'media' => wa_media_for_api($media),
        ];
        if ($mimeType !== null && $mimeType !== '') {
            $body['mimetype'] = $mimeType;
        }
        if ($caption !== null && $caption !== '') {
            $body['caption'] = $caption;
        }
        foreach (['delay', 'quoted', 'mentionsEveryOne', 'mentioned'] as $k) {
            if (array_key_exists($k, $options)) {
                $body[$k] = $options[$k];
            }
        }
        try {
            return $this->request('POST', '/message/sendMedia/' . $this->inst(), [], $body, 120);
        } finally {
            wa_media_outbox_release($body['media']);
        }
    }

    public function sendContact(string $number, array $contactMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendContact/' . $this->inst(), [], [
            'number' => $number,
            'contact' => $contactMessage,
            'options' => (object)$options,
        ]);
    }

    /** Nota de voz (o serviço converte para ogg/opus). */
    public function sendWhatsAppAudio(string $number, string $audio, array $options = []): array
    {
        $body = ['number' => $number, 'audio' => wa_media_for_api($audio)];
        if (isset($options['delay'])) {
            $body['delay'] = $options['delay'];
        }
        try {
            return $this->request('POST', '/message/sendWhatsAppAudio/' . $this->inst(), [], $body, 120);
        } finally {
            wa_media_outbox_release($body['audio']);
        }
    }

    /** Modelos (templates) não existem no WhatsApp comum: o serviço responde 400. */
    public function sendTemplate(string $number, array $templateMessage): array
    {
        return $this->request('POST', '/message/sendTemplate/' . $this->inst(), [], [
            'number' => $number,
            'templateMessage' => $templateMessage,
        ]);
    }

    public function sendStatus(array $statusMessage): array
    {
        return $this->request('POST', '/message/sendStatus/' . $this->inst(), [], ['statusMessage' => $statusMessage], 60);
    }

    public function sendLocation(string $number, array $locationMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendLocation/' . $this->inst(), [], [
            'number' => $number,
            'locationMessage' => $locationMessage,
            'options' => (object)$options,
        ]);
    }

    public function sendReaction(array $reactionMessage): array
    {
        return $this->request('POST', '/message/sendReaction/' . $this->inst(), [], ['reactionMessage' => $reactionMessage]);
    }

    public function sendSticker(string $number, string $image, array $options = []): array
    {
        $sticker = wa_media_for_api($image);
        try {
            return $this->request('POST', '/message/sendSticker/' . $this->inst(), [], [
                'number' => $number,
                'sticker' => $sticker,
                'options' => (object)$options,
            ]);
        } finally {
            wa_media_outbox_release($sticker);
        }
    }

    public function sendPoll(string $number, array $pollMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendPoll/' . $this->inst(), [], [
            'number' => $number,
            'pollMessage' => $pollMessage,
            'options' => (object)$options,
        ]);
    }

    /** Listas não existem no WhatsApp comum: o serviço responde 400. */
    public function sendList(string $number, array $listMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendList/' . $this->inst(), [], [
            'number' => $number,
            'options' => (object)$options,
            'listMessage' => $listMessage,
        ]);
    }

    // --------------------
    // Grupos
    // --------------------

    public function fetchAllGroups(bool $getMembers = false, int $timeoutSeconds = 25): array
    {
        return $this->request('GET', '/group/fetchAllGroups/' . $this->inst(), ['getParticipants' => $getMembers ? 'true' : 'false'], null, $timeoutSeconds);
    }

    public function findGroupByJid(string $groupJid, int $timeoutSeconds = 15): array
    {
        return $this->request('GET', '/group/findGroupInfos/' . $this->inst(), ['groupJid' => $groupJid], null, $timeoutSeconds);
    }

    public function findGroupMembers(string $groupJid, int $timeoutSeconds = 20): array
    {
        return $this->request('GET', '/group/participants/' . $this->inst(), ['groupJid' => $groupJid], null, $timeoutSeconds);
    }

    public function createGroup(string $subject, array $participants, ?string $description = null): array
    {
        $body = ['subject' => $subject, 'participants' => $participants];
        if ($description !== null && $description !== '') {
            $body['description'] = $description;
        }
        return $this->request('POST', '/group/create/' . $this->inst(), [], $body, 60);
    }

    /** $action: add | remove | promote | demote */
    public function updateGroupMembers(string $groupJid, string $action, array $participants): array
    {
        return $this->request('POST', '/group/updateParticipant/' . $this->inst(), [], [
            'groupJid' => $groupJid,
            'action' => $action,
            'participants' => $participants,
        ], 60);
    }

    /** $action: announcement | not_announcement | locked | unlocked */
    public function updateGroupSetting(string $groupJid, string $action): array
    {
        return $this->request('POST', '/group/updateSetting/' . $this->inst(), [], ['groupJid' => $groupJid, 'action' => $action], 15);
    }

    public function updateGroupSubject(string $groupJid, string $subject): array
    {
        return $this->request('POST', '/group/updateGroupSubject/' . $this->inst(), ['groupJid' => $groupJid], ['subject' => $subject]);
    }

    public function updateGroupDescription(string $groupJid, string $description): array
    {
        return $this->request('POST', '/group/updateGroupDescription/' . $this->inst(), ['groupJid' => $groupJid], ['description' => $description]);
    }

    public function updateGroupPicture(string $groupJid, string $imageUrl): array
    {
        return $this->request('POST', '/group/updateGroupPicture/' . $this->inst(), ['groupJid' => $groupJid], ['image' => $imageUrl]);
    }

    public function fetchInviteCode(string $groupJid): array
    {
        return $this->request('GET', '/group/inviteCode/' . $this->inst(), ['groupJid' => $groupJid]);
    }

    public function acceptInviteCode(string $inviteCode): array
    {
        return $this->request('GET', '/group/acceptInviteCode/' . $this->inst(), ['inviteCode' => $inviteCode]);
    }

    public function revokeInviteCode(string $groupJid): array
    {
        return $this->request('POST', '/group/revokeInviteCode/' . $this->inst(), ['groupJid' => $groupJid]);
    }

    public function sendGroupInvite(string $groupJid, array $numbers, string $description): array
    {
        return $this->request('POST', '/group/sendInvite/' . $this->inst(), [], [
            'groupJid' => $groupJid,
            'numbers' => $numbers,
            'description' => $description,
        ]);
    }

    public function findGroupByInviteCode(string $inviteCode): array
    {
        return $this->request('GET', '/group/inviteInfo/' . $this->inst(), ['inviteCode' => $inviteCode]);
    }

    public function leaveGroup(string $groupJid): array
    {
        return $this->request('DELETE', '/group/leaveGroup/' . $this->inst(), ['groupJid' => $groupJid]);
    }

    public function toggleEphemeral(string $groupJid, int $expirationSeconds): array
    {
        return $this->request('POST', '/group/toggleEphemeral/' . $this->inst(), ['groupJid' => $groupJid], ['expiration' => $expirationSeconds]);
    }

    // --------------------
    // Atalhos de mídia
    // --------------------

    public function sendAudio(string $number, string $audioUrl, array $options = []): array
    {
        return $this->sendWhatsAppAudio($number, $audioUrl, $options);
    }

    public function sendImage(string $number, string $imageUrl, ?string $caption = null, array $options = []): array
    {
        $fileName = basename((string)parse_url($imageUrl, PHP_URL_PATH));
        return $this->sendMedia($number, 'image', $fileName, $imageUrl, $caption, $options);
    }

    public function sendVideo(string $number, string $videoUrl, ?string $caption = null, array $options = []): array
    {
        $fileName = basename((string)parse_url($videoUrl, PHP_URL_PATH));
        return $this->sendMedia($number, 'video', $fileName, $videoUrl, $caption, $options);
    }

    public function sendDocument(string $number, string $documentUrl, ?string $fileName = null, array $options = []): array
    {
        if ($fileName === null || $fileName === '') {
            $fileName = basename((string)parse_url($documentUrl, PHP_URL_PATH));
        }
        return $this->sendMedia($number, 'document', $fileName, $documentUrl, null, $options);
    }
}
