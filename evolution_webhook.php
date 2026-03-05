<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Public endpoint: Evolution will call this.
// Optional shared secret validation (recommended)
$expected = (string)admin_setting_get('evolution.webhook_token', '');
$provided = '';

$hdr = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
if (is_string($hdr) && $hdr !== '') {
    $provided = $hdr;
} elseif (isset($_GET['token'])) {
    $provided = (string)$_GET['token'];
}

if ($expected !== '' && !hash_equals($expected, $provided)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw)) {
    $raw = '';
}

$payload = null;
try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $payload = null;
}

if (!is_array($payload)) {
    integration_log('evolution_webhook', 'inbound', 'error', 400, null, $raw, 'invalid_json', 1);
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// Helper: deep search for tokens like #CAP...
$tokenRegex = '/#CAP[0-9A-Z\-]{4,80}/';

$deepCollectStrings = function ($v, array &$out) use (&$deepCollectStrings): void {
    if (is_string($v)) {
        $out[] = $v;
        return;
    }
    if (is_array($v)) {
        foreach ($v as $vv) {
            $deepCollectStrings($vv, $out);
        }
        return;
    }
};

// Attempt to normalize common Evolution payload shapes
$data = $payload['data'] ?? $payload;
if (!is_array($data)) {
    $data = $payload;
}

$remoteJid = '';
$externalMessageId = '';
$senderRaw = '';
$body = '';
$receivedAt = null;

// Most common keys
if (isset($data['key']) && is_array($data['key'])) {
    $remoteJid = (string)($data['key']['remoteJid'] ?? '');
    $externalMessageId = (string)($data['key']['id'] ?? '');
    $senderRaw = (string)($data['key']['participant'] ?? '');
}

if ($remoteJid === '' && isset($data['remoteJid'])) {
    $remoteJid = (string)$data['remoteJid'];
}

if ($externalMessageId === '' && isset($data['messageId'])) {
    $externalMessageId = (string)$data['messageId'];
}

if ($senderRaw === '' && isset($data['from'])) {
    $senderRaw = (string)$data['from'];
}

if ($senderRaw === '' && isset($data['sender'])) {
    $senderRaw = is_string($data['sender']) ? (string)$data['sender'] : '';
}

// Body extraction
if (isset($data['message']) && is_array($data['message'])) {
    $m = $data['message'];
    $body = (string)($m['conversation'] ?? '');
    if ($body === '' && isset($m['extendedTextMessage']) && is_array($m['extendedTextMessage'])) {
        $body = (string)($m['extendedTextMessage']['text'] ?? '');
    }
}

if ($body === '' && isset($data['body'])) {
    $body = (string)$data['body'];
}

if ($body === '' && isset($data['text'])) {
    $body = (string)$data['text'];
}

if (isset($data['messageTimestamp'])) {
    $ts = $data['messageTimestamp'];
    if (is_numeric($ts)) {
        $receivedAt = date('Y-m-d H:i:s', (int)$ts);
    }
}

$groupJid = $remoteJid;
$isGroup = ($groupJid !== '' && str_contains($groupJid, '@g.us'));

if (!$isGroup) {
    // Ignore non-group events for this monitoring feature (private messages are handled elsewhere).
    integration_log('evolution_webhook', 'inbound_non_group', 'success', 200, null, $payload, null, 0);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$senderPhone = preg_replace('/\D+/', '', $senderRaw);
if ($senderPhone === '') {
    // Fallback: try any digits in payload
    $strings = [];
    $deepCollectStrings($payload, $strings);
    foreach ($strings as $s) {
        $d = preg_replace('/\D+/', '', $s);
        if ($d !== '' && mb_strlen($d) >= 10) {
            $senderPhone = $d;
            break;
        }
    }
}

if ($externalMessageId === '') {
    $externalMessageId = hash('sha256', $raw);
}

// Token detection in any nested string
$strings = [];
$deepCollectStrings($payload, $strings);
$foundToken = '';
foreach ($strings as $s) {
    if (preg_match($tokenRegex, $s, $m)) {
        $foundToken = (string)$m[0];
        break;
    }
}

$demandId = null;
$groupId = null;
if ($foundToken !== '') {
    $stmt = db()->prepare('SELECT demand_id, group_id FROM demand_dispatch_logs WHERE capture_token = :t ORDER BY id DESC LIMIT 1');
    $stmt->execute(['t' => $foundToken]);
    $row = $stmt->fetch();
    if ($row) {
        $demandId = (int)$row['demand_id'];
        $groupId = $row['group_id'] !== null ? (int)$row['group_id'] : null;
    }
}

// If groupId not found from token, try map by jid
if ($groupId === null) {
    $stmt = db()->prepare('SELECT id FROM whatsapp_groups WHERE evolution_group_jid = :jid LIMIT 1');
    $stmt->execute(['jid' => $groupJid]);
    $g = $stmt->fetch();
    if ($g) {
        $groupId = (int)$g['id'];
    }
}

try {
    $stmt = db()->prepare(
        'INSERT INTO whatsapp_group_messages (demand_id, group_id, group_jid, external_message_id, sender_phone, body, received_at, raw_json) '
        . 'VALUES (:did, :gid, :gjid, :mid, :phone, :body, :rat, :raw)'
        . ' ON DUPLICATE KEY UPDATE body = VALUES(body)'
    );

    $stmt->execute([
        'did' => $demandId,
        'gid' => $groupId,
        'gjid' => $groupJid,
        'mid' => $externalMessageId,
        'phone' => $senderPhone !== '' ? $senderPhone : '0',
        'body' => $body !== '' ? $body : '[sem texto]',
        'rat' => $receivedAt,
        'raw' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    integration_log('evolution_webhook', 'persist_group_message', 'error', 500, ['token' => $foundToken], $payload, mb_strimwidth($e->getMessage(), 0, 255, ''), 1);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

integration_log('evolution_webhook', 'persist_group_message', 'success', 200, ['token' => $foundToken], $payload, null, 0);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);
