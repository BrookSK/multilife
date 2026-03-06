<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$chatId = trim($_GET['chat_id'] ?? '');
$since  = (int)($_GET['since'] ?? 0); // timestamp UNIX - retorna apenas msgs após este momento

if (empty($chatId)) {
    echo json_encode(['error' => 'chat_id obrigatório']);
    exit;
}

try {
    $tableExists = db()->query("SHOW TABLES LIKE 'chat_messages'")->fetch();
    if (!$tableExists) {
        echo json_encode(['messages' => [], 'count' => 0, 'last_timestamp' => 0]);
        exit;
    }

    // Buscar mensagens novas desde o último timestamp
    $stmt = db()->prepare("
        SELECT id, remote_jid, message_text, from_me, message_timestamp
        FROM chat_messages
        WHERE remote_jid = ?
          AND message_timestamp > ?
        ORDER BY message_timestamp ASC, id ASC
        LIMIT 50
    ");
    $stmt->execute([$chatId, $since]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lastTimestamp = $since;
    if (!empty($messages)) {
        $lastTimestamp = (int)end($messages)['message_timestamp'];
    }

    echo json_encode([
        'messages'       => $messages,
        'count'          => count($messages),
        'last_timestamp' => $lastTimestamp,
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'messages' => [], 'count' => 0]);
}
exit;
