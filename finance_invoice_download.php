<?php

declare(strict_types=1);

/**
 * Visualização/Download de uma Nota Fiscal do financeiro.
 * Requer login + permissão financeira. Serve o arquivo inline (abre no navegador).
 *
 * Uso: /finance_invoice_download.php?id=<id>
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
$uid = auth_user_id();
if (!rbac_user_can($uid, 'finance.manage') && !rbac_user_can($uid, 'finance.view')) {
    rbac_require_permission('finance.manage');
}

function fi_dl_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    fi_dl_fail(404, 'Nota fiscal inválida.');
}

$stmt = db()->prepare('SELECT file_path, file_name FROM finance_invoices WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fi_dl_fail(404, 'Nota fiscal não encontrada.');
}

// Resolver caminho físico (armazenado como caminho relativo em /uploads).
$relative = ltrim((string)$row['file_path'], '/');
$fullPath = __DIR__ . '/' . $relative;
$realBase = realpath(__DIR__ . '/uploads');
$realFile = realpath($fullPath);

// Proteção contra path traversal: o arquivo tem que estar dentro de /uploads.
if ($realFile === false || $realBase === false || strncmp($realFile, $realBase, strlen($realBase)) !== 0) {
    fi_dl_fail(404, 'Arquivo indisponível.');
}
if (!is_file($realFile)) {
    fi_dl_fail(404, 'Arquivo indisponível.');
}

$downloadName = (string)($row['file_name'] ?? basename($realFile));
$downloadName = preg_replace('/[\r\n"]+/', '', $downloadName);
if ($downloadName === '') {
    $downloadName = basename($realFile);
}

$mime = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $realFile);
        finfo_close($finfo);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
}

while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: ' . $mime);
// inline: abre PDF/imagem no navegador; demais tipos o navegador decide baixar.
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($realFile));
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($realFile);
exit;
