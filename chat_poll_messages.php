<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');
session_release(); // polling: não pode travar as outras telas do usuário

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$chatId = trim($_GET['chat_id'] ?? '');
$since  = (int)($_GET['since'] ?? 0);
// Cursor adicional por id: resolve o caso de VÁRIAS mensagens no MESMO segundo
// (ex.: vários arquivos enviados de uma vez). Sem ele, mensagens com o mesmo
// message_timestamp já "consumido" nunca mais apareciam no polling incremental.
$lastId = (int)($_GET['last_id'] ?? 0);

if (empty($chatId)) {
    echo json_encode(['messages' => [], 'last_timestamp' => $since, 'last_id' => $lastId]);
    exit;
}

try {
    // Buscar mensagens novas desde o último cursor (timestamp + id).
    // Condição: timestamp estritamente maior, OU mesmo timestamp porém id maior.
    $stmt = db()->prepare("
        SELECT 
            id,
            remote_jid,
            message_text,
            from_me,
            message_timestamp,
            message_type,
            media_url,
            media_mime_type,
            media_filename,
            media_size,
            audio_transcription,
            quoted_message_id,
            quoted_message_text,
            quoted_message_sender,
            mentioned_jids,
            sender_name,
            participant_jid,
            external_message_id
        FROM chat_messages
        WHERE remote_jid = ?
          AND (
                message_timestamp > ?
                OR (message_timestamp = ? AND id > ?)
          )
        ORDER BY message_timestamp ASC, id ASC
        LIMIT 50
    ");
    $stmt->execute([$chatId, $since, $since, $lastId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Converter URLs relativas para absolutas
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    foreach ($messages as &$msg) {
        if (!empty($msg['media_url']) && $msg['media_url'][0] === '/' && strpos($msg['media_url'], 'http') !== 0) {
            $msg['media_url'] = $protocol . '://' . $host . $msg['media_url'];
        }
    }
    unset($msg);

    // Buscar reações para as mensagens retornadas
    $externalIds = array_filter(array_column($messages, 'external_message_id'));
    $reactions = [];
    if (!empty($externalIds)) {
        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        try {
            $stmtReact = db()->prepare("SELECT message_id, reactor_jid, emoji FROM chat_reactions WHERE message_id IN ($placeholders)");
            $stmtReact->execute(array_values($externalIds));
            $rawReactions = $stmtReact->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawReactions as $r) {
                $reactions[$r['message_id']][] = ['reactor' => $r['reactor_jid'], 'emoji' => $r['emoji']];
            }
        } catch (Exception $e) {
            // Tabela pode não existir ainda
        }
    }

    // Anexar reações às mensagens
    foreach ($messages as &$msg) {
        $msg['reactions'] = [];
        if (!empty($msg['external_message_id']) && isset($reactions[$msg['external_message_id']])) {
            $msg['reactions'] = $reactions[$msg['external_message_id']];
        }
    }
    unset($msg);

    // Avança os cursores com base na ÚLTIMA mensagem do lote (ordenado por ts, id).
    $lastTimestamp = $since;
    $lastIdOut = $lastId;
    if (!empty($messages)) {
        $lastMsg = end($messages);
        $lastTimestamp = (int)$lastMsg['message_timestamp'];
        $lastIdOut = (int)$lastMsg['id'];
    }

    echo json_encode([
        'messages'       => $messages,
        'count'          => count($messages),
        'last_timestamp' => $lastTimestamp,
        'last_id'        => $lastIdOut,
    ]);
} catch (Exception $e) {
    error_log('Erro no chat_poll_messages: ' . $e->getMessage());
    echo json_encode(['messages' => [], 'last_timestamp' => $since, 'last_id' => $lastId, 'error' => $e->getMessage()]);
}
