<?php

declare(strict_types=1);

/**
 * Abre um anexo/imagem de e-mail de captação (só para quem gerencia captação).
 * Imagens e PDF abrem no navegador; os demais tipos são baixados.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

inbound_attachments_ensure_schema();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM inbound_email_attachments WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$att = $st->fetch(PDO::FETCH_ASSOC);

$path = $att ? (string)($att['file_path'] ?? '') : '';
$base = inbound_attachments_dir();
$real = $path !== '' ? realpath($path) : false;
$realBase = $base !== '' ? realpath($base) : false;
if (!$att || $real === false || $realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR) || !is_file($real)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Anexo não encontrado.';
    exit;
}

$mime = (string)$att['mime_type'];
$inlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$inline = in_array($mime, $inlineTypes, true);
$name = (string)$att['file_name'];
$ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'anexo';

audit_log('view', 'inbound_email_attachments', (string)$id, null, ['inbound_email_id' => (int)$att['inbound_email_id']]);

header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($real));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
readfile($real);
exit;
