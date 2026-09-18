<?php

declare(strict_types=1);

/**
 * Download PÚBLICO (sem login) de um documento da operadora, forçando o
 * navegador a baixar o arquivo (Content-Disposition: attachment) — útil quando
 * o profissional precisa imprimir/guardar o documento físico.
 *
 * Segurança: exige o mesmo token público do profissional (users.documents_token)
 * e só libera o download se o documento pertencer a uma operadora vinculada a
 * um atendimento ativo desse profissional.
 *
 * Uso: /documento_download.php?token=...&doc=<id>
 */

require_once __DIR__ . '/app/bootstrap.php';

function dl_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
$docId = (int)($_GET['doc'] ?? 0);

if ($token === '' || strlen($token) < 32 || $docId <= 0) {
    dl_fail(404, 'Link inválido.');
}

// Localizar o profissional pelo token de documentos.
$uStmt = db()->prepare('SELECT id FROM users WHERE documents_token = :t LIMIT 1');
$uStmt->execute(['t' => $token]);
$userId = (int)($uStmt->fetchColumn() ?: 0);
if ($userId <= 0) {
    dl_fail(404, 'Link inválido ou expirado.');
}

// Buscar o documento GARANTINDO que ele pertence a uma operadora de um
// atendimento ativo do profissional (mesma regra da página de documentos).
$sql = "SELECT hid.file_name, hid.file_path
        FROM health_insurer_documents hid
        INNER JOIN patient_assignments pa ON pa.health_insurer_id = hid.health_insurer_id
        WHERE hid.id = :doc
          AND pa.professional_user_id = :uid
          AND pa.status IN ('admitted', 'awaiting_documents', 'awaiting_financial_approval', 'approved')
        LIMIT 1";
$stmt = db()->prepare($sql);
$stmt->execute(['doc' => $docId, 'uid' => $userId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    dl_fail(404, 'Documento não encontrado.');
}

// Resolver o caminho físico do arquivo (armazenado como caminho relativo, ex.: /uploads/insurer_docs/..).
$relative = ltrim((string)$doc['file_path'], '/');
$fullPath = __DIR__ . '/' . $relative;
$realBase = realpath(__DIR__ . '/uploads');
$realFile = realpath($fullPath);

// Proteção contra path traversal: o arquivo tem que estar dentro de /uploads.
if ($realFile === false || $realBase === false || strncmp($realFile, $realBase, strlen($realBase)) !== 0) {
    dl_fail(404, 'Arquivo indisponível.');
}
if (!is_file($realFile)) {
    dl_fail(404, 'Arquivo indisponível.');
}

// Nome amigável para o download.
$downloadName = (string)($doc['file_name'] ?? basename($realFile));
$downloadName = preg_replace('/[\r\n"]+/', '', $downloadName);
if ($downloadName === '') {
    $downloadName = basename($realFile);
}

// Enviar como anexo (força o download).
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

// Limpar qualquer buffer para não corromper o binário.
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($realFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($realFile);
exit;
