<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$versionId = isset($_GET['version_id']) ? (int)$_GET['version_id'] : 0;

$stmt = db()->prepare(
    'SELECT v.id, v.stored_path, v.original_name, v.mime_type, v.file_size
     FROM document_versions v
     WHERE v.id = :id'
);
$stmt->execute(['id' => $versionId]);
$v = $stmt->fetch();

if (!$v) {
    http_response_code(404);
    echo "Arquivo não encontrado.";
    exit;
}

$stored = (string)$v['stored_path'];
$stored = str_replace('..', '', $stored);
$abs = realpath(__DIR__ . '/' . ltrim($stored, '/'));
$base = realpath(__DIR__ . '/storage/documents');

if ($abs === false || $base === false || strpos($abs, $base) !== 0 || !is_file($abs)) {
    http_response_code(404);
    echo "Arquivo inválido.";
    exit;
}

$mime = (string)($v['mime_type'] ?? 'application/octet-stream');
$name = (string)($v['original_name'] ?? 'download');

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($abs));

readfile($abs);
exit;
