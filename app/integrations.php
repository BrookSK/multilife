<?php

declare(strict_types=1);

/*
 * Log de integrações (tabela integration_logs).
 *
 * A tabela crescia sem limite: cada chamada à Evolution (inclusive as consultas de
 * status feitas o tempo todo) e cada webhook gravavam o request/response INTEIRO,
 * com mídia em base64. Agora:
 *   1. Consultas bem-sucedidas sem efeito (GET na Evolution, webhook ignorado) não
 *      são gravadas. Erros são sempre gravados.
 *   2. Payloads são enxugados (base64 omitido, textos longos cortados, limite de
 *      tamanho por registro).
 *   3. Registros antigos são ARQUIVADOS em arquivo compactado (.jsonl.gz) no
 *      servidor (storage/log_archive/integration_logs/AAAA-MM.jsonl.gz) e apagados
 *      da tabela. Roda sozinho a cada 10 min (em segundo plano, no fim de uma
 *      requisição), pelo cron cron/integration_logs_cleanup.php ou pelo botão em
 *      Logs Técnicos.
 *
 * Configurações (admin_settings), todas opcionais:
 *   logs.integration_retention_days_success  dias na tabela p/ sucessos (padrão 7)
 *   logs.integration_retention_days_error    dias na tabela p/ erros    (padrão 30)
 *   logs.integration_archive                 '1' arquiva antes de apagar (padrão), '0' só apaga
 *   logs.integration_archive_months          meses que os arquivos ficam no servidor (padrão 12)
 *   logs.integration_log_success_reads       '1' volta a gravar GETs bem-sucedidos (diagnóstico)
 */

const INTEGRATION_LOG_MAX_SUCCESS_BYTES = 8192;
const INTEGRATION_LOG_MAX_ERROR_BYTES = 65536;

/** Pasta dos arquivos de log arquivados (protegida contra acesso pela web). */
function integration_logs_archive_dir(): string
{
    return dirname(__DIR__) . '/storage/log_archive/integration_logs';
}

/**
 * Enxuga um valor antes de gravar: troca base64/binários por um aviso e corta
 * textos muito longos. Mantém a estrutura (chaves) para continuar legível.
 */
function integration_log_shrink($value, int $depth = 0)
{
    if ($depth > 12) {
        return '[…]';
    }
    if (is_array($value)) {
        $out = [];
        $i = 0;
        foreach ($value as $k => $v) {
            if (++$i > 200) {
                $out['_omitidos'] = count($value) - 200;
                break;
            }
            $out[$k] = integration_log_shrink($v, $depth + 1);
        }
        return $out;
    }
    if (is_object($value)) {
        return integration_log_shrink((array)$value, $depth);
    }
    if (is_string($value)) {
        $len = strlen($value);
        if ($len > 512 && preg_match('~^(data:[\w/+.-]+;base64,)?[A-Za-z0-9+/=\r\n]+$~', substr($value, 0, 4096))) {
            return '[base64 omitido: ' . $len . ' bytes]';
        }
        if ($len > 2000) {
            return mb_strcut($value, 0, 2000, 'UTF-8') . '… [cortado: ' . $len . ' bytes]';
        }
    }
    return $value;
}

/** JSON válido (a coluna é JSON) e com tamanho limitado. */
function integration_log_encode($payload, bool $isError): ?string
{
    if ($payload === null) {
        return null;
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    $json = json_encode(integration_log_shrink($payload), $flags);
    if (!is_string($json)) {
        return null;
    }
    $max = $isError ? INTEGRATION_LOG_MAX_ERROR_BYTES : INTEGRATION_LOG_MAX_SUCCESS_BYTES;
    if (strlen($json) > $max) {
        $json = json_encode([
            '_truncado' => true,
            'tamanho_original' => strlen($json),
            'inicio' => mb_strcut($json, 0, $max - 200, 'UTF-8'),
        ], $flags);
    }
    return is_string($json) ? $json : null;
}

/** Registros que só enchem a tabela e nunca são consultados. */
function integration_log_should_skip(string $provider, string $action, string $status): bool
{
    if ($status !== 'success') {
        return false; // erro: sempre grava
    }
    // Webhook de conversa privada: só é ignorado por este endpoint (o chat_webhook trata).
    if ($provider === 'evolution_webhook' && $action === 'inbound_non_group') {
        return true;
    }
    // Consultas à Evolution (status da conexão, participantes, instâncias...).
    if ($provider === 'evolution' && strncmp($action, 'GET ', 4) === 0) {
        static $logReads = null;
        if ($logReads === null) {
            try {
                $logReads = (string)admin_setting_get('logs.integration_log_success_reads', '0') === '1';
            } catch (Throwable $e) {
                $logReads = false;
            }
        }
        return !$logReads;
    }
    return false;
}

function integration_log(
    string $provider,
    string $action,
    string $status,
    ?int $httpStatus,
    $requestPayload,
    $responsePayload,
    ?string $errorMessage,
    int $attempts = 1
): void {
    $status = in_array($status, ['success', 'error'], true) ? $status : 'error';
    if (integration_log_should_skip($provider, $action, $status)) {
        return;
    }
    $isError = $status === 'error';
    // Falha ao gravar o log NUNCA pode derrubar a operação que está sendo registrada.
    try {
        $stmt = db()->prepare(
            'INSERT INTO integration_logs (provider, action, status, http_status, request_payload, response_payload, error_message, attempts)
             VALUES (:p, :a, :s, :hs, :req, :res, :err, :att)'
        );
        $stmt->execute([
            'p' => mb_substr($provider, 0, 60),
            'a' => mb_substr($action, 0, 80),
            's' => $status,
            'hs' => $httpStatus,
            'req' => integration_log_encode($requestPayload, $isError),
            'res' => integration_log_encode($responsePayload, $isError),
            'err' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 255),
            'att' => max(1, $attempts),
        ]);
    } catch (Throwable $e) {
        error_log('[INTEGRATION_LOG] falha ao gravar log: ' . $e->getMessage());
    }

    integration_logs_schedule_maintenance();
}

/**
 * Agenda a limpeza automática (no fim da requisição, no máx. a cada 10 min,
 * com tempo limitado), para não depender de cron configurado.
 */
function integration_logs_schedule_maintenance(): void
{
    static $scheduled = false;
    if ($scheduled) {
        return;
    }
    $stamp = sys_get_temp_dir() . '/multilife_intlog_maint_' . md5(__DIR__) . '.ts';
    if (is_file($stamp) && (time() - (int)@filemtime($stamp)) < 600) {
        return;
    }
    $scheduled = true;
    @touch($stamp);
    register_shutdown_function(static function (): void {
        try {
            integration_logs_maintain(2);
        } catch (Throwable $e) {
            error_log('[INTEGRATION_LOG] limpeza automática: ' . $e->getMessage());
        }
    });
}

/** Garante a pasta de arquivo, bloqueada para acesso pela web. */
function integration_logs_ensure_archive_dir(): string
{
    $dir = integration_logs_archive_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $storage = dirname(__DIR__) . '/storage';
    if (is_dir($storage) && !is_file($storage . '/.htaccess')) {
        @file_put_contents($storage . '/.htaccess', "# Arquivos internos: nunca servir pela web\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    if (is_dir($storage) && !is_file($storage . '/index.html')) {
        @file_put_contents($storage . '/index.html', '');
    }
    return $dir;
}

/**
 * Arquiva (opcional) e apaga os registros antigos, em lotes, até $maxSeconds.
 *
 * @return array{archived:int, deleted:int, more:bool, error:string}
 */
function integration_logs_maintain(int $maxSeconds = 2): array
{
    $result = ['archived' => 0, 'deleted' => 0, 'more' => false, 'error' => ''];
    $db = db();

    // Uma limpeza por vez (várias requisições podem disparar ao mesmo tempo).
    try {
        if ((int)$db->query("SELECT GET_LOCK('multilife_intlog_maint', 0)")->fetchColumn() !== 1) {
            $result['error'] = 'Outra limpeza já está em andamento.';
            return $result;
        }
    } catch (Throwable $e) {
        // sem GET_LOCK: segue mesmo assim
    }

    try {
        $daysOk = max(1, (int)admin_setting_get('logs.integration_retention_days_success', '7'));
        $daysErr = max(1, (int)admin_setting_get('logs.integration_retention_days_error', '30'));
        $archive = (string)admin_setting_get('logs.integration_archive', '1') !== '0';
        $cutOk = date('Y-m-d H:i:s', time() - $daysOk * 86400);
        $cutErr = date('Y-m-d H:i:s', time() - $daysErr * 86400);
        $dir = $archive ? integration_logs_ensure_archive_dir() : '';
        if ($archive && (!is_dir($dir) || !is_writable($dir))) {
            $result['error'] = 'Sem permissão para gravar em ' . $dir . '. Nada foi apagado.';
            return $result;
        }

        $sel = $db->prepare(
            "SELECT * FROM integration_logs
             WHERE (status = 'success' AND created_at < :c1) OR (status <> 'success' AND created_at < :c2)
             ORDER BY id ASC LIMIT 1000"
        );
        $start = microtime(true);
        while (true) {
            $sel->execute(['c1' => $cutOk, 'c2' => $cutErr]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            if ($archive) {
                // Agrupa por mês do registro: um arquivo .jsonl.gz por mês.
                $byMonth = [];
                foreach ($rows as $r) {
                    $byMonth[substr((string)$r['created_at'], 0, 7)][] = $r;
                }
                foreach ($byMonth as $month => $list) {
                    $file = $dir . '/' . preg_replace('/[^0-9-]/', '', $month) . '.jsonl.gz';
                    $gz = @gzopen($file, 'ab6');
                    if ($gz === false) {
                        $result['error'] = 'Não foi possível escrever ' . basename($file) . '. Nada deste lote foi apagado.';
                        return $result;
                    }
                    $buf = '';
                    foreach ($list as $r) {
                        foreach (['request_payload', 'response_payload'] as $col) {
                            if (isset($r[$col]) && is_string($r[$col])) {
                                $dec = json_decode($r[$col], true);
                                $r[$col] = $dec ?? $r[$col];
                            }
                        }
                        $buf .= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                    }
                    $okWrite = gzwrite($gz, $buf) !== false;
                    gzclose($gz);
                    if (!$okWrite) {
                        $result['error'] = 'Falha ao escrever ' . basename($file) . '. Nada deste lote foi apagado.';
                        return $result;
                    }
                }
                $result['archived'] += count($rows);
            }

            $ids = array_map(static fn($r) => (int)$r['id'], $rows);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $del = $db->prepare("DELETE FROM integration_logs WHERE id IN ($ph)");
            $del->execute($ids);
            $result['deleted'] += $del->rowCount();

            if (count($rows) < 1000) {
                break;
            }
            if (microtime(true) - $start >= $maxSeconds) {
                $result['more'] = true;
                break;
            }
        }

        // Arquivos mais antigos que o prazo saem do servidor.
        if ($archive) {
            $months = max(1, (int)admin_setting_get('logs.integration_archive_months', '12'));
            $limit = date('Y-m', strtotime('-' . $months . ' months'));
            foreach ((array)glob($dir . '/*.jsonl.gz') as $f) {
                if (substr(basename((string)$f), 0, 7) < $limit) {
                    @unlink((string)$f);
                }
            }
        }
    } finally {
        try { $db->query("SELECT RELEASE_LOCK('multilife_intlog_maint')")->fetchAll(); } catch (Throwable $e) {}
    }
    return $result;
}

/**
 * Números para a tela de Logs Técnicos.
 *
 * @return array{rows:int, size_mb:float, oldest:?string, old_rows:int, files:array<int,array{name:string,size:int}>}
 */
function integration_logs_stats(): array
{
    $db = db();
    $out = ['rows' => 0, 'size_mb' => 0.0, 'oldest' => null, 'old_rows' => 0, 'files' => []];
    try {
        $t = $db->query("SELECT TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.tables
                         WHERE table_schema = DATABASE() AND table_name = 'integration_logs'")->fetch(PDO::FETCH_ASSOC);
        $out['rows'] = (int)($t['TABLE_ROWS'] ?? 0); // estimativa (COUNT(*) em tabela grande é lento)
        $out['size_mb'] = round(((int)($t['bytes'] ?? 0)) / 1048576, 1);
        $out['oldest'] = $db->query('SELECT MIN(created_at) FROM integration_logs')->fetchColumn() ?: null;
        $daysOk = max(1, (int)admin_setting_get('logs.integration_retention_days_success', '7'));
        $daysErr = max(1, (int)admin_setting_get('logs.integration_retention_days_error', '30'));
        $st = $db->prepare("SELECT COUNT(*) FROM (SELECT id FROM integration_logs
              WHERE (status = 'success' AND created_at < :c1) OR (status <> 'success' AND created_at < :c2) LIMIT 1000001) x");
        $st->execute(['c1' => date('Y-m-d H:i:s', time() - $daysOk * 86400), 'c2' => date('Y-m-d H:i:s', time() - $daysErr * 86400)]);
        $out['old_rows'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[INTEGRATION_LOG] stats: ' . $e->getMessage());
    }
    foreach ((array)glob(integration_logs_archive_dir() . '/*.jsonl.gz') as $f) {
        $out['files'][] = ['name' => basename((string)$f), 'size' => (int)@filesize((string)$f)];
    }
    usort($out['files'], static fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

function integration_job_enqueue(string $provider, string $action, $payload, ?string $nextRunAt = null): int
{
    $stmt = db()->prepare(
        'INSERT INTO integration_jobs (provider, action, status, payload, next_run_at) VALUES (:p, :a, \'pending\', :pl, :nra)'
    );

    $stmt->execute([
        'p' => $provider,
        'a' => $action,
        'pl' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        'nra' => $nextRunAt,
    ]);

    return (int)db()->lastInsertId();
}
