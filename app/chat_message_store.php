<?php

declare(strict_types=1);

/**
 * Gravação de mensagens do chat (chat_messages) sem duplicar e resolução de LID.
 *
 * Duplicação: a mesma mensagem pode chegar por vários caminhos ao mesmo tempo
 * (resposta do envio pela API, webhook messages.upsert, webhook send.message,
 * webhook entregue duas vezes). Todos usam o id da mensagem no WhatsApp (key.id,
 * gravado em external_message_id) com um lock no MySQL para que só um grave.
 *
 * LID: o WhatsApp pode identificar o contato por um código "XXXX@lid" em vez do
 * número. Guardamos o par LID -> número para gravar tudo na conversa certa.
 */

/** Lock por mensagem (GET_LOCK do MySQL). Retorna true se conseguiu. */
function chat_msg_lock(string $externalId, int $timeoutSeconds = 5): bool
{
    if ($externalId === '') {
        return false;
    }
    try {
        $st = db()->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute(['cm_' . md5($externalId), $timeoutSeconds]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        error_log('[CHAT_STORE] GET_LOCK falhou: ' . $e->getMessage());
        return false;
    }
}

function chat_msg_unlock(string $externalId): void
{
    if ($externalId === '') {
        return;
    }
    try {
        $st = db()->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute(['cm_' . md5($externalId)]);
    } catch (Throwable $e) {
        // sem ação: o lock é liberado ao fechar a conexão
    }
}

/** Id da linha já gravada com esse key.id (0 se não existe). */
function chat_msg_find_by_external_id(string $externalId): int
{
    if ($externalId === '') {
        return 0;
    }
    $st = db()->prepare('SELECT id FROM chat_messages WHERE external_message_id = ? ORDER BY id ASC LIMIT 1');
    $st->execute([$externalId]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Mesmo formato usado no resto do chat: número@s.whatsapp.net ou id@g.us. */
function chat_store_normalize_jid(string $jid): string
{
    $numberOnly = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us|broadcast)$/', '', $jid);
    if (strpos($jid, '@g.us') !== false) {
        return $numberOnly . '@g.us';
    }
    return $numberOnly . '@s.whatsapp.net';
}

// ---------------------------------------------------------------------------
// Mapa LID -> número
// ---------------------------------------------------------------------------

function chat_lid_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS chat_lid_map (
        lid_jid VARCHAR(100) NOT NULL,
        pn_jid VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (lid_jid),
        INDEX idx_pn (pn_jid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function chat_lid_lookup(string $lidJid): string
{
    if (substr($lidJid, -4) !== '@lid') {
        return '';
    }
    try {
        chat_lid_ensure_table();
        $st = db()->prepare('SELECT pn_jid FROM chat_lid_map WHERE lid_jid = ? LIMIT 1');
        $st->execute([$lidJid]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('[CHAT_STORE] erro ao ler mapa LID: ' . $e->getMessage());
        return '';
    }
}

/** LIDs conhecidos de um número (para buscar o histórico na Evolution). */
function chat_lid_for_pn(string $pnJid): array
{
    try {
        chat_lid_ensure_table();
        $st = db()->prepare('SELECT lid_jid FROM chat_lid_map WHERE pn_jid = ?');
        $st->execute([chat_store_normalize_jid($pnJid)]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Guarda o par LID -> número. Quando o par é novo, move para a conversa certa
 * as mensagens que já tinham sido gravadas na conversa "fantasma" do LID
 * (LID@s.whatsapp.net), ex.: respostas enviadas pelo celular.
 */
function chat_lid_remember(string $lidJid, string $pnJid): void
{
    if (substr($lidJid, -4) !== '@lid' || strpos($pnJid, '@s.whatsapp.net') === false) {
        return;
    }
    $pnJid = chat_store_normalize_jid($pnJid);
    try {
        chat_lid_ensure_table();
        if (chat_lid_lookup($lidJid) === $pnJid) {
            return;
        }
        db()->prepare('INSERT INTO chat_lid_map (lid_jid, pn_jid) VALUES (?, ?) ON DUPLICATE KEY UPDATE pn_jid = VALUES(pn_jid)')
            ->execute([$lidJid, $pnJid]);

        $ghostJid = chat_store_normalize_jid($lidJid);
        if ($ghostJid === $pnJid) {
            return;
        }
        $moved = db()->prepare('UPDATE chat_messages SET remote_jid = ? WHERE remote_jid = ?');
        $moved->execute([$pnJid, $ghostJid]);
        try {
            db()->prepare('UPDATE chat_reactions SET remote_jid = ? WHERE remote_jid = ?')->execute([$pnJid, $ghostJid]);
        } catch (Throwable $e) { /* tabela pode não existir */ }

        $stPn = db()->prepare('SELECT COUNT(*) FROM chat_contacts WHERE remote_jid = ?');
        $stPn->execute([$pnJid]);
        if ((int)$stPn->fetchColumn() > 0) {
            db()->prepare('DELETE FROM chat_contacts WHERE remote_jid = ?')->execute([$ghostJid]);
        } else {
            db()->prepare('UPDATE chat_contacts SET remote_jid = ? WHERE remote_jid = ?')->execute([$pnJid, $ghostJid]);
        }
        if ($moved->rowCount() > 0) {
            error_log("[CHAT_STORE] LID $lidJid -> $pnJid: " . $moved->rowCount() . ' mensagem(ns) movida(s) para a conversa certa');
        }
    } catch (Throwable $e) {
        error_log('[CHAT_STORE] erro ao gravar mapa LID: ' . $e->getMessage());
    }
}

/**
 * Descobre o número de um LID pelo histórico da Evolution: as mensagens
 * RECEBIDAS desse contato trazem o número em senderPn/remoteJidAlt.
 */
function chat_lid_resolve_via_evolution(string $lidJid, string $instanceName): string
{
    if (substr($lidJid, -4) !== '@lid' || $instanceName === '') {
        return '';
    }
    try {
        $api = new EvolutionApiV1(
            (string)admin_setting_get('evolution.base_url'),
            (string)admin_setting_get('evolution.api_key'),
            $instanceName
        );
        $res = $api->findRecentMessages($lidJid, 50, 6);
        foreach (chat_evolution_records($res['json'] ?? null) as $rec) {
            $key = is_array($rec['key'] ?? null) ? $rec['key'] : [];
            if ((string)($key['remoteJid'] ?? '') !== $lidJid) {
                continue;
            }
            foreach (['remoteJidAlt', 'senderPn'] as $field) {
                $cand = (string)($key[$field] ?? '');
                if ($field === 'senderPn' && !empty($key['fromMe'])) {
                    continue; // em mensagem enviada, senderPn é o NOSSO número
                }
                if (strpos($cand, '@s.whatsapp.net') !== false) {
                    chat_lid_remember($lidJid, $cand);
                    return chat_store_normalize_jid($cand);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[CHAT_STORE] erro ao resolver LID na Evolution: ' . $e->getMessage());
    }
    return '';
}

/** Horário (unix) de um registro da Evolution (número, string ou {low,high}). */
function chat_evolution_record_ts(array $rec): int
{
    $raw = $rec['messageTimestamp'] ?? 0;
    if (is_array($raw)) {
        $raw = $raw['low'] ?? 0;
    }
    return is_numeric($raw) ? (int)$raw : 0;
}

/** Lista de mensagens da resposta do findMessages (formatos v1 e v2). */
function chat_evolution_records($json): array
{
    if (!is_array($json)) {
        return [];
    }
    $records = $json['messages']['records'] ?? ($json['records'] ?? (isset($json[0]) ? $json : []));
    return is_array($records) ? array_values(array_filter($records, 'is_array')) : [];
}

// ---------------------------------------------------------------------------
// Importação do histórico da Evolution (reserva quando o webhook não entrega)
// ---------------------------------------------------------------------------

/**
 * Grava uma mensagem vinda do findMessages da Evolution, se ainda não existir.
 * Usada pela sincronização da conversa aberta: garante que respostas enviadas
 * pelo celular apareçam mesmo que o webhook falhe ou chegue com LID.
 *
 * @return int id da linha criada (0 se já existia ou foi ignorada)
 */
function chat_import_evolution_record(array $rec, string $instanceName, string $conversationJid): int
{
    $key = is_array($rec['key'] ?? null) ? $rec['key'] : [];
    $extId = (string)($key['id'] ?? '');
    if ($extId === '') {
        return 0;
    }
    $msg = is_array($rec['message'] ?? null) ? $rec['message'] : [];
    // Mensagens embrulhadas (temporárias / enviadas de outro aparelho)
    foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'documentWithCaptionMessage'] as $wrap) {
        if (isset($msg[$wrap]['message']) && is_array($msg[$wrap]['message'])) {
            $msg = $msg[$wrap]['message'];
        }
    }
    if (isset($msg['deviceSentMessage']['message']) && is_array($msg['deviceSentMessage']['message'])) {
        $msg = $msg['deviceSentMessage']['message'];
    }
    foreach (['protocolMessage', 'reactionMessage', 'senderKeyDistributionMessage', 'pollUpdateMessage', 'encReactionMessage'] as $sys) {
        if (isset($msg[$sys]) && count($msg) <= 2) {
            return 0;
        }
    }

    $type = 'text';
    $text = (string)($msg['conversation'] ?? ($msg['extendedTextMessage']['text'] ?? ''));
    $map = [
        'imageMessage' => ['image', '[Imagem]'],
        'videoMessage' => ['video', '[Vídeo]'],
        'audioMessage' => ['audio', '[Áudio]'],
        'documentMessage' => ['document', '[Documento]'],
        'stickerMessage' => ['sticker', '[Sticker]'],
    ];
    $filename = null;
    foreach ($map as $field => [$t, $placeholder]) {
        if (isset($msg[$field]) && is_array($msg[$field])) {
            $type = $t;
            $text = (string)($msg[$field]['caption'] ?? '') ?: $placeholder;
            $filename = $msg[$field]['fileName'] ?? null;
            break;
        }
    }
    if ($text === '') {
        return 0;
    }

    $fromMe = !empty($key['fromMe']) ? 1 : 0;
    $ts = chat_evolution_record_ts($rec);
    if ($ts <= 0) {
        $ts = time();
    }
    $jid = chat_store_normalize_jid($conversationJid);

    $locked = chat_msg_lock($extId);
    try {
        if (chat_msg_find_by_external_id($extId) > 0) {
            return 0;
        }
        // Eco de mensagem do sistema gravada sem key.id (linhas antigas, follow-ups,
        // mídia): só associa o id. Janela larga porque a linha do sistema usa o
        // horário do servidor e pode ter sido gravada bem antes desta sincronização.
        if ($fromMe) {
            if ($type === 'text') {
                $stEcho = db()->prepare("SELECT id FROM chat_messages WHERE remote_jid = ? AND from_me = 1 AND message_text = ?
                                         AND external_message_id IS NULL AND message_timestamp BETWEEN ? AND ? ORDER BY id ASC LIMIT 1");
                $stEcho->execute([$jid, $text, $ts - 86400, $ts + 86400]);
            } else {
                $stEcho = db()->prepare("SELECT id FROM chat_messages WHERE remote_jid = ? AND from_me = 1 AND message_type = ?
                                         AND external_message_id IS NULL AND message_timestamp BETWEEN ? AND ? ORDER BY id ASC LIMIT 1");
                $stEcho->execute([$jid, $type, $ts - 600, $ts + 600]);
            }
            $echoId = (int)($stEcho->fetchColumn() ?: 0);
            if ($echoId > 0) {
                db()->prepare('UPDATE chat_messages SET external_message_id = ? WHERE id = ?')->execute([$extId, $echoId]);
                return 0;
            }
        }
        $senderName = (!$fromMe && !empty($rec['pushName'])) ? (string)$rec['pushName'] : null;
        db()->prepare("INSERT INTO chat_messages
                (remote_jid, instance_name, message_text, message_type, media_filename, from_me, message_timestamp, sender_name, external_message_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$jid, $instanceName !== '' ? $instanceName : null, $text, $type, $filename, $fromMe, $ts, $senderName, $extId]);
        $newId = (int)db()->lastInsertId();

        // Conversa sobe na lista (também quando a mensagem foi ENVIADA)
        db()->prepare("UPDATE chat_contacts
                       SET last_message_timestamp = ?, last_message_text = ?, last_message_type = ?, updated_at = CURRENT_TIMESTAMP
                       WHERE remote_jid = ? AND (last_message_timestamp IS NULL OR last_message_timestamp <= ?)")
            ->execute([$ts, $text, $type, $jid, $ts]);
        return $newId;
    } finally {
        if ($locked) {
            chat_msg_unlock($extId);
        }
    }
}

// ---------------------------------------------------------------------------
// Rastreio do webhook (diagnóstico): o que chegou e o que foi feito com cada
// mensagem. Visível em /admin_webhook_debug.php. Guarda só os últimos 3 dias.
// ---------------------------------------------------------------------------

function chat_webhook_trace(string $event, array $key, string $result, string $resolvedJid = '', string $text = ''): void
{
    try {
        static $ready = false;
        if (!$ready) {
            db()->exec("CREATE TABLE IF NOT EXISTS chat_webhook_trace (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                event VARCHAR(40) NOT NULL,
                instance_name VARCHAR(100) DEFAULT NULL,
                from_me TINYINT(1) NOT NULL DEFAULT 0,
                remote_jid VARCHAR(100) DEFAULT NULL,
                remote_jid_alt VARCHAR(100) DEFAULT NULL,
                sender_pn VARCHAR(100) DEFAULT NULL,
                sender_lid VARCHAR(100) DEFAULT NULL,
                resolved_jid VARCHAR(100) DEFAULT NULL,
                external_id VARCHAR(100) DEFAULT NULL,
                result VARCHAR(120) NOT NULL,
                text_sample VARCHAR(120) DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready = true;
        }
        $cut = static fn($v, int $n) => $v === null || $v === '' ? null : mb_substr((string)$v, 0, $n);
        db()->prepare('INSERT INTO chat_webhook_trace
                (event, instance_name, from_me, remote_jid, remote_jid_alt, sender_pn, sender_lid, resolved_jid, external_id, result, text_sample)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $cut($event, 40),
                $cut($GLOBALS['_webhookInstanceName'] ?? null, 100),
                !empty($key['fromMe']) ? 1 : 0,
                $cut($key['remoteJid'] ?? null, 100),
                $cut($key['remoteJidAlt'] ?? null, 100),
                $cut($key['senderPn'] ?? null, 100),
                $cut($key['senderLid'] ?? null, 100),
                $cut($resolvedJid, 100),
                $cut($key['id'] ?? null, 100),
                $cut($result, 120),
                $cut($text, 120),
            ]);
        if (random_int(1, 200) === 1) {
            db()->exec('DELETE FROM chat_webhook_trace WHERE created_at < (NOW() - INTERVAL 3 DAY)');
        }
    } catch (Throwable $e) {
        // diagnóstico nunca pode atrapalhar o webhook
    }
}
