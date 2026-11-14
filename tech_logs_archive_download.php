<?php
/**
 * Baixa um arquivo de logs arquivados (storage/log_archive/integration_logs/AAAA-MM.jsonl.gz).
 * Cada linha do arquivo é um registro JSON da antiga tabela integration_logs.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');
session_release();

$name = basename((string)($_GET['f'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}\.jsonl\.gz$/', $name)) {
    http_response_code(400);
    exit('Arquivo inválido.');
}
$path = integration_logs_archive_dir() . '/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="integration_logs_' . $name . '"');
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
