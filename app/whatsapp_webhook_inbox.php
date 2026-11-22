<?php

declare(strict_types=1);

/**
 * Fila de entrada do webhook da Evolution (chat_webhook_inbox).
 *
 * O webhook grava o payload cru (arquivo em disco + linha na tabela), responde
 * 200 na hora e só depois processa. Assim a Evolution nunca espera o
 * processamento (mídia grande, consultas, etc.).
 *
 *  - Payload: arquivo fora da pasta pública (ou storage/webhook_inbox, bloqueada
 *    para a web). Pode ter dezenas de MB por causa da mídia em base64, por isso
 *    não vai para o banco. O arquivo é apagado quando o processamento termina.
 *  - Idempotência: messages.upsert / send.message com o mesmo evento + WhatsApp +
 *    key.id chegam uma vez só (chave única dedupe_key). Repetição = ignorada.
 *  - Recuperação: o que ficou pendente (PHP caiu, tempo esgotado) é processado
 *    pelos próximos webhooks e pelo cron cron/chat_webhook_inbox.php.
 */

const WA_INBOX_MAX_ATTEMPTS = 5;
/** Linha "processing" há mais que isto = o PHP morreu no meio; pode tentar de novo. */
const WA_INBOX_STUCK_SECONDS = 600;
/** Pendentes mais novos que isto ainda estão com o request que os recebeu. */
const WA_INBOX_RETRY_AFTER_SECONDS = 30;
/** Eventos com deduplicação por key.id (os demais não têm id estável). */
const WA_INBOX_DEDUPE_EVENTS = ['messages.upsert', 'send.message'];

/** Payload sem arquivo ou com JSON quebrado: tentar de novo não adianta. */
final class WaInboxPayloadError extends Exception
{
}

function wa_inbox_ensure_table(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS chat_webhook_inbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event VARCHAR(60) NOT NULL DEFAULT '',
        instance_name VARCHAR(100) NULL,
        dedupe_key CHAR(40) NULL,
        message_ids VARCHAR(500) NULL,
        payload_file VARCHAR(255) NULL,
        payload_size INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        claimed_at DATETIME NULL,
        processed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_dedupe (dedupe_key),
        KEY idx_status (status, received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

/** Pasta dos payloads. Prefere fora da pasta pública do site; '' se nenhuma for gravável. */
function wa_inbox_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $candidates = [
        dirname(__DIR__, 2) . '/' . basename(dirname(__DIR__)) . '_webhook_inbox',
        dirname(__DIR__) . '/storage/webhook_inbox',
    ];
    foreach ($candidates as $i => $c) {
        // "@": com open_basedir, checar pasta fora do site gera aviso
        if (!@is_dir($c)) {
            @mkdir($c, 0700, true);
        }
        if (@is_dir($c) && @is_writable($c)) {
            if ($i === 1) {
                $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
                if (!is_file($c . '/.htaccess')) {
                    @file_put_contents($c . '/.htaccess', $deny);
                }
                if (!is_file($c . '/index.html')) {
                    @file_put_contents($c . '/index.html', '');
                }
            }
            return $dir = $c;
        }
    }
    return $dir = '';
}

/** Evento normalizado (mesma regra de wa_webhook_handle). */
function wa_inbox_event(array $payload): string
{
    return strtolower(str_replace('_', '.', (string)($payload['event'] ?? '')));
}

/** key.id das mensagens do payload, na ordem. */
function wa_inbox_message_ids(array $payload): array
{
    $ids = [];
    foreach (wa_wh_message_list($payload['data'] ?? []) as $msg) {
        $id = (string)($msg['key']['id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Grava o payload na fila.
 *
 * @return array{status:string,id:int} status: queued | duplicate | unavailable
 *         ("unavailable" = sem pasta/banco; quem chamou processa na hora)
 */
function wa_inbox_store(string $raw, array $payload): array
{
    $event = wa_inbox_event($payload);
    $instance = trim((string)($payload['instance'] ?? ($payload['instanceName'] ?? '')));
    $ids = wa_inbox_message_ids($payload);
    $dedupe = null;
    if ($ids && in_array($event, WA_INBOX_DEDUPE_EVENTS, true)) {
        $dedupe = sha1($event . '|' . $instance . '|' . implode(',', $ids));
    }

    $dir = wa_inbox_dir();
    if ($dir === '') {
        return ['status' => 'unavailable', 'id' => 0];
    }
    wa_inbox_ensure_table();

    // Repetição já conhecida: nem grava o arquivo
    if ($dedupe !== null) {
        $st = db()->prepare('SELECT id FROM chat_webhook_inbox WHERE dedupe_key = ? LIMIT 1');
        $st->execute([$dedupe]);
        $dupId = (int)($st->fetchColumn() ?: 0);
        if ($dupId > 0) {
            return ['status' => 'duplicate', 'id' => $dupId];
        }
    }

    // Arquivo antes da linha: se o PHP cair entre os dois, a Evolution reenvia e
    // não há linha "sem arquivo" bloqueando a repetição.
    $sub = date('Y-m-d');
    if (!@is_dir($dir . '/' . $sub)) {
        @mkdir($dir . '/' . $sub, 0700, true);
    }
    $rel = $sub . '/' . bin2hex(random_bytes(16)) . '.json';
    if (@file_put_contents($dir . '/' . $rel, $raw, LOCK_EX) === false) {
        return ['status' => 'unavailable', 'id' => 0];
    }

    try {
        db()->prepare("INSERT INTO chat_webhook_inbox (event, instance_name, dedupe_key, message_ids, payload_file, payload_size)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([
                mb_substr($event, 0, 60), $instance !== '' ? mb_substr($instance, 0, 100) : null, $dedupe,
                $ids ? mb_substr(implode(',', $ids), 0, 500) : null, $rel, strlen($raw),
            ]);
        return ['status' => 'queued', 'id' => (int)db()->lastInsertId()];
    } catch (PDOException $e) {
        @unlink($dir . '/' . $rel);
        // Mesma chave gravada por um request simultâneo
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['status' => 'duplicate', 'id' => 0];
        }
        throw $e;
    }
}

/**
 * Processa uma linha da fila. $payload: já decodificado pelo request que recebeu
 * (evita ler e decodificar o arquivo de novo).
 */
function wa_inbox_process(int $id, ?array $payload = null): bool
{
    wa_inbox_ensure_table();
    // Reserva atômica: só um processo pega a linha
    $claim = db()->prepare("UPDATE chat_webhook_inbox
                            SET status = 'processing', claimed_at = NOW(), attempts = attempts + 1
                            WHERE id = ? AND attempts < ?
                              AND (status = 'pending'
                                   OR (status = 'processing' AND claimed_at < (NOW() - INTERVAL ? SECOND)))");
    $claim->execute([$id, WA_INBOX_MAX_ATTEMPTS, WA_INBOX_STUCK_SECONDS]);
    if ($claim->rowCount() !== 1) {
        return false;
    }

    $st = db()->prepare('SELECT payload_file, attempts FROM chat_webhook_inbox WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $path = !empty($row['payload_file']) ? wa_inbox_dir() . '/' . $row['payload_file'] : '';

    try {
        if ($payload === null) {
            $raw = $path !== '' ? @file_get_contents($path) : false;
            if ($raw === false) {
                throw new WaInboxPayloadError('arquivo do payload não encontrado');
            }
            $payload = json_decode($raw, true);
            unset($raw);
            if (!is_array($payload)) {
                throw new WaInboxPayloadError('payload inválido (JSON)');
            }
        }

        wa_webhook_handle($payload);

        db()->prepare("UPDATE chat_webhook_inbox
                       SET status = 'done', processed_at = NOW(), payload_file = NULL, last_error = NULL
                       WHERE id = ?")->execute([$id]);
        if ($path !== '') {
            @unlink($path);
        }
        return true;
    } catch (Throwable $e) {
        $final = (int)($row['attempts'] ?? 0) >= WA_INBOX_MAX_ATTEMPTS
            || $e instanceof WaInboxPayloadError;
        error_log('[WEBHOOK] fila #' . $id . ' erro: ' . $e->getMessage());
        db()->prepare("UPDATE chat_webhook_inbox SET status = ?, last_error = ? WHERE id = ?")
            ->execute([$final ? 'failed' : 'pending', mb_substr($e->getMessage(), 0, 500), $id]);
        return false;
    }
}

/**
 * Processa o que ficou para trás (pendentes antigos e "processing" travados).
 *
 * @return array{processed:int,failed:int}
 */
function wa_inbox_process_pending(int $limit = 50, int $timeBudgetSeconds = 50): array
{
    wa_inbox_ensure_table();
    $out = ['processed' => 0, 'failed' => 0];
    $deadline = time() + max(1, $timeBudgetSeconds);

    // Travados que já esgotaram as tentativas: falha definitiva
    db()->prepare("UPDATE chat_webhook_inbox SET status = 'failed', last_error = COALESCE(last_error, 'tempo esgotado no processamento')
                   WHERE status = 'processing' AND attempts >= ? AND claimed_at < (NOW() - INTERVAL ? SECOND)")
        ->execute([WA_INBOX_MAX_ATTEMPTS, WA_INBOX_STUCK_SECONDS]);

    $st = db()->prepare("SELECT id FROM chat_webhook_inbox
                         WHERE attempts < ?
                           AND ((status = 'pending' AND received_at < (NOW() - INTERVAL ? SECOND))
                                OR (status = 'processing' AND claimed_at < (NOW() - INTERVAL ? SECOND)))
                         ORDER BY id ASC LIMIT " . max(1, $limit));
    $st->execute([WA_INBOX_MAX_ATTEMPTS, WA_INBOX_RETRY_AFTER_SECONDS, WA_INBOX_STUCK_SECONDS]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (time() >= $deadline) {
            break;
        }
        if (wa_inbox_process((int)$id)) {
            $out['processed']++;
        } else {
            $out['failed']++;
        }
    }
    return $out;
}

/**
 * Limpeza: linhas concluídas (a chave de deduplicação vale por $doneDays dias)
 * e falhas antigas com o arquivo junto.
 */
function wa_inbox_cleanup(int $doneDays = 7, int $failedDays = 30): int
{
    wa_inbox_ensure_table();
    $dir = wa_inbox_dir();
    $st = db()->prepare("SELECT id, payload_file FROM chat_webhook_inbox
                         WHERE status = 'failed' AND received_at < (NOW() - INTERVAL ? DAY) LIMIT 1000");
    $st->execute([$failedDays]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($dir !== '' && !empty($r['payload_file'])) {
            @unlink($dir . '/' . $r['payload_file']);
        }
    }
    $del = db()->prepare("DELETE FROM chat_webhook_inbox
                          WHERE (status = 'done' AND received_at < (NOW() - INTERVAL ? DAY))
                             OR (status = 'failed' AND received_at < (NOW() - INTERVAL ? DAY))
                          LIMIT 5000");
    $del->execute([$doneDays, $failedDays]);
    $n = $del->rowCount();

    // Pastas de dias antigos que ficaram vazias
    if ($dir !== '') {
        foreach ((array)@glob($dir . '/20*', GLOB_ONLYDIR) as $d) {
            if (is_string($d) && basename($d) < date('Y-m-d', strtotime('-2 days'))) {
                @rmdir($d); // só remove se estiver vazia
            }
        }
    }
    return $n;
}

/** Contagem por status (tela de diagnóstico). */
function wa_inbox_stats(): array
{
    wa_inbox_ensure_table();
    $out = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
    foreach (db()->query("SELECT status, COUNT(*) AS n FROM chat_webhook_inbox
                          WHERE received_at >= (NOW() - INTERVAL 1 DAY) GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['status']] = (int)$r['n'];
    }
    return $out;
}
