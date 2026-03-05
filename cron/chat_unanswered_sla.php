<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$minutes = (int)admin_setting_get('chat.unanswered_timeout_minutes', '60');
if ($minutes <= 0) {
    $minutes = 60;
}

$cut = new DateTime('now');
$cut->modify('-' . $minutes . ' minutes');
$cutAt = $cut->format('Y-m-d H:i:s');

$db = db();

// Candidate conversations: open, last inbound message older than cutoff, and no outbound after it.
$stmt = $db->prepare(
    "SELECT c.id, c.external_phone, c.assigned_user_id,
            (SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id = c.id AND m.direction = 'in') AS last_in_id,
            (SELECT MAX(m.created_at) FROM chat_messages m WHERE m.conversation_id = c.id AND m.direction = 'in') AS last_in_at,
            (SELECT MAX(m.created_at) FROM chat_messages m WHERE m.conversation_id = c.id AND m.direction = 'out') AS last_out_at
     FROM chat_conversations c
     WHERE c.status = 'open'"
);
$stmt->execute();
$rows = $stmt->fetchAll();

if (count($rows) === 0) {
    echo "OK: no conversations\n";
    exit;
}

$exists = $db->prepare(
    "SELECT id FROM pending_items
     WHERE status = 'open' AND type = 'chat_unanswered'
       AND related_table = 'chat_conversations' AND related_id = :rid
     LIMIT 1"
);

$ins = $db->prepare(
    "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)
     VALUES ('chat_unanswered','open',:title,:detail,'chat_conversations',:rid,:uid)"
);

$created = 0;
foreach ($rows as $r) {
    if (!$r['last_in_at']) {
        continue;
    }

    $lastInAt = (string)$r['last_in_at'];
    if ($lastInAt > $cutAt) {
        continue;
    }

    $lastOutAt = $r['last_out_at'] ? (string)$r['last_out_at'] : null;
    if ($lastOutAt !== null && $lastOutAt >= $lastInAt) {
        continue;
    }

    $rid = (int)$r['id'];
    $exists->execute(['rid' => $rid]);
    if ($exists->fetch()) {
        continue;
    }

    $phone = (string)$r['external_phone'];
    $title = 'Chat sem resposta: ' . $phone;
    $detail = 'Última mensagem recebida em ' . $lastInAt . '. SLA: ' . $minutes . ' min.';

    $ins->execute([
        'title' => $title,
        'detail' => $detail,
        'rid' => $rid,
        'uid' => $r['assigned_user_id'] !== null ? (int)$r['assigned_user_id'] : null,
    ]);

    $created++;
}

echo 'OK: created=' . $created . "\n";
