<?php

declare(strict_types=1);

/**
 * Log detalhado do WhatsApp (tabela baileys_event_log), lado do sistema.
 *
 * O serviço WhatsApp (ml-baileys-service) grava na mesma tabela com origem
 * "servico" tudo o que acontece no WhatsApp; aqui o sistema grava com origem
 * "sistema" o que ele fez com cada evento: webhook recebido, mensagem gravada
 * ou ignorada (e por quê), reação que virou interesse em captação (ou não),
 * erros nas chamadas ao serviço, falhas de keepalive e erros fatais do PHP.
 * Tela: /admin_whatsapp_eventos.php.
 *
 * Nunca atrapalha o fluxo: as linhas ficam num buffer e são gravadas num INSERT
 * só no fim do request (ou a cada 50 linhas / 5 s em rotinas longas). Qualquer
 * erro aqui é engolido.
 */

const WA_EVLOG_LEVELS = ['debug', 'info', 'warn', 'error'];

function wa_event_log_schema(): string
{
    return "CREATE TABLE IF NOT EXISTS baileys_event_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME(3) NOT NULL COMMENT 'UTC',
      source VARCHAR(10) NOT NULL COMMENT 'servico | sistema',
      level VARCHAR(8) NOT NULL COMMENT 'debug | info | warn | error',
      category VARCHAR(30) NOT NULL,
      event VARCHAR(80) NOT NULL,
      instance VARCHAR(100) NULL,
      remote_jid VARCHAR(150) NULL,
      message_id VARCHAR(150) NULL,
      status VARCHAR(40) NULL,
      http_status SMALLINT NULL,
      duration_ms INT NULL,
      summary VARCHAR(500) NULL,
      error TEXT NULL,
      details MEDIUMTEXT NULL,
      PRIMARY KEY (id),
      KEY idx_bel_time (created_at),
      KEY idx_bel_inst (instance, created_at),
      KEY idx_bel_cat (category, created_at),
      KEY idx_bel_level (level, created_at),
      KEY idx_bel_msg (message_id),
      KEY idx_bel_jid (remote_jid, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

/** Cria a tabela se faltar (a tela chama; o serviço também cria ao subir). */
function wa_event_log_ensure_table(?PDO $pdo = null): void
{
    static $done = false;
    if ($done) {
        return;
    }
    ($pdo ?? db())->exec(wa_event_log_schema());
    $done = true;
}

/** Grava o nível "debug"? (Configurações → WhatsApp: baileys.event_log_debug) */
function wa_event_log_debug_enabled(): bool
{
    static $v = null;
    if ($v === null) {
        try {
            $v = (string)admin_setting_get('baileys.event_log_debug', '1') !== '0';
        } catch (Throwable $e) {
            $v = true;
        }
    }
    return $v;
}

/** Dias que o log fica guardado (0 = para sempre). Padrão 90. */
function wa_event_log_retention_days(): int
{
    try {
        $v = admin_setting_get('baileys.event_log_retention_days', null);
    } catch (Throwable $e) {
        $v = null;
    }
    return $v === null || trim($v) === '' ? 90 : max(0, (int)$v);
}

/**
 * Registra um evento.
 *
 * @param string $level    debug | info | warn | error
 * @param string $category webhook | processamento | reacao | captacao | enviada | api | conexao | sistema ...
 * @param string $event    código curto (ex.: "mensagem_gravada")
 * @param array  $f        instance, jid, message_id, status, http, ms, summary, error, details
 */
function wa_event_log(string $level, string $category, string $event, array $f = []): void
{
    try {
        if (!in_array($level, WA_EVLOG_LEVELS, true)) {
            $level = 'info';
        }
        if ($level === 'debug' && !wa_event_log_debug_enabled()) {
            return;
        }
        $cut = static function ($v, int $n): ?string {
            if ($v === null || $v === '' || is_array($v)) {
                return null;
            }
            $s = (string)$v;
            return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
        };
        $details = null;
        if (isset($f['details']) && $f['details'] !== null) {
            $details = json_encode($f['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($details) && strlen($details) > 60000) {
                $details = substr($details, 0, 60000) . '…';
            }
        }
        $instance = $f['instance'] ?? ($GLOBALS['_webhookInstanceName'] ?? null);
        $err = $f['error'] ?? null;
        if ($err instanceof Throwable) {
            $err = get_class($err) . ': ' . $err->getMessage() . ' em ' . basename($err->getFile()) . ':' . $err->getLine();
        }
        $now = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true))) ?: new DateTime('now');
        $GLOBALS['_waEventLogBuffer'][] = [
            $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'sistema', $level, $cut($category, 30), $cut($event, 80),
            $cut($instance, 100), $cut($f['jid'] ?? null, 150), $cut($f['message_id'] ?? null, 150), $cut($f['status'] ?? null, 40),
            isset($f['http']) && is_numeric($f['http']) ? (int)$f['http'] : null,
            isset($f['ms']) && is_numeric($f['ms']) ? (int)round((float)$f['ms']) : null,
            $cut($f['summary'] ?? null, 500), $cut($err, 4000), $details,
        ];

        static $registered = false;
        static $firstAt = 0.0;
        if (!$registered) {
            $registered = true;
            register_shutdown_function('wa_event_log_shutdown');
        }
        if ($firstAt <= 0) {
            $firstAt = microtime(true);
        }
        // Rotinas longas (cron, fila): grava aos poucos
        if (count($GLOBALS['_waEventLogBuffer']) >= 50 || microtime(true) - $firstAt > 5) {
            $firstAt = 0.0;
            wa_event_log_flush();
        }
    } catch (Throwable $e) {
        // o log nunca pode atrapalhar
    }
}

/** Grava o buffer (um INSERT para todas as linhas). */
function wa_event_log_flush(bool $final = false): void
{
    $rows = $GLOBALS['_waEventLogBuffer'] ?? [];
    $GLOBALS['_waEventLogBuffer'] = [];
    if (!$rows) {
        return;
    }
    try {
        $pdo = wa_event_log_pdo();
        if (!$pdo) {
            // Transação aberta e sem conexão própria: tenta de novo depois (no fim do request)
            if (!$final && count($rows) < 2000) {
                $GLOBALS['_waEventLogBuffer'] = array_merge($rows, $GLOBALS['_waEventLogBuffer']);
            } else {
                error_log('[WA-EVENT-LOG] ' . count($rows) . ' evento(s) descartados: sem conexão para gravar');
            }
            return;
        }
        $ph = implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
        $sql = "INSERT INTO baileys_event_log (created_at, source, level, category, event, instance, remote_jid, message_id, status,
                    http_status, duration_ms, summary, error, details) VALUES $ph";
        $params = array_merge(...$rows);
        try {
            $pdo->prepare($sql)->execute($params);
        } catch (PDOException $e) {
            // Tabela ainda não existe (o serviço não subiu nesta base): cria e repete
            if ((int)($e->errorInfo[1] ?? 0) !== 1146) {
                throw $e;
            }
            wa_event_log_ensure_table($pdo);
            $pdo->prepare($sql)->execute($params);
        }
        if (random_int(1, 2000) === 1) {
            wa_event_log_cleanup($pdo);
        }
    } catch (Throwable $e) {
        error_log('[WA-EVENT-LOG] falha ao gravar ' . count($rows) . ' evento(s): ' . $e->getMessage());
    }
}

/**
 * Conexão para gravar. A do sistema, a não ser que esteja no meio de uma
 * transação (o log não pode entrar nela nem fazer commit implícito): aí abre
 * uma conexão própria.
 */
function wa_event_log_pdo(): ?PDO
{
    $main = db();
    if (!$main->inTransaction()) {
        return $main;
    }
    static $own = null;
    static $failed = false;
    if ($own instanceof PDO) {
        return $own;
    }
    if ($failed) {
        return null;
    }
    $failed = true; // uma tentativa por request (cada uma pode esperar o timeout)
    try {
        $config = require __DIR__ . '/../config/config.php';
        $d = $config['db'];
        $own = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], (int)$d['port'], $d['name'], $d['charset']),
            $d['user'], $d['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]
        );
        $failed = false;
        return $own;
    } catch (Throwable $e) {
        return null;
    }
}

/** Limpeza pelo prazo configurado (0 = nunca apaga). */
function wa_event_log_cleanup(?PDO $pdo = null): int
{
    $days = wa_event_log_retention_days();
    if ($days <= 0) {
        return 0;
    }
    $st = ($pdo ?? db())->prepare('DELETE FROM baileys_event_log WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ? DAY) LIMIT 5000');
    $st->execute([$days]);
    return $st->rowCount();
}

/** Fim do request: erro fatal do PHP em rotina do WhatsApp + grava o buffer. */
function wa_event_log_shutdown(): void
{
    try {
        $e = error_get_last();
        if ($e && in_array((int)$e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            $GLOBALS['_waEventLogFatal'] = true;
            wa_event_log('error', 'sistema', 'erro_fatal_php', [
                'summary' => 'O PHP parou com erro fatal no meio de uma rotina do WhatsApp (' . basename((string)$e['file']) . ':' . (int)$e['line'] . ')',
                'error' => (string)$e['message'],
                'details' => ['arquivo' => (string)$e['file'], 'linha' => (int)$e['line'], 'script' => (string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['argv'][0] ?? '')),
                    'memoriaMB' => round(memory_get_peak_usage(true) / 1048576, 1), 'limiteMemoria' => ini_get('memory_limit')],
            ]);
        }
    } catch (Throwable $t) {
    }
    wa_event_log_flush(true);
}

/**
 * Quem chamou a API do WhatsApp (primeiro arquivo fora deste cliente):
 * mostra na tela qual parte do sistema mandou a mensagem.
 */
function wa_event_log_caller(): string
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $fr) {
        $file = (string)($fr['file'] ?? '');
        if ($file === '' || basename($file) === 'whatsapp_api.php' || basename($file) === 'whatsapp_event_log.php') {
            continue;
        }
        $root = dirname(__DIR__);
        $rel = str_replace('\\', '/', strpos($file, $root) === 0 ? substr($file, strlen($root) + 1) : basename($file));
        return $rel . ':' . (int)($fr['line'] ?? 0);
    }
    return (string)($_SERVER['SCRIPT_NAME'] ?? '');
}

/**
 * Resultado do rastreio do webhook (chat_webhook_trace) no log detalhado:
 * cada mensagem/reação processada aparece com o que foi feito e por quê.
 */
function wa_event_log_from_trace(string $event, array $key, string $result, string $resolvedJid = '', string $text = ''): void
{
    $r = mb_strtolower($result);
    $map = [
        ['/^gravada/u', 'debug', 'processamento', 'mensagem_gravada', 'Mensagem gravada no chat do sistema'],
        ['/^eco do sistema/u', 'debug', 'processamento', 'eco_do_sistema', 'Eco de mensagem enviada pelo sistema: ligada à que já estava no chat'],
        ['/já existia/u', 'debug', 'processamento', 'ja_existia', 'Mensagem já estava no chat (entrega repetida)'],
        ['/^ignorada/u', 'warn', 'processamento', 'mensagem_ignorada', 'Mensagem NÃO foi para o chat'],
        ['/^erro/u', 'error', 'processamento', 'erro_processar', 'Erro ao gravar a mensagem no chat'],
        ['/interesse registrado/u', 'info', 'captacao', 'interesse_registrado', 'Interesse na captação registrado pela reação'],
        ['/interesse já existia/u', 'info', 'captacao', 'interesse_ja_existia', 'Reação repetida: o interesse já estava registrado'],
        ['/não é captação/u', 'info', 'reacao', 'reacao_sem_captacao', 'Reação gravada, mas a mensagem reagida não é de captação (ou não foi encontrada)'],
        ['/captação #\d+ encerrada/u', 'warn', 'captacao', 'reacao_captacao_encerrada', 'Reação ignorada: a captação já foi encerrada'],
        ['/^reação removida/u', 'info', 'reacao', 'reacao_removida', 'Reação removida'],
        ['/^reação gravada/u', 'debug', 'reacao', 'reacao_gravada', 'Reação nossa gravada'],
        ['/^reação: erro/u', 'error', 'captacao', 'interesse_erro', 'Erro ao registrar o interesse da reação'],
        ['/^reação ignorada/u', 'warn', 'reacao', 'reacao_ignorada', 'Reação ignorada'],
    ];
    [$level, $cat, $ev, $label] = ['info', 'processamento', 'rastreio', 'Webhook'];
    foreach ($map as $m) {
        if (preg_match($m[0], $r)) {
            [, $level, $cat, $ev, $label] = $m;
            break;
        }
    }
    wa_event_log($level, $cat, $ev, [
        'jid' => $resolvedJid !== '' ? $resolvedJid : ($key['remoteJid'] ?? null),
        'message_id' => $key['id'] ?? null,
        'status' => $event,
        'summary' => $label . ' · ' . $result . ($text !== '' && $cat === 'processamento' ? ' · "' . mb_substr($text, 0, 80) . '"' : ''),
        'error' => $level === 'error' ? $result : null,
        'details' => array_filter([
            'resultado' => $result,
            'texto' => $text !== '' ? mb_substr($text, 0, 300) : null,
            'deMim' => !empty($key['fromMe']),
            'remoteJid' => $key['remoteJid'] ?? null,
            'remoteJidAlt' => $key['remoteJidAlt'] ?? null,
            'participante' => $key['participant'] ?? null,
            'participanteNumero' => $key['participantPn'] ?? ($key['participantAlt'] ?? null),
        ], static fn($v) => $v !== null && $v !== ''),
    ]);
}
