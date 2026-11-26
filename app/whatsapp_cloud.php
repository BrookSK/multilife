<?php

declare(strict_types=1);

/**
 * API oficial do WhatsApp (Cloud API da Meta): configuração, chamadas à Graph
 * API, mídia, janela de 24 h, modelos (templates) e números.
 *
 * Configuração (Configurações → WhatsApp → API oficial, tela admin_whatsapp_cloud.php):
 *   cloud.access_token   token permanente do Usuário do Sistema (criptografado)
 *   cloud.app_secret     chave secreta do app (confere a assinatura do webhook; criptografada)
 *   cloud.verify_token   token de verificação do webhook (gerado sozinho)
 *   cloud.waba_id        id da conta do WhatsApp Business (WABA)
 *   cloud.graph_version  versão da Graph API (padrão v27.0)
 *   cloud.tpl.<uso>      modelo aprovado para cada uso ("nome|idioma")
 *   cloud.captacao_mode  direct (padrão: 1 mensagem para cada profissional) | group
 *
 * Cada número da Meta é uma "instância" do sistema (tabela whatsapp_instances,
 * coluna cloud_phone_number_id), então vínculo com usuários, filtros do chat e
 * WhatsApp da captação continuam iguais.
 */

const WA_CLOUD_GRAPH_HOST = 'https://graph.facebook.com';
const WA_CLOUD_DEFAULT_VERSION = 'v27.0';
/** Janela de atendimento da Meta (mensagem livre só até 24 h depois da última mensagem do contato). */
const WA_CLOUD_WINDOW_SECONDS = 86400;
/** Margem de segurança: perto do fim da janela já usa o modelo. */
const WA_CLOUD_WINDOW_MARGIN = 300;
/** Máximo de participantes num grupo da API oficial (regra da Meta). */
const WA_CLOUD_GROUP_MAX_PARTICIPANTS = 8;

/**
 * Usos dos modelos. A ordem de "params" é a ordem das variáveis {{1}}, {{2}}...
 * do corpo do modelo. "body" é o texto sugerido para criar o modelo pela tela.
 */
const WA_CLOUD_PURPOSES = [
    'aviso' => [
        'label' => 'Aviso (mensagens automáticas e respostas depois de 24 h)',
        'help' => 'Usado sempre que o sistema precisa mandar uma mensagem para quem não falou com o número nas últimas 24 h (aprovação de cadastro, link, lembrete, resposta do chat...). O texto da mensagem vai inteiro na variável {{1}}, em uma linha só.',
        'default_name' => 'multilife_aviso',
        'category' => 'UTILITY',
        'params' => ['texto' => 'Texto da mensagem'],
        'body' => "Olá! Mensagem da MultiLife Care:\n\n{{1}}\n\nSe precisar, responda por aqui que a nossa equipe atende.",
        'example' => ['Seu cadastro foi aprovado. Acesse o link enviado para concluir.'],
        'buttons' => [],
    ],
    'captacao' => [
        'label' => 'Captação (oportunidade de atendimento para o profissional)',
        'help' => 'Enviado a cada profissional da especialidade/região quando a captação sai pela API oficial e o profissional não falou com o número nas últimas 24 h. O botão "Tenho interesse" registra o interesse na Lista de Espera.',
        'default_name' => 'multilife_captacao',
        'category' => 'UTILITY',
        'params' => ['numero' => 'Número da captação', 'titulo' => 'Título', 'local' => 'Local (rua, bairro, cidade)', 'especialidade' => 'Especialidade', 'frequencia' => 'Frequência'],
        'body' => "Nova oportunidade de atendimento #{{1}}\n\n{{2}}\n\nLocal: {{3}}\nEspecialidade: {{4}}\nFrequência: {{5}}\n\nSe tiver interesse e disponibilidade, toque em \"Tenho interesse\". A nossa equipe entra em contato para alinhar os detalhes.",
        'example' => ['1234', 'Atendimento domiciliar de Fisioterapia', 'Rua das Flores - Centro - Campinas/SP', 'Fisioterapia', '3x por semana'],
        'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Tenho interesse']],
    ],
    'convite_grupo' => [
        'label' => 'Convite para grupo (link de entrada)',
        'help' => 'A API oficial não deixa adicionar pessoas no grupo: o sistema manda este convite com o link do grupo. Usado quando a pessoa não falou com o número nas últimas 24 h.',
        'default_name' => 'multilife_convite_grupo',
        'category' => 'UTILITY',
        'params' => ['grupo' => 'Nome do grupo', 'link' => 'Link de convite'],
        'body' => "Olá! Você foi convidado(a) para o grupo {{1}} da MultiLife Care.\n\nPara entrar, toque no link: {{2}}",
        'example' => ['Fisioterapia - Campinas/SP - 1', 'https://chat.whatsapp.com/ABCDEFGHIJ'],
        'buttons' => [],
    ],
];

// ---------------------------------------------------------------------------
// Configuração
// ---------------------------------------------------------------------------

function wa_cloud_setting(string $name, string $default = ''): string
{
    try {
        $v = admin_setting_get('cloud.' . $name, null);
    } catch (Throwable $e) {
        $v = null;
    }
    return $v === null || trim((string)$v) === '' ? $default : trim((string)$v);
}

/** Configuração secreta (gravada criptografada). */
function wa_cloud_secret(string $name): string
{
    $raw = wa_cloud_setting($name, '');
    return $raw === '' ? '' : trim(secure_decrypt($raw));
}

function wa_cloud_setting_save(string $name, string $value, bool $secret = false): void
{
    $value = trim($value);
    if ($secret && $value !== '') {
        $value = (string)secure_encrypt($value);
    }
    db()->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_by_user_id) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_user_id = VALUES(updated_by_user_id)')
        ->execute(['cloud.' . $name, $value, function_exists('auth_user_id') ? auth_user_id() : null]);
}

function wa_cloud_graph_version(): string
{
    $v = wa_cloud_setting('graph_version', WA_CLOUD_DEFAULT_VERSION);
    return preg_match('/^v\d{1,3}\.\d$/', $v) ? $v : WA_CLOUD_DEFAULT_VERSION;
}

function wa_cloud_access_token(): string
{
    return wa_cloud_secret('access_token');
}

function wa_cloud_waba_id(): string
{
    return (string)preg_replace('/\D+/', '', wa_cloud_setting('waba_id', ''));
}

/** Token que a Meta manda na verificação do webhook (gerado na primeira vez). */
function wa_cloud_verify_token(): string
{
    $t = wa_cloud_setting('verify_token', '');
    if ($t === '') {
        $t = 'ml-' . bin2hex(random_bytes(12));
        try {
            wa_cloud_setting_save('verify_token', $t);
        } catch (Throwable $e) {
            error_log('[WA_CLOUD] verify_token: ' . $e->getMessage());
        }
    }
    return $t;
}

/** Endereço que vai no campo "URL de retorno de chamada" do webhook no app da Meta. */
function wa_cloud_webhook_url(): string
{
    $custom = rtrim(trim(wa_cloud_setting('webhook_url', '')), '/');
    if ($custom !== '') {
        return $custom;
    }
    $base = function_exists('wa_system_public_url') ? wa_system_public_url() : '';
    if ($base !== '' && str_starts_with($base, 'http://')) {
        $base = 'https://' . substr($base, 7); // a Meta só aceita https
    }
    return $base !== '' ? $base . '/whatsapp_cloud_webhook.php' : '';
}

/** Credenciais mínimas preenchidas? */
function wa_cloud_configured(): bool
{
    return wa_cloud_access_token() !== '' && wa_cloud_waba_id() !== '';
}

/** Captação pela API oficial: "direct" (1 mensagem para cada profissional) ou "group". */
function wa_cloud_captacao_mode(): string
{
    return wa_cloud_setting('captacao_mode', 'direct') === 'group' ? 'group' : 'direct';
}

// ---------------------------------------------------------------------------
// Tabelas (criadas sozinhas; nenhuma migração manual)
// ---------------------------------------------------------------------------

function wa_cloud_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = [];
        foreach (db()->query('SHOW COLUMNS FROM whatsapp_instances')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[(string)$c['Field']] = true;
        }
        $add = [
            'cloud_phone_number_id' => 'VARCHAR(40) NULL',
            'cloud_display_phone' => 'VARCHAR(40) NULL',
            'cloud_verified_name' => 'VARCHAR(190) NULL',
            'cloud_quality' => 'VARCHAR(20) NULL',
            'cloud_state' => 'VARCHAR(40) NULL',
            'cloud_checked_at' => 'DATETIME NULL',
        ];
        foreach ($add as $col => $def) {
            if (!isset($cols[$col])) {
                db()->exec("ALTER TABLE whatsapp_instances ADD COLUMN $col $def");
            }
        }
        if (!isset($cols['cloud_phone_number_id'])) {
            try { db()->exec('ALTER TABLE whatsapp_instances ADD KEY idx_wi_cloud_pnid (cloud_phone_number_id)'); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        error_log('[WA_CLOUD] colunas whatsapp_instances: ' . $e->getMessage());
    }

    $tables = [
        // Cópia das mensagens no formato do resto do sistema (busca de mensagem, mídia antiga, sincronização)
        "CREATE TABLE IF NOT EXISTS wa_cloud_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_name VARCHAR(100) NOT NULL,
            remote_jid VARCHAR(150) NOT NULL,
            message_id VARCHAR(190) NOT NULL,
            from_me TINYINT(1) NOT NULL DEFAULT 0,
            message_ts INT UNSIGNED NOT NULL,
            media_id VARCHAR(80) NULL,
            record MEDIUMTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_wcm_msg (message_id),
            KEY idx_wcm_jid (instance_name, remote_jid, message_ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // Última mensagem recebida de cada contato por número (janela de 24 h)
        "CREATE TABLE IF NOT EXISTS wa_cloud_windows (
            instance_name VARCHAR(100) NOT NULL,
            contact_key VARCHAR(150) NOT NULL,
            last_inbound_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (instance_name, contact_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // Modelos (templates) da conta, copiados da Meta
        "CREATE TABLE IF NOT EXISTS wa_cloud_templates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id VARCHAR(40) NULL,
            name VARCHAR(190) NOT NULL,
            language VARCHAR(20) NOT NULL,
            status VARCHAR(30) NULL,
            category VARCHAR(30) NULL,
            quality VARCHAR(30) NULL,
            rejected_reason VARCHAR(190) NULL,
            components MEDIUMTEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_wct (name, language)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // Convites de grupo já enviados (não repetir o convite a cada captação)
        "CREATE TABLE IF NOT EXISTS wa_cloud_group_invites (
            group_id VARCHAR(150) NOT NULL,
            contact_key VARCHAR(150) NOT NULL,
            invited_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (group_id, contact_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // Criação de grupo é assíncrona na Meta: o id chega pelo webhook
        "CREATE TABLE IF NOT EXISTS wa_cloud_group_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id VARCHAR(120) NULL,
            instance_name VARCHAR(100) NULL,
            subject VARCHAR(190) NULL,
            group_id VARCHAR(150) NULL,
            invite_link VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_wcgr_req (request_id),
            KEY idx_wcgr_gid (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($tables as $sql) {
        try {
            db()->exec($sql);
        } catch (Throwable $e) {
            error_log('[WA_CLOUD] tabela: ' . $e->getMessage());
        }
    }
    // Link de convite do grupo (a API oficial só coloca gente no grupo por link)
    try {
        if (!db()->query("SHOW COLUMNS FROM whatsapp_groups LIKE 'invite_link'")->fetch()) {
            db()->exec('ALTER TABLE whatsapp_groups ADD COLUMN invite_link VARCHAR(255) NULL');
        }
    } catch (Throwable $e) {
        error_log('[WA_CLOUD] whatsapp_groups.invite_link: ' . $e->getMessage());
    }
    // Motivo da falha de entrega informado pela Meta (aparece no chat)
    try {
        if (!db()->query("SHOW COLUMNS FROM chat_messages LIKE 'send_error'")->fetch()) {
            db()->exec('ALTER TABLE chat_messages ADD COLUMN send_error VARCHAR(255) NULL');
        }
    } catch (Throwable $e) {
        error_log('[WA_CLOUD] chat_messages.send_error: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Números (instâncias)
// ---------------------------------------------------------------------------

/** Linha de whatsapp_instances da instância (com o phone_number_id da Meta) ou null. */
function wa_cloud_instance(string $instanceName): ?array
{
    if ($instanceName === '') {
        return null;
    }
    static $cache = [];
    if (array_key_exists($instanceName, $cache)) {
        return $cache[$instanceName];
    }
    wa_cloud_ensure_schema();
    try {
        $st = db()->prepare("SELECT * FROM whatsapp_instances WHERE instance_name = ? ORDER BY (status = 'active') DESC, id DESC LIMIT 1");
        $st->execute([$instanceName]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $row = null;
    }
    if ($row !== null && trim((string)($row['cloud_phone_number_id'] ?? '')) === '') {
        $row = null;
    }
    return $cache[$instanceName] = $row;
}

/** Instância do sistema ligada a um phone_number_id da Meta ('' se nenhuma). */
function wa_cloud_instance_by_phone_id(string $phoneNumberId): string
{
    if ($phoneNumberId === '') {
        return '';
    }
    wa_cloud_ensure_schema();
    try {
        $st = db()->prepare("SELECT instance_name FROM whatsapp_instances WHERE cloud_phone_number_id = ? ORDER BY (status = 'active') DESC, id DESC LIMIT 1");
        $st->execute([$phoneNumberId]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/** Instâncias ativas da API oficial. */
function wa_cloud_instances(): array
{
    wa_cloud_ensure_schema();
    try {
        return db()->query("SELECT * FROM whatsapp_instances WHERE status = 'active' AND cloud_phone_number_id IS NOT NULL AND cloud_phone_number_id <> '' ORDER BY is_default DESC, id ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Cadastra (ou atualiza) um número da Meta como instância do sistema.
 * @return array{ok:bool, instance:string, error:string}
 */
function wa_cloud_instance_save(string $phoneNumberId, string $instanceName, array $meta = [], ?int $userId = null): array
{
    wa_cloud_ensure_schema();
    $phoneNumberId = (string)preg_replace('/\D+/', '', $phoneNumberId);
    $instanceName = trim($instanceName);
    if ($phoneNumberId === '') {
        return ['ok' => false, 'instance' => '', 'error' => 'Informe o ID do número (Phone number ID) da Meta.'];
    }
    if ($instanceName === '' || !preg_match('/^[A-Za-z0-9_.\-]{2,60}$/', $instanceName)) {
        return ['ok' => false, 'instance' => '', 'error' => 'Nome interno inválido: use letras, números, ponto, traço ou sublinhado (2 a 60).'];
    }
    $display = (string)preg_replace('/\D+/', '', (string)($meta['display_phone_number'] ?? ''));
    try {
        $existingByPhone = wa_cloud_instance_by_phone_id($phoneNumberId);
        if ($existingByPhone !== '' && $existingByPhone !== $instanceName) {
            return ['ok' => false, 'instance' => $existingByPhone, 'error' => 'Este número da Meta já está cadastrado como "' . $existingByPhone . '".'];
        }
        $st = db()->prepare('SELECT id, status FROM whatsapp_instances WHERE instance_name = ? LIMIT 1');
        $st->execute([$instanceName]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            db()->prepare("UPDATE whatsapp_instances SET status = 'active', cloud_phone_number_id = ?, cloud_display_phone = ?, cloud_verified_name = ?,
                               cloud_quality = ?, owner_number = COALESCE(NULLIF(?, ''), owner_number), owner_phone_formatted = COALESCE(NULLIF(?, ''), owner_phone_formatted),
                               connection_status = 'connected'
                           WHERE id = ?")
                ->execute([$phoneNumberId, $display ?: null, $meta['verified_name'] ?? null, $meta['quality_rating'] ?? null, $display, $display, (int)$row['id']]);
        } else {
            $isFirst = (int)db()->query("SELECT COUNT(*) FROM whatsapp_instances WHERE status = 'active'")->fetchColumn() === 0;
            db()->prepare("INSERT INTO whatsapp_instances (instance_name, status, is_default, connection_status, created_by, owner_number, owner_phone_formatted,
                               cloud_phone_number_id, cloud_display_phone, cloud_verified_name, cloud_quality)
                           VALUES (?, 'active', ?, 'connected', ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$instanceName, $isFirst ? 1 : 0, $userId, $display ?: null, $display ?: null, $phoneNumberId, $display ?: null,
                    $meta['verified_name'] ?? null, $meta['quality_rating'] ?? null]);
        }
        return ['ok' => true, 'instance' => $instanceName, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'instance' => $instanceName, 'error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Graph API
// ---------------------------------------------------------------------------

/**
 * Chamada à Graph API.
 *
 * @param array $opts multipart (campos do formulário; arquivo = ['bin','mime','name']),
 *                    raw (true = não decodifica JSON; para baixar mídia),
 *                    token (outro token), max_bytes (limite do download)
 * @return array{status:int, headers_raw:string, body_raw:string, json:mixed, error:string}
 */
function wa_cloud_graph(string $method, string $path, array $query = [], $body = null, int $timeoutSeconds = 30, array $opts = []): array
{
    $method = strtoupper($method);
    $url = preg_match('#^https://#i', $path) ? $path : WA_CLOUD_GRAPH_HOST . '/' . wa_cloud_graph_version() . '/' . ltrim($path, '/');
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $token = (string)($opts['token'] ?? wa_cloud_access_token());
    $headers = ['Authorization' => 'Bearer ' . $token];

    // Testes: transporte falso
    if (isset($GLOBALS['WA_CLOUD_HTTP_MOCK']) && is_callable($GLOBALS['WA_CLOUD_HTTP_MOCK'])) {
        $r = ($GLOBALS['WA_CLOUD_HTTP_MOCK'])($method, $url, $headers, $body, $opts);
        $r += ['status' => 0, 'headers_raw' => '', 'body_raw' => '', 'json' => null, 'error' => ''];
        if ($r['json'] === null && empty($opts['raw']) && is_string($r['body_raw']) && $r['body_raw'] !== '') {
            $r['json'] = json_decode($r['body_raw'], true);
        }
        return $r;
    }
    if ($token === '') {
        return ['status' => 0, 'headers_raw' => '', 'body_raw' => '', 'json' => null,
            'error' => 'Token de acesso da API oficial não configurado (Configurações → WhatsApp → API oficial).'];
    }

    $ch = curl_init();
    $hdrs = [];
    foreach ($headers as $k => $v) {
        $hdrs[] = $k . ': ' . $v;
    }
    $curlOpts = [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => min(15, $timeoutSeconds),
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_USERAGENT => 'MultiLifeCare/1.0',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ];
    $tmpFiles = [];
    if (!empty($opts['multipart']) && is_array($opts['multipart'])) {
        $fields = [];
        foreach ($opts['multipart'] as $name => $value) {
            if (is_array($value) && isset($value['bin'])) {
                $mime = (string)($value['mime'] ?? 'application/octet-stream');
                $fname = (string)($value['name'] ?? 'arquivo');
                if (class_exists('CURLStringFile')) {
                    $fields[$name] = new CURLStringFile((string)$value['bin'], $fname, $mime);
                } else {
                    $tmp = tempnam(sys_get_temp_dir(), 'wac');
                    file_put_contents($tmp, (string)$value['bin']);
                    $tmpFiles[] = $tmp;
                    $fields[$name] = new CURLFile($tmp, $mime, $fname);
                }
            } else {
                $fields[$name] = (string)$value;
            }
        }
        $curlOpts[CURLOPT_POSTFIELDS] = $fields;
    } elseif ($body !== null) {
        $curlOpts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hdrs[] = 'Content-Type: application/json';
    }
    $curlOpts[CURLOPT_HTTPHEADER] = $hdrs;
    $maxBytes = (int)($opts['max_bytes'] ?? 0);
    if ($maxBytes > 0) {
        $curlOpts[CURLOPT_NOPROGRESS] = false;
        $curlOpts[CURLOPT_PROGRESSFUNCTION] = static function ($c, $dlTotal, $dlNow) use ($maxBytes) {
            return ($dlTotal > $maxBytes || $dlNow > $maxBytes) ? 1 : 0;
        };
    }
    curl_setopt_array($ch, $curlOpts);
    $raw = curl_exec($ch);
    $err = $raw === false ? curl_error($ch) : '';
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    unset($ch);
    foreach ($tmpFiles as $t) {
        @unlink($t);
    }
    if ($raw === false) {
        return ['status' => 0, 'headers_raw' => '', 'body_raw' => '', 'json' => null, 'error' => 'Falha de conexão com a Meta: ' . $err];
    }
    $headerRaw = substr((string)$raw, 0, $headerSize);
    $bodyRaw = substr((string)$raw, $headerSize);
    $json = null;
    if (empty($opts['raw'])) {
        $trim = ltrim($bodyRaw);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $json = json_decode($trim, true);
        }
    }
    return ['status' => $status, 'headers_raw' => $headerRaw, 'body_raw' => $bodyRaw, 'json' => $json, 'error' => ''];
}

function wa_cloud_ok(array $res): bool
{
    return (int)($res['status'] ?? 0) >= 200 && (int)($res['status'] ?? 0) < 300;
}

/** Código de erro da Meta (0 = sem código). */
function wa_cloud_error_code(array $res): int
{
    $j = is_array($res['json'] ?? null) ? $res['json'] : [];
    return (int)($j['error']['code'] ?? 0);
}

/** Erro da Meta em português (para o chat, a captação e o log). */
function wa_cloud_error_text(array $res): string
{
    if (($res['error'] ?? '') !== '') {
        return (string)$res['error'];
    }
    $j = is_array($res['json'] ?? null) ? $res['json'] : [];
    $e = is_array($j['error'] ?? null) ? $j['error'] : [];
    $code = (int)($e['code'] ?? 0);
    $known = [
        131047 => 'Fora da janela de 24 h: a pessoa não falou com este número nas últimas 24 h, então só dá para enviar um modelo aprovado.',
        131026 => 'Mensagem não entregue: o número pode não ter WhatsApp, estar com versão antiga ou ter bloqueado a empresa.',
        131051 => 'Tipo de mensagem não aceito pela API oficial.',
        131052 => 'A Meta não conseguiu baixar o arquivo enviado.',
        131053 => 'A Meta recusou o arquivo (tipo ou tamanho não aceito).',
        131056 => 'Muitas mensagens seguidas para a mesma pessoa: aguarde um pouco e tente de novo.',
        131048 => 'Limite de envio atingido por qualidade baixa do número: aguarde e reduza os envios.',
        131049 => 'A Meta decidiu não entregar esta mensagem agora para preservar a experiência da pessoa (limite de mensagens de marketing).',
        131031 => 'A conta do WhatsApp Business está bloqueada pela Meta.',
        131042 => 'Problema de pagamento na conta da Meta: confira a forma de pagamento no Gerenciador do WhatsApp.',
        131045 => 'Número não registrado na API oficial (falta concluir o cadastro no painel da Meta).',
        131009 => 'Valor inválido no pedido (ex.: número ou id de mensagem).',
        131008 => 'Falta um campo obrigatório no pedido.',
        131021 => 'Não dá para enviar mensagem para o próprio número.',
        130429 => 'Limite de envio por segundo da Meta atingido: o sistema vai tentar de novo mais devagar.',
        130472 => 'A Meta não entregou por estar testando novidades com este número de destino.',
        132000 => 'O modelo tem um número diferente de variáveis do que o sistema mandou.',
        132001 => 'O modelo não existe nesse idioma ou ainda não foi aprovado.',
        132005 => 'O texto do modelo ficou grande demais depois de preencher as variáveis.',
        132007 => 'O texto viola a política de formatação de modelos da Meta.',
        132012 => 'Variável do modelo em formato inválido.',
        132015 => 'O modelo foi pausado pela Meta (qualidade baixa).',
        132016 => 'O modelo foi desativado pela Meta.',
        132018 => 'Variável do modelo com quebra de linha ou espaços demais.',
        133010 => 'Número da empresa não registrado na Cloud API.',
        368 => 'A conta foi bloqueada temporariamente pela Meta por violação de política.',
        190 => 'O token de acesso da Meta expirou ou é inválido: gere um token permanente (Usuário do Sistema) e cole em Configurações → API oficial.',
        200 => 'O token não tem permissão para esta conta do WhatsApp (whatsapp_business_messaging / management).',
        10 => 'O token não tem permissão para esta ação.',
        100 => 'Parâmetro inválido no pedido à Meta.',
        4 => 'Limite de chamadas da Meta atingido: aguarde alguns minutos.',
        80007 => 'Limite de chamadas desta conta da Meta atingido: aguarde alguns minutos.',
    ];
    $detail = trim((string)($e['error_data']['details'] ?? ($e['message'] ?? '')));
    if ($code !== 0 && isset($known[$code])) {
        return $known[$code] . ($detail !== '' && $code === 100 ? ' (' . mb_substr($detail, 0, 160) . ')' : '') . ' [Meta ' . $code . ']';
    }
    if ($detail !== '') {
        return mb_substr($detail, 0, 300) . ($code ? ' [Meta ' . $code . ']' : '');
    }
    $st = (int)($res['status'] ?? 0);
    return $st ? 'A Meta respondeu HTTP ' . $st : 'Sem resposta da Meta';
}

/** Situação da configuração (para Configurações e diagnóstico). */
function wa_cloud_health(int $timeoutSeconds = 8): array
{
    $out = ['ok' => false, 'url' => WA_CLOUD_GRAPH_HOST, 'http' => 0, 'data' => null, 'error' => ''];
    if (wa_cloud_access_token() === '') {
        $out['error'] = 'token de acesso não configurado';
        return $out;
    }
    if (wa_cloud_waba_id() === '') {
        $out['error'] = 'ID da conta do WhatsApp Business (WABA) não configurado';
        return $out;
    }
    $res = wa_cloud_graph('GET', wa_cloud_waba_id(), ['fields' => 'id,name,currency,timezone_id,message_template_namespace'], null, $timeoutSeconds);
    $out['http'] = (int)$res['status'];
    $out['data'] = is_array($res['json'] ?? null) ? $res['json'] : null;
    $out['ok'] = wa_cloud_ok($res);
    if (!$out['ok']) {
        $out['error'] = wa_cloud_error_text($res);
    }
    return $out;
}

/** Números da conta (WABA) na Meta. */
function wa_cloud_list_phone_numbers(): array
{
    $res = wa_cloud_graph('GET', wa_cloud_waba_id() . '/phone_numbers', [
        'fields' => 'id,display_phone_number,verified_name,quality_rating,status,name_status,code_verification_status,messaging_limit_tier,platform_type',
        'limit' => 100,
    ], null, 15);
    if (!wa_cloud_ok($res)) {
        return ['ok' => false, 'error' => wa_cloud_error_text($res), 'items' => []];
    }
    $items = is_array($res['json']['data'] ?? null) ? $res['json']['data'] : [];
    return ['ok' => true, 'error' => '', 'items' => $items];
}

/** Dados de um número (estado na Meta). */
function wa_cloud_phone_info(string $phoneNumberId, int $timeoutSeconds = 10): array
{
    return wa_cloud_graph('GET', $phoneNumberId, [
        'fields' => 'id,display_phone_number,verified_name,quality_rating,status,name_status,code_verification_status,messaging_limit_tier,platform_type,throughput',
    ], null, $timeoutSeconds);
}

/** Inscreve o app na conta (sem isso a Meta não manda o webhook). */
function wa_cloud_subscribe_app(): array
{
    $res = wa_cloud_graph('POST', wa_cloud_waba_id() . '/subscribed_apps', [], new stdClass(), 15);
    return ['ok' => wa_cloud_ok($res), 'error' => wa_cloud_ok($res) ? '' : wa_cloud_error_text($res)];
}

/** Apps inscritos na conta. */
function wa_cloud_subscribed_apps(): array
{
    $res = wa_cloud_graph('GET', wa_cloud_waba_id() . '/subscribed_apps', [], null, 10);
    return ['ok' => wa_cloud_ok($res), 'items' => is_array($res['json']['data'] ?? null) ? $res['json']['data'] : [], 'error' => wa_cloud_ok($res) ? '' : wa_cloud_error_text($res)];
}

/** Registra o número na Cloud API (PIN de 6 dígitos da verificação em duas etapas). */
function wa_cloud_register_phone(string $phoneNumberId, string $pin): array
{
    $res = wa_cloud_graph('POST', $phoneNumberId . '/register', [], ['messaging_product' => 'whatsapp', 'pin' => $pin], 20);
    return ['ok' => wa_cloud_ok($res), 'error' => wa_cloud_ok($res) ? '' : wa_cloud_error_text($res)];
}

// ---------------------------------------------------------------------------
// Destino (número, BSUID ou grupo)
// ---------------------------------------------------------------------------

/**
 * Converte o "number" usado no resto do sistema no destino da Meta.
 * @return array{type:string, to?:string, recipient?:string, group?:string}
 */
function wa_cloud_target(string $numberOrJid): array
{
    $v = trim($numberOrJid);
    if (str_contains($v, '@g.us')) {
        return ['type' => 'group', 'group' => (string)preg_replace('/@g\.us$/', '', $v)];
    }
    $local = (string)preg_replace('/@.*$/', '', $v);
    $local = (string)preg_replace('/:\d+$/', '', $local);
    $digits = (string)preg_replace('/\D+/', '', $local);
    if ($digits !== '' && preg_match('/^\+?[\d\s\-().]+$/', $local)) {
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }
        return ['type' => 'individual', 'to' => $digits];
    }
    // Usuário com nome de usuário (sem telefone): BSUID "BR.123..."
    return ['type' => 'individual', 'recipient' => $local];
}

/** Campos de destino do corpo do envio. */
function wa_cloud_target_fields(array $target): array
{
    if ($target['type'] === 'group') {
        return ['recipient_type' => 'group', 'to' => $target['group']];
    }
    if (isset($target['recipient'])) {
        return ['recipient_type' => 'individual', 'recipient' => $target['recipient']];
    }
    return ['recipient_type' => 'individual', 'to' => $target['to']];
}

/** JID do sistema para um contato da Meta (wa_id ou BSUID). */
function wa_cloud_contact_jid(?string $waId, ?string $userId = null): string
{
    $waId = (string)preg_replace('/\D+/', '', (string)$waId);
    if ($waId !== '') {
        return $waId . '@s.whatsapp.net';
    }
    $userId = trim((string)$userId);
    return $userId !== '' ? $userId . '@s.whatsapp.net' : '';
}

/** JID do sistema para um grupo da Meta. */
function wa_cloud_group_jid(string $groupId): string
{
    return $groupId === '' ? '' : $groupId . '@g.us';
}

/** Chave do contato para a janela de 24 h (9º dígito normalizado). */
function wa_cloud_contact_key(string $numberOrJid): string
{
    $t = wa_cloud_target($numberOrJid);
    if (isset($t['to'])) {
        return function_exists('dispatch_member_key') ? dispatch_member_key($t['to']) : $t['to'];
    }
    return (string)($t['recipient'] ?? ($t['group'] ?? ''));
}

// ---------------------------------------------------------------------------
// Janela de 24 h
// ---------------------------------------------------------------------------

function wa_cloud_window_touch(string $instanceName, string $numberOrJid, int $ts): void
{
    $key = wa_cloud_contact_key($numberOrJid);
    if ($instanceName === '' || $key === '') {
        return;
    }
    wa_cloud_ensure_schema();
    try {
        db()->prepare('INSERT INTO wa_cloud_windows (instance_name, contact_key, last_inbound_at) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE last_inbound_at = GREATEST(last_inbound_at, VALUES(last_inbound_at))')
            ->execute([$instanceName, mb_substr($key, 0, 150), max(1, $ts)]);
    } catch (Throwable $e) {
        error_log('[WA_CLOUD] janela: ' . $e->getMessage());
    }
}

/** Unix da última mensagem recebida do contato neste número (0 = nunca). */
function wa_cloud_window_last(string $instanceName, string $numberOrJid): int
{
    $key = wa_cloud_contact_key($numberOrJid);
    if ($instanceName === '' || $key === '') {
        return 0;
    }
    wa_cloud_ensure_schema();
    try {
        $st = db()->prepare('SELECT last_inbound_at FROM wa_cloud_windows WHERE instance_name = ? AND contact_key = ?');
        $st->execute([$instanceName, $key]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Dá para mandar mensagem livre agora (dentro das 24 h)? Grupos: sempre. */
function wa_cloud_window_open(string $instanceName, string $numberOrJid): bool
{
    if (wa_cloud_target($numberOrJid)['type'] === 'group') {
        return true;
    }
    $last = wa_cloud_window_last($instanceName, $numberOrJid);
    return $last > 0 && (time() - $last) < (WA_CLOUD_WINDOW_SECONDS - WA_CLOUD_WINDOW_MARGIN);
}

/** Segundos que faltam para fechar a janela (0 = fechada). */
function wa_cloud_window_remaining(string $instanceName, string $numberOrJid): int
{
    $last = wa_cloud_window_last($instanceName, $numberOrJid);
    return $last > 0 ? max(0, WA_CLOUD_WINDOW_SECONDS - (time() - $last)) : 0;
}

// ---------------------------------------------------------------------------
// Mídia
// ---------------------------------------------------------------------------

/**
 * Baixa a mídia de uma mensagem recebida (id da mídia da Meta).
 * @return array{ok:bool, bin:string, mime:string, size:int, error:string}
 */
function wa_cloud_media_download(string $mediaId, string $phoneNumberId = '', int $maxBytes = 104857600): array
{
    $fail = static fn(string $e): array => ['ok' => false, 'bin' => '', 'mime' => '', 'size' => 0, 'error' => $e];
    if ($mediaId === '') {
        return $fail('mensagem sem id de mídia');
    }
    $info = wa_cloud_graph('GET', $mediaId, $phoneNumberId !== '' ? ['phone_number_id' => $phoneNumberId] : [], null, 15);
    if (!wa_cloud_ok($info) || empty($info['json']['url'])) {
        return $fail(wa_cloud_error_text($info));
    }
    $mime = (string)($info['json']['mime_type'] ?? 'application/octet-stream');
    $size = (int)($info['json']['file_size'] ?? 0);
    if ($size > $maxBytes) {
        return $fail('arquivo de ' . round($size / 1048576, 1) . ' MB passa do limite de ' . round($maxBytes / 1048576) . ' MB');
    }
    $dl = wa_cloud_graph('GET', (string)$info['json']['url'], [], null, 120, ['raw' => true, 'max_bytes' => $maxBytes]);
    if (!wa_cloud_ok($dl) || $dl['body_raw'] === '') {
        return $fail('download do arquivo falhou: ' . wa_cloud_error_text($dl));
    }
    return ['ok' => true, 'bin' => $dl['body_raw'], 'mime' => $mime, 'size' => strlen($dl['body_raw']), 'error' => ''];
}

/**
 * Envia um arquivo para a Meta e devolve o id da mídia.
 * @return array{ok:bool, id:string, error:string}
 */
function wa_cloud_media_upload(string $phoneNumberId, string $bin, string $mime, string $fileName): array
{
    $res = wa_cloud_graph('POST', $phoneNumberId . '/media', [], null, 120, ['multipart' => [
        'messaging_product' => 'whatsapp',
        'type' => $mime,
        'file' => ['bin' => $bin, 'mime' => $mime, 'name' => $fileName !== '' ? $fileName : 'arquivo'],
    ]]);
    $id = (string)($res['json']['id'] ?? '');
    return ['ok' => wa_cloud_ok($res) && $id !== '', 'id' => $id, 'error' => wa_cloud_ok($res) && $id !== '' ? '' : wa_cloud_error_text($res)];
}

/**
 * Lê o conteúdo de uma mídia passada pelo resto do sistema: base64 (com ou sem
 * "data:"), caminho /uploads/... desta instalação ou link https.
 * Link para o próprio domínio é lido do disco (a hospedagem recusa o servidor
 * abrir o próprio endereço).
 *
 * @return array{ok:bool, bin:string, mime:string, error:string}
 */
function wa_cloud_media_resolve(string $media, string $mimeHint = ''): array
{
    $media = trim($media);
    if ($media === '') {
        return ['ok' => false, 'bin' => '', 'mime' => '', 'error' => 'arquivo vazio'];
    }
    $root = dirname(__DIR__);
    $local = '';
    if (preg_match('#^/(uploads|storage|public)/#', $media)) {
        $local = $media;
    } elseif (preg_match('#^https?://#i', $media)) {
        $host = strtolower((string)parse_url($media, PHP_URL_HOST));
        $ownHosts = array_filter([
            strtolower((string)parse_url(function_exists('wa_system_public_url') ? wa_system_public_url() : '', PHP_URL_HOST)),
            strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))),
        ]);
        if ($host !== '' && in_array($host, $ownHosts, true)) {
            $local = (string)parse_url($media, PHP_URL_PATH);
        }
    }
    if ($local !== '') {
        $path = realpath($root . '/' . ltrim(rawurldecode($local), '/'));
        if ($path === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root) . '/') || !is_file($path)) {
            return ['ok' => false, 'bin' => '', 'mime' => '', 'error' => 'arquivo não encontrado nesta instalação: ' . $local];
        }
        $bin = (string)@file_get_contents($path);
        return ['ok' => $bin !== '', 'bin' => $bin, 'mime' => wa_cloud_sniff_mime($bin, $mimeHint, $path), 'error' => $bin !== '' ? '' : 'arquivo vazio'];
    }
    if (preg_match('#^https?://#i', $media)) {
        $ch = curl_init($media);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90, CURLOPT_USERAGENT => 'MultiLifeCare/1.0']);
        $bin = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        unset($ch);
        if (!is_string($bin) || $bin === '' || $code < 200 || $code >= 300) {
            return ['ok' => false, 'bin' => '', 'mime' => '', 'error' => 'não foi possível baixar o arquivo (' . ($err !== '' ? $err : 'HTTP ' . $code) . ')'];
        }
        return ['ok' => true, 'bin' => $bin, 'mime' => wa_cloud_sniff_mime($bin, $mimeHint !== '' ? $mimeHint : $ct, (string)parse_url($media, PHP_URL_PATH)), 'error' => ''];
    }
    $mimeFromData = '';
    if (preg_match('#^data:([^;,]+)[^,]*,#', $media, $m)) {
        $mimeFromData = $m[1];
    }
    $b64 = (string)preg_replace('#^data:[^,]*,#', '', $media);
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') {
        return ['ok' => false, 'bin' => '', 'mime' => '', 'error' => 'arquivo em base64 inválido'];
    }
    return ['ok' => true, 'bin' => $bin, 'mime' => wa_cloud_sniff_mime($bin, $mimeHint !== '' ? $mimeHint : $mimeFromData), 'error' => ''];
}

/** Tipo real do arquivo pelo conteúdo (com o informado como reserva). */
function wa_cloud_sniff_mime(string $bin, string $hint = '', string $name = ''): string
{
    $real = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        $real = $fi ? (string)@finfo_buffer($fi, $bin) : '';
    }
    $hint = strtolower(trim(explode(';', $hint)[0]));
    if (strncmp($bin, "\x1a\x45\xdf\xa3", 4) === 0) {
        return str_starts_with($hint, 'video/') ? 'video/webm' : 'audio/webm';
    }
    if (strncmp($bin, 'OggS', 4) === 0) {
        return 'audio/ogg';
    }
    if ($real !== '' && $real !== 'application/octet-stream' && $real !== 'text/plain') {
        return $real;
    }
    if ($hint !== '' && $hint !== 'application/octet-stream') {
        return $hint;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'txt' => 'text/plain', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'webp' => 'image/webp'];
    return $map[$ext] ?? ($real !== '' ? $real : 'application/octet-stream');
}

/**
 * Tipo de mensagem da Meta para o arquivo (a Meta só aceita alguns formatos em
 * cada tipo; o resto vai como documento).
 */
function wa_cloud_media_kind(string $wanted, string $mime): string
{
    $mime = strtolower($mime);
    $ok = [
        'image' => ['image/jpeg', 'image/png'],
        'video' => ['video/mp4', 'video/3gpp'],
        'audio' => ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg'],
        'sticker' => ['image/webp'],
    ];
    if ($wanted === 'document') {
        return 'document';
    }
    if (isset($ok[$wanted]) && in_array($mime, $ok[$wanted], true)) {
        return $wanted;
    }
    foreach ($ok as $kind => $list) {
        if ($kind !== 'sticker' && in_array($mime, $list, true)) {
            return $kind;
        }
    }
    return 'document';
}

// ---------------------------------------------------------------------------
// Cópia local das mensagens (formato do resto do sistema)
// ---------------------------------------------------------------------------

function wa_cloud_store_record(string $instanceName, array $record, ?string $mediaId = null): void
{
    $key = is_array($record['key'] ?? null) ? $record['key'] : [];
    $id = (string)($key['id'] ?? '');
    $jid = (string)($key['remoteJid'] ?? '');
    if ($instanceName === '' || $id === '' || $jid === '') {
        return;
    }
    wa_cloud_ensure_schema();
    $rec = $record;
    // a mídia em base64 não fica na cópia (só o id da Meta, que vale 30 dias)
    if (isset($rec['message']['base64'])) {
        unset($rec['message']['base64']);
    }
    try {
        db()->prepare('INSERT INTO wa_cloud_messages (instance_name, remote_jid, message_id, from_me, message_ts, media_id, record)
                       VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE record = VALUES(record), media_id = COALESCE(VALUES(media_id), media_id)')
            ->execute([$instanceName, mb_substr($jid, 0, 150), mb_substr($id, 0, 190), !empty($key['fromMe']) ? 1 : 0,
                max(1, (int)($record['messageTimestamp'] ?? time())), $mediaId !== null && $mediaId !== '' ? mb_substr($mediaId, 0, 80) : null,
                json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    } catch (Throwable $e) {
        error_log('[WA_CLOUD] cópia da mensagem: ' . $e->getMessage());
    }
}

function wa_cloud_find_record(string $messageId): ?array
{
    if ($messageId === '') {
        return null;
    }
    wa_cloud_ensure_schema();
    try {
        $st = db()->prepare('SELECT instance_name, media_id, record FROM wa_cloud_messages WHERE message_id = ? LIMIT 1');
        $st->execute([$messageId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $rec = json_decode((string)$row['record'], true);
        return ['instance' => (string)$row['instance_name'], 'media_id' => (string)($row['media_id'] ?? ''), 'record' => is_array($rec) ? $rec : []];
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
// Modelos (templates)
// ---------------------------------------------------------------------------

/** Copia os modelos da conta para wa_cloud_templates. */
function wa_cloud_templates_sync(): array
{
    wa_cloud_ensure_schema();
    if (!wa_cloud_configured()) {
        return ['ok' => false, 'count' => 0, 'error' => 'Configure o token e o ID da conta (WABA) primeiro.'];
    }
    $path = wa_cloud_waba_id() . '/message_templates';
    $query = ['fields' => 'id,name,language,status,category,components,quality_score,rejected_reason', 'limit' => 100];
    $count = 0;
    $seen = [];
    for ($page = 0; $page < 20; $page++) {
        $res = wa_cloud_graph('GET', $path, $query, null, 20);
        if (!wa_cloud_ok($res)) {
            return ['ok' => false, 'count' => $count, 'error' => wa_cloud_error_text($res)];
        }
        foreach ((array)($res['json']['data'] ?? []) as $t) {
            if (!is_array($t) || empty($t['name'])) {
                continue;
            }
            $seen[] = [(string)$t['name'], (string)($t['language'] ?? '')];
            db()->prepare('INSERT INTO wa_cloud_templates (template_id, name, language, status, category, quality, rejected_reason, components)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), status = VALUES(status), category = VALUES(category),
                               quality = VALUES(quality), rejected_reason = VALUES(rejected_reason), components = VALUES(components)')
                ->execute([
                    (string)($t['id'] ?? ''), (string)$t['name'], (string)($t['language'] ?? ''), (string)($t['status'] ?? ''),
                    (string)($t['category'] ?? ''), is_array($t['quality_score'] ?? null) ? (string)($t['quality_score']['score'] ?? '') : null,
                    isset($t['rejected_reason']) && $t['rejected_reason'] !== 'NONE' ? (string)$t['rejected_reason'] : null,
                    json_encode($t['components'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            $count++;
        }
        $after = (string)($res['json']['paging']['cursors']['after'] ?? '');
        if ($after === '' || empty($res['json']['paging']['next'])) {
            break;
        }
        $query['after'] = $after;
    }
    // Modelos apagados na Meta saem da lista
    try {
        $all = db()->query('SELECT id, name, language FROM wa_cloud_templates')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all as $row) {
            if (!in_array([(string)$row['name'], (string)$row['language']], $seen, true)) {
                db()->prepare('DELETE FROM wa_cloud_templates WHERE id = ?')->execute([(int)$row['id']]);
            }
        }
    } catch (Throwable $e) {}
    try {
        wa_cloud_setting_save('templates_synced_at', (string)time());
    } catch (Throwable $e) {}
    return ['ok' => true, 'count' => $count, 'error' => ''];
}

function wa_cloud_templates_list(): array
{
    wa_cloud_ensure_schema();
    try {
        return db()->query('SELECT * FROM wa_cloud_templates ORDER BY name, language')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function wa_cloud_template_find(string $name, string $lang): ?array
{
    wa_cloud_ensure_schema();
    try {
        $st = db()->prepare('SELECT * FROM wa_cloud_templates WHERE name = ? AND language = ? LIMIT 1');
        $st->execute([$name, $lang]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Estrutura de um modelo: variáveis do corpo/cabeçalho e botões.
 * @return array{body_vars:int, header_vars:int, header_format:string, quick_replies:int, url_vars:array, body:string}
 */
function wa_cloud_template_shape(array $templateRow): array
{
    $comps = json_decode((string)($templateRow['components'] ?? '[]'), true);
    $out = ['body_vars' => 0, 'header_vars' => 0, 'header_format' => '', 'quick_replies' => 0, 'url_vars' => [], 'body' => ''];
    foreach (is_array($comps) ? $comps : [] as $c) {
        $type = strtoupper((string)($c['type'] ?? ''));
        if ($type === 'BODY') {
            $out['body'] = (string)($c['text'] ?? '');
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $out['body'], $m);
            $out['body_vars'] = $m[1] ? max(array_map('intval', $m[1])) : 0;
        } elseif ($type === 'HEADER') {
            $out['header_format'] = strtoupper((string)($c['format'] ?? 'TEXT'));
            if ($out['header_format'] === 'TEXT') {
                preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string)($c['text'] ?? ''), $m);
                $out['header_vars'] = $m[1] ? max(array_map('intval', $m[1])) : 0;
            }
        } elseif ($type === 'BUTTONS') {
            foreach ((array)($c['buttons'] ?? []) as $i => $b) {
                $bt = strtoupper((string)($b['type'] ?? ''));
                if ($bt === 'QUICK_REPLY') {
                    $out['quick_replies']++;
                } elseif ($bt === 'URL' && str_contains((string)($b['url'] ?? ''), '{{')) {
                    $out['url_vars'][] = (int)$i;
                }
            }
        }
    }
    return $out;
}

/** Modelo escolhido para um uso (ou null se não configurado). */
function wa_cloud_purpose_template(string $purpose): ?array
{
    if (!isset(WA_CLOUD_PURPOSES[$purpose])) {
        return null;
    }
    $cfg = wa_cloud_setting('tpl.' . $purpose, '');
    if ($cfg === '') {
        return null;
    }
    [$name, $lang] = array_pad(explode('|', $cfg, 2), 2, '');
    $name = trim($name);
    $lang = trim($lang) !== '' ? trim($lang) : wa_cloud_setting('default_lang', 'pt_BR');
    if ($name === '') {
        return null;
    }
    $row = wa_cloud_template_find($name, $lang);
    return ['name' => $name, 'lang' => $lang, 'row' => $row, 'approved' => $row !== null && strtoupper((string)$row['status']) === 'APPROVED'];
}

/**
 * Valor de variável de modelo: a Meta recusa quebra de linha, tabulação e mais de
 * 4 espaços seguidos (erro 132018). Junta as linhas com " · ".
 */
function wa_cloud_flatten_param(string $value, int $max = 900): string
{
    $v = str_replace(["\r\n", "\r"], "\n", $value);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $v)), static fn($l) => $l !== ''));
    $v = implode(' · ', $lines);
    $v = (string)preg_replace('/[ \t]{2,}|\t/', ' ', $v);
    $v = trim($v);
    if ($v === '') {
        $v = '-';
    }
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
}

/**
 * Objeto "template" do envio para um uso.
 *
 * @param array $values valores das variáveis na ordem de WA_CLOUD_PURPOSES[uso][params]
 * @param array $buttonPayloads payload de cada botão de resposta rápida (na ordem)
 * @return array{ok:bool, template?:array, error?:string, name?:string}
 */
function wa_cloud_template_payload(string $purpose, array $values, array $buttonPayloads = []): array
{
    $sel = wa_cloud_purpose_template($purpose);
    $label = WA_CLOUD_PURPOSES[$purpose]['label'] ?? $purpose;
    if ($sel === null) {
        return ['ok' => false, 'error' => 'Fora da janela de 24 h e sem modelo configurado para "' . $label . '". Configure em Configurações → WhatsApp → API oficial → Modelos.'];
    }
    if ($sel['row'] === null) {
        return ['ok' => false, 'error' => 'O modelo "' . $sel['name'] . '" (' . $sel['lang'] . ') não foi encontrado na conta. Clique em "Atualizar modelos da Meta" na tela da API oficial.'];
    }
    if (!$sel['approved']) {
        return ['ok' => false, 'error' => 'O modelo "' . $sel['name'] . '" ainda não está aprovado pela Meta (situação: ' . (string)$sel['row']['status'] . ').'];
    }
    $shape = wa_cloud_template_shape($sel['row']);
    $values = array_values($values);
    $comps = [];
    if ($shape['header_vars'] > 0) {
        $hp = [];
        for ($i = 0; $i < $shape['header_vars']; $i++) {
            $hp[] = ['type' => 'text', 'text' => wa_cloud_flatten_param((string)($values[$i] ?? '-'), 60)];
        }
        $comps[] = ['type' => 'header', 'parameters' => $hp];
    }
    if ($shape['body_vars'] > 0) {
        $bp = [];
        for ($i = 0; $i < $shape['body_vars']; $i++) {
            $bp[] = ['type' => 'text', 'text' => wa_cloud_flatten_param((string)($values[$i] ?? '-'))];
        }
        $comps[] = ['type' => 'body', 'parameters' => $bp];
    }
    for ($i = 0; $i < $shape['quick_replies']; $i++) {
        if (isset($buttonPayloads[$i]) && $buttonPayloads[$i] !== '') {
            $comps[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string)$i,
                'parameters' => [['type' => 'payload', 'payload' => mb_substr((string)$buttonPayloads[$i], 0, 128)]]];
        }
    }
    $tpl = ['name' => $sel['name'], 'language' => ['code' => $sel['lang']]];
    if ($comps) {
        $tpl['components'] = $comps;
    }
    return ['ok' => true, 'template' => $tpl, 'name' => $sel['name'], 'body' => $shape['body']];
}

/** Texto do modelo preenchido (para mostrar no chat o que a pessoa recebeu). */
function wa_cloud_template_render(string $body, array $values): string
{
    $values = array_values($values);
    return (string)preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', static function ($m) use ($values) {
        return wa_cloud_flatten_param((string)($values[(int)$m[1] - 1] ?? '-'));
    }, $body);
}

/**
 * Cria na Meta o modelo padrão de um uso (vai para aprovação, que leva de minutos a 24 h).
 * @return array{ok:bool, error:string, name:string}
 */
function wa_cloud_template_create_standard(string $purpose, string $lang = 'pt_BR', ?string $name = null, ?string $category = null): array
{
    $def = WA_CLOUD_PURPOSES[$purpose] ?? null;
    if ($def === null) {
        return ['ok' => false, 'error' => 'uso desconhecido', 'name' => ''];
    }
    $name = strtolower((string)preg_replace('/[^a-z0-9_]/i', '_', $name ?? $def['default_name']));
    $components = [[
        'type' => 'BODY',
        'text' => $def['body'],
        'example' => ['body_text' => [$def['example']]],
    ]];
    if ($def['buttons']) {
        $components[] = ['type' => 'BUTTONS', 'buttons' => $def['buttons']];
    }
    $res = wa_cloud_graph('POST', wa_cloud_waba_id() . '/message_templates', [], [
        'name' => $name,
        'language' => $lang,
        'category' => strtoupper($category ?? $def['category']),
        'components' => $components,
    ], 20);
    if (wa_cloud_ok($res)) {
        try {
            wa_cloud_setting_save('tpl.' . $purpose, $name . '|' . $lang);
        } catch (Throwable $e) {}
        wa_cloud_templates_sync();
        return ['ok' => true, 'error' => '', 'name' => $name];
    }
    return ['ok' => false, 'error' => wa_cloud_error_text($res), 'name' => $name];
}
