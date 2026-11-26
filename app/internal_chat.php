<?php

declare(strict_types=1);

/**
 * CHAT INTERNO — conversas entre os usuários da equipe, sem WhatsApp nem API
 * externa. Tudo fica no banco do sistema.
 *
 *  - conversa direta (2 pessoas) ou grupo (nome + participantes; quem cria é
 *    administrador e pode renomear, adicionar e remover);
 *  - texto, arquivos e imagens (guardados em storage/internal_chat, fora do
 *    acesso direto pela web; download só para quem está na conversa);
 *  - resposta a uma mensagem, editar e apagar a própria mensagem (a linha fica
 *    guardada, aparece "mensagem apagada");
 *  - lidas / não lidas por pessoa (contador no menu).
 *
 * Tela: /internal_chat.php · API: /internal_chat_api.php
 */

const INTERNAL_CHAT_MAX_FILE_MB = 25;

function ichat_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if ((string)admin_setting_get('internal_chat.schema_version', '') === '1') {
            return;
        }
    } catch (Throwable $e) {}
    $sql = [
        "CREATE TABLE IF NOT EXISTS internal_chat_conversations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(10) NOT NULL DEFAULT 'direct',
            title VARCHAR(120) NULL,
            direct_key VARCHAR(40) NULL,
            created_by INT UNSIGNED NULL,
            last_message_id BIGINT UNSIGNED NULL,
            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_ic_direct (direct_key),
            KEY idx_ic_last (last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS internal_chat_members (
            conversation_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'member',
            last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            left_at DATETIME NULL,
            PRIMARY KEY (conversation_id, user_id),
            KEY idx_icm_user (user_id, left_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS internal_chat_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'text',
            body TEXT NULL,
            file_path VARCHAR(255) NULL,
            file_name VARCHAR(255) NULL,
            file_mime VARCHAR(120) NULL,
            file_size INT UNSIGNED NULL,
            reply_to_id BIGINT UNSIGNED NULL,
            edited_at DATETIME NULL,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_icmsg_conv (conversation_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($sql as $s) {
        db()->exec($s);
    }
    try {
        db()->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('internal_chat.schema_version', '1')
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute();
    } catch (Throwable $e) {}
}

/**
 * Usuário da equipe? (tem algum papel que não seja só profissional/paciente).
 * Mesma regra do menu completo em app/view.php.
 */
function ichat_user_allowed(int $userId): bool
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    if ($userId <= 0) {
        return $cache[$userId] = false;
    }
    try {
        $roles = function_exists('rbac_user_roles') ? array_map(static fn($r) => is_array($r) ? (string)($r['slug'] ?? '') : (string)$r, rbac_user_roles($userId)) : [];
        if (!$roles) {
            $st = db()->prepare('SELECT r.slug FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?');
            $st->execute([$userId]);
            $roles = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        foreach ($roles as $r) {
            if ($r !== '' && $r !== 'profissional' && $r !== 'paciente') {
                return $cache[$userId] = true;
            }
        }
    } catch (Throwable $e) {}
    return $cache[$userId] = false;
}

/** Usuários da equipe que podem conversar (ativos, com papel administrativo). */
function ichat_staff_users(): array
{
    $rows = db()->query("SELECT DISTINCT u.id, u.name, u.email
                         FROM users u
                         INNER JOIN user_roles ur ON ur.user_id = u.id
                         INNER JOIN roles r ON r.id = ur.role_id
                         WHERE u.status = 'active' AND r.slug NOT IN ('profissional', 'paciente')
                           AND u.email NOT LIKE '%@importacao.multilife.local'
                         ORDER BY u.name ASC")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'email' => (string)$r['email']], $rows);
}

/** Linha de participação ativa (ou null se a pessoa não está na conversa). */
function ichat_membership(int $conversationId, int $userId): ?array
{
    $st = db()->prepare('SELECT * FROM internal_chat_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL');
    $st->execute([$conversationId, $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function ichat_conversation(int $conversationId): ?array
{
    $st = db()->prepare('SELECT * FROM internal_chat_conversations WHERE id = ?');
    $st->execute([$conversationId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Participantes ativos com nome. */
function ichat_members(int $conversationId): array
{
    $st = db()->prepare('SELECT m.user_id, m.role, m.last_read_id, u.name, u.email FROM internal_chat_members m
                         INNER JOIN users u ON u.id = m.user_id
                         WHERE m.conversation_id = ? AND m.left_at IS NULL ORDER BY (m.role = \'admin\') DESC, u.name');
    $st->execute([$conversationId]);
    return array_map(static fn($r) => ['id' => (int)$r['user_id'], 'name' => (string)$r['name'], 'email' => (string)$r['email'],
        'role' => (string)$r['role'], 'last_read_id' => (int)$r['last_read_id']], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Conversa direta entre duas pessoas (cria se não existe). */
function ichat_direct(int $userA, int $userB): int
{
    if ($userA === $userB) {
        throw new RuntimeException('Escolha outra pessoa.');
    }
    $key = min($userA, $userB) . ':' . max($userA, $userB);
    $st = db()->prepare('SELECT id FROM internal_chat_conversations WHERE direct_key = ?');
    $st->execute([$key]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) {
        // volta a participar se tinha saído
        db()->prepare('UPDATE internal_chat_members SET left_at = NULL WHERE conversation_id = ? AND user_id IN (?, ?)')->execute([$id, $userA, $userB]);
        return $id;
    }
    db()->prepare("INSERT INTO internal_chat_conversations (type, direct_key, created_by) VALUES ('direct', ?, ?)")->execute([$key, $userA]);
    $id = (int)db()->lastInsertId();
    $ins = db()->prepare("INSERT INTO internal_chat_members (conversation_id, user_id, role) VALUES (?, ?, 'member')");
    $ins->execute([$id, $userA]);
    $ins->execute([$id, $userB]);
    return $id;
}

/** Grupo novo; quem cria é administrador. */
function ichat_create_group(int $creatorId, string $title, array $memberIds): int
{
    $title = trim(mb_substr($title, 0, 120));
    if ($title === '') {
        throw new RuntimeException('Dê um nome ao grupo.');
    }
    $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn($v) => $v > 0 && $v !== $creatorId)));
    if (!$memberIds) {
        throw new RuntimeException('Escolha pelo menos uma pessoa para o grupo.');
    }
    db()->prepare("INSERT INTO internal_chat_conversations (type, title, created_by) VALUES ('group', ?, ?)")->execute([$title, $creatorId]);
    $id = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO internal_chat_members (conversation_id, user_id, role) VALUES (?, ?, 'admin')")->execute([$id, $creatorId]);
    ichat_add_members($id, $memberIds, $creatorId, false);
    ichat_system_message($id, ichat_user_name($creatorId) . ' criou o grupo "' . $title . '"');
    return $id;
}

function ichat_user_name(int $userId): string
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $st = db()->prepare('SELECT name FROM users WHERE id = ?');
        $st->execute([$userId]);
        $cache[$userId] = (string)($st->fetchColumn() ?: 'Usuário');
    }
    return $cache[$userId];
}

/** @return string[] nomes adicionados */
function ichat_add_members(int $conversationId, array $userIds, int $byUserId, bool $announce = true): array
{
    $added = [];
    $ins = db()->prepare("INSERT INTO internal_chat_members (conversation_id, user_id, role) VALUES (?, ?, 'member')
                          ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = IF(left_at IS NULL, joined_at, NOW())");
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid <= 0 || !ichat_user_allowed($uid)) {
            continue;
        }
        $was = ichat_membership($conversationId, $uid);
        if ($was) {
            continue;
        }
        $ins->execute([$conversationId, $uid]);
        $added[] = ichat_user_name($uid);
    }
    if ($announce && $added) {
        ichat_system_message($conversationId, ichat_user_name($byUserId) . ' adicionou ' . implode(', ', $added));
    }
    return $added;
}

function ichat_system_message(int $conversationId, string $text): int
{
    db()->prepare("INSERT INTO internal_chat_messages (conversation_id, user_id, kind, body) VALUES (?, NULL, 'system', ?)")->execute([$conversationId, $text]);
    $id = (int)db()->lastInsertId();
    db()->prepare('UPDATE internal_chat_conversations SET last_message_id = ?, last_message_at = NOW() WHERE id = ?')->execute([$id, $conversationId]);
    return $id;
}

/** Grava uma mensagem e marca como lida para quem enviou. */
function ichat_send(int $conversationId, int $userId, string $body, ?int $replyTo = null, ?array $file = null): int
{
    $body = rtrim(str_replace("\r\n", "\n", $body));
    if (mb_strlen($body) > 8000) {
        $body = mb_substr($body, 0, 8000);
    }
    if ($body === '' && $file === null) {
        throw new RuntimeException('Mensagem vazia.');
    }
    if ($replyTo !== null && $replyTo > 0) {
        $st = db()->prepare('SELECT 1 FROM internal_chat_messages WHERE id = ? AND conversation_id = ?');
        $st->execute([$replyTo, $conversationId]);
        if (!$st->fetchColumn()) {
            $replyTo = null;
        }
    }
    $kind = $file === null ? 'text' : (str_starts_with((string)$file['mime'], 'image/') ? 'image' : 'file');
    db()->prepare('INSERT INTO internal_chat_messages (conversation_id, user_id, kind, body, file_path, file_name, file_mime, file_size, reply_to_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$conversationId, $userId, $kind, $body !== '' ? $body : null, $file['path'] ?? null, $file['name'] ?? null, $file['mime'] ?? null,
            $file['size'] ?? null, $replyTo ?: null]);
    $id = (int)db()->lastInsertId();
    db()->prepare('UPDATE internal_chat_conversations SET last_message_id = ?, last_message_at = NOW() WHERE id = ?')->execute([$id, $conversationId]);
    db()->prepare('UPDATE internal_chat_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conversation_id = ? AND user_id = ?')->execute([$id, $conversationId, $userId]);
    return $id;
}

/** Guarda um arquivo enviado ($_FILES) em storage/internal_chat. */
function ichat_store_upload(array $f): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        throw new RuntimeException('Arquivo não recebido (tamanho máximo do servidor: ' . ini_get('upload_max_filesize') . ').');
    }
    $size = (int)$f['size'];
    if ($size <= 0 || $size > INTERNAL_CHAT_MAX_FILE_MB * 1048576) {
        throw new RuntimeException('O arquivo passa de ' . INTERNAL_CHAT_MAX_FILE_MB . ' MB.');
    }
    $name = trim((string)preg_replace('/[\x00-\x1F\/\\\\]+/', '_', (string)$f['name']));
    $name = $name !== '' ? mb_substr($name, 0, 180) : 'arquivo';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'htaccess', 'exe', 'bat', 'cmd', 'sh', 'js', 'html', 'htm', 'svg'], true)) {
        throw new RuntimeException('Este tipo de arquivo não pode ser enviado pelo chat.');
    }
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)(finfo_file($fi, (string)$f['tmp_name']) ?: $mime);
    }
    $dirRel = 'storage/internal_chat/' . date('Y-m');
    $dir = dirname(__DIR__) . '/' . $dirRel;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Sem permissão para gravar o arquivo.');
    }
    $root = dirname(__DIR__) . '/storage/internal_chat';
    if (!is_file($root . '/.htaccess')) {
        @file_put_contents($root . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    $stored = bin2hex(random_bytes(16)) . ($ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext) ? '.' . $ext : '');
    if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Não foi possível gravar o arquivo.');
    }
    return ['path' => $dirRel . '/' . $stored, 'name' => $name, 'mime' => $mime, 'size' => $size];
}

/** Mensagem no formato da tela. */
function ichat_message_view(array $m, int $viewerId): array
{
    $deleted = !empty($m['deleted_at']);
    return [
        'id' => (int)$m['id'],
        'conversation_id' => (int)$m['conversation_id'],
        'user_id' => $m['user_id'] !== null ? (int)$m['user_id'] : null,
        'user_name' => $m['user_id'] !== null ? (string)($m['user_name'] ?? ichat_user_name((int)$m['user_id'])) : '',
        'mine' => $m['user_id'] !== null && (int)$m['user_id'] === $viewerId,
        'kind' => (string)$m['kind'],
        'body' => $deleted ? '' : (string)($m['body'] ?? ''),
        'file' => (!$deleted && !empty($m['file_path'])) ? ['url' => '/internal_chat_file.php?id=' . (int)$m['id'], 'name' => (string)$m['file_name'],
            'mime' => (string)$m['file_mime'], 'size' => (int)$m['file_size']] : null,
        'reply_to' => !empty($m['reply_to_id']) ? ['id' => (int)$m['reply_to_id'], 'user_name' => (string)($m['reply_user_name'] ?? ''),
            'body' => !empty($m['reply_deleted_at']) ? 'mensagem apagada' : mb_strimwidth((string)($m['reply_body'] ?? ($m['reply_file_name'] ?? '')), 0, 140, '…')] : null,
        'edited' => !empty($m['edited_at']),
        'deleted' => $deleted,
        'created_at' => (string)$m['created_at'],
        'ts' => strtotime((string)$m['created_at']) ?: time(),
    ];
}

/** Mensagens de uma conversa (mais antigas primeiro). */
function ichat_messages(int $conversationId, int $viewerId, int $afterId = 0, int $beforeId = 0, int $limit = 60): array
{
    $where = 'm.conversation_id = ?';
    $params = [$conversationId];
    if ($afterId > 0) {
        $where .= ' AND m.id > ?';
        $params[] = $afterId;
    }
    if ($beforeId > 0) {
        $where .= ' AND m.id < ?';
        $params[] = $beforeId;
    }
    $order = $afterId > 0 ? 'ASC' : 'DESC';
    $st = db()->prepare("SELECT m.*, u.name AS user_name, r.body AS reply_body, r.file_name AS reply_file_name, r.deleted_at AS reply_deleted_at, ru.name AS reply_user_name
                         FROM internal_chat_messages m
                         LEFT JOIN users u ON u.id = m.user_id
                         LEFT JOIN internal_chat_messages r ON r.id = m.reply_to_id
                         LEFT JOIN users ru ON ru.id = r.user_id
                         WHERE $where ORDER BY m.id $order LIMIT " . max(1, min(200, $limit)));
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($order === 'DESC') {
        $rows = array_reverse($rows);
    }
    return array_map(static fn($r) => ichat_message_view($r, $viewerId), $rows);
}

/** Conversas da pessoa, com prévia da última mensagem e não lidas. */
function ichat_conversations(int $userId): array
{
    $st = db()->prepare("SELECT c.*, me.last_read_id, me.role AS my_role,
                            lm.body AS last_body, lm.kind AS last_kind, lm.file_name AS last_file, lm.deleted_at AS last_deleted, lm.user_id AS last_user_id, lu.name AS last_user_name,
                            (SELECT COUNT(*) FROM internal_chat_messages x WHERE x.conversation_id = c.id AND x.id > me.last_read_id AND (x.user_id IS NULL OR x.user_id <> ?) AND x.kind <> 'system') AS unread
                         FROM internal_chat_members me
                         INNER JOIN internal_chat_conversations c ON c.id = me.conversation_id
                         LEFT JOIN internal_chat_messages lm ON lm.id = c.last_message_id
                         LEFT JOIN users lu ON lu.id = lm.user_id
                         WHERE me.user_id = ? AND me.left_at IS NULL
                         ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
                         LIMIT 300");
    $st->execute([$userId, $userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // Nome da conversa direta = a outra pessoa
    $directIds = [];
    foreach ($rows as $r) {
        if ($r['type'] === 'direct') {
            $directIds[] = (int)$r['id'];
        }
    }
    $other = [];
    if ($directIds) {
        $ph = implode(',', array_fill(0, count($directIds), '?'));
        $st2 = db()->prepare("SELECT m.conversation_id, u.id, u.name FROM internal_chat_members m INNER JOIN users u ON u.id = m.user_id
                              WHERE m.conversation_id IN ($ph) AND m.user_id <> ?");
        $st2->execute(array_merge($directIds, [$userId]));
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $other[(int)$o['conversation_id']] = ['id' => (int)$o['id'], 'name' => (string)$o['name']];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $isDirect = $r['type'] === 'direct';
        $preview = '';
        if (!empty($r['last_deleted'])) {
            $preview = 'mensagem apagada';
        } elseif ($r['last_kind'] === 'image') {
            $preview = '📷 ' . ((string)$r['last_body'] !== '' ? (string)$r['last_body'] : 'Imagem');
        } elseif ($r['last_kind'] === 'file') {
            $preview = '📎 ' . (string)$r['last_file'];
        } else {
            $preview = (string)$r['last_body'];
        }
        if ($r['last_user_id'] !== null && !$isDirect) {
            $preview = ((int)$r['last_user_id'] === $userId ? 'Você' : explode(' ', (string)$r['last_user_name'])[0]) . ': ' . $preview;
        } elseif ($r['last_user_id'] !== null && (int)$r['last_user_id'] === $userId) {
            $preview = 'Você: ' . $preview;
        }
        $out[] = [
            'id' => $id,
            'type' => (string)$r['type'],
            'title' => $isDirect ? (string)($other[$id]['name'] ?? 'Conversa') : (string)$r['title'],
            'other_user_id' => $isDirect ? ($other[$id]['id'] ?? null) : null,
            'preview' => mb_strimwidth((string)preg_replace('/\s+/', ' ', $preview), 0, 90, '…'),
            'last_at' => (string)($r['last_message_at'] ?? $r['created_at']),
            'last_id' => (int)($r['last_message_id'] ?? 0),
            'unread' => (int)$r['unread'],
            'my_role' => (string)$r['my_role'],
        ];
    }
    return $out;
}

/** Total de mensagens não lidas da pessoa (contador do menu). */
function ichat_unread_total(int $userId): int
{
    try {
        ichat_ensure_schema();
        $st = db()->prepare("SELECT COUNT(*) FROM internal_chat_members me
                             INNER JOIN internal_chat_messages x ON x.conversation_id = me.conversation_id AND x.id > me.last_read_id
                             WHERE me.user_id = ? AND me.left_at IS NULL AND (x.user_id IS NULL OR x.user_id <> ?) AND x.kind <> 'system'");
        $st->execute([$userId, $userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ichat_mark_read(int $conversationId, int $userId, int $lastId): void
{
    db()->prepare('UPDATE internal_chat_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conversation_id = ? AND user_id = ?')
        ->execute([$lastId, $conversationId, $userId]);
}
