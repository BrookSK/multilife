<?php

declare(strict_types=1);

/**
 * Download de mídia do chat (imagem, vídeo, áudio, documento) com o nome do
 * arquivo. Só serve arquivos gravados em /uploads/ e de conversas que o usuário
 * pode ver (mesmas regras de WhatsApp do chat).
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');
$userId = (int)(auth_user_id() ?? 0);
session_release();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Mensagem inválida.');
}

$st = db()->prepare('SELECT id, remote_jid, instance_name, message_type, media_url, media_mime_type, media_filename
                     FROM chat_messages WHERE id = ? LIMIT 1');
$st->execute([$id]);
$msg = $st->fetch(PDO::FETCH_ASSOC);
if (!$msg || (string)$msg['media_url'] === '') {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

// Mesma regra de visibilidade das mensagens no chat (WhatsApps do usuário)
[$instSql, $instParams] = whatsapp_messages_instance_filter($userId, (string)$msg['remote_jid']);
if ($instSql !== '') {
    $chk = db()->prepare('SELECT 1 FROM chat_messages WHERE id = ?' . $instSql . ' LIMIT 1');
    $chk->execute(array_merge([$id], $instParams));
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        exit('Sem acesso a este arquivo.');
    }
}

// Caminho local: aceita "/uploads/..." ou URL absoluta do próprio servidor
$mediaUrl = (string)$msg['media_url'];
$path = (string)(parse_url($mediaUrl, PHP_URL_PATH) ?? '');
$isOwnHost = !preg_match('#^https?://#i', $mediaUrl)
    || strcasecmp((string)parse_url($mediaUrl, PHP_URL_HOST), (string)($_SERVER['HTTP_HOST'] ?? '')) === 0;

$uploadsRoot = realpath(__DIR__ . '/uploads');
$file = ($isOwnHost && $path !== '') ? realpath(__DIR__ . '/' . ltrim(rawurldecode($path), '/')) : false;

if ($uploadsRoot === false || $file === false || strpos($file, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    // Arquivo fora do servidor (ex.: link externo): abre o link original
    if (preg_match('#^https?://#i', $mediaUrl)) {
        header('Location: ' . $mediaUrl);
        exit;
    }
    http_response_code(404);
    exit('Arquivo não encontrado no servidor.');
}

// Nome do arquivo: o original quando existe, senão um nome pelo tipo + extensão do arquivo
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$name = trim((string)($msg['media_filename'] ?? ''));
if ($name === '' || in_array($name, ['image.jpg', 'audio.ogg', 'video.mp4', 'document.pdf'], true)) {
    $labels = ['image' => 'imagem', 'video' => 'video', 'audio' => 'audio', 'document' => 'documento', 'sticker' => 'figurinha'];
    $name = ($labels[(string)$msg['message_type']] ?? 'arquivo') . '_' . $id . ($ext !== '' ? '.' . $ext : '');
} elseif (pathinfo($name, PATHINFO_EXTENSION) === '' && $ext !== '') {
    $name .= '.' . $ext;
}
$name = str_replace(["\r", "\n", '"', '\\', '/'], '_', $name);
$asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: ('arquivo_' . $id);

$mime = (string)preg_replace('/[^\w\/.+\-; =]/', '', (string)($msg['media_mime_type'] ?? ''));
if ($mime === '' || strpos($mime, '/') === false) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0');
readfile($file);
