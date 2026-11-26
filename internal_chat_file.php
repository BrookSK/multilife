<?php

declare(strict_types=1);

/** Download/visualização de arquivo do Chat Interno (só para quem está na conversa). */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/internal_chat.php';

auth_require_login();
$uid = (int)auth_user_id();
session_release();
ichat_ensure_schema();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM internal_chat_messages WHERE id = ? AND deleted_at IS NULL');
$st->execute([$id]);
$m = $st->fetch(PDO::FETCH_ASSOC);
if (!$m || empty($m['file_path']) || !ichat_membership((int)$m['conversation_id'], $uid)) {
    http_response_code(404);
    echo 'Arquivo não encontrado.';
    exit;
}
$root = realpath(__DIR__ . '/storage/internal_chat');
$path = realpath(__DIR__ . '/' . (string)$m['file_path']);
if ($root === false || $path === false || !str_starts_with($path, $root) || !is_file($path)) {
    http_response_code(404);
    echo 'Arquivo não encontrado.';
    exit;
}
$mime = (string)($m['file_mime'] ?: 'application/octet-stream');
$inline = isset($_GET['inline']) && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
$name = (string)($m['file_name'] ?: 'arquivo');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . str_replace('"', '', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
readfile($path);
