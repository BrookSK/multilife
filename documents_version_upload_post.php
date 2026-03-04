<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$id = (int)($_POST['id'] ?? 0);
$validUntil = trim((string)($_POST['valid_until'] ?? ''));

if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
    flash_set('error', 'Validade inválida.');
    header('Location: /documents_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT * FROM documents WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();
if (!$d) {
    flash_set('error', 'Documento não encontrado.');
    header('Location: /documents_list.php');
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    flash_set('error', 'Arquivo obrigatório.');
    header('Location: /documents_view.php?id=' . $id);
    exit;
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_set('error', 'Falha no upload.');
    header('Location: /documents_view.php?id=' . $id);
    exit;
}

$tmp = (string)$f['tmp_name'];
$original = (string)$f['name'];
$size = isset($f['size']) ? (int)$f['size'] : null;
$mime = function_exists('mime_content_type') ? (string)@mime_content_type($tmp) : null;

$ext = pathinfo($original, PATHINFO_EXTENSION);
$ext = $ext !== '' ? ('.' . strtolower($ext)) : '';

$baseDir = __DIR__ . '/storage/documents';
if (!is_dir($baseDir) && !mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
    flash_set('error', 'Não foi possível criar diretório de armazenamento.');
    header('Location: /documents_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT COALESCE(MAX(version_no),0) AS v FROM document_versions WHERE document_id = :id');
$stmt->execute(['id' => $id]);
$max = (int)($stmt->fetch()['v'] ?? 0);
$next = $max + 1;

$day = (new DateTime())->format('Y-m-d');
$seq = random_int(100, 999);
$typePrefix = strtoupper((string)$d['category']);
$typePrefix = preg_replace('/[^A-Z0-9_]+/', '_', $typePrefix) ?? 'DOC';
$entityType = (string)$d['entity_type'];
$entPart = $entityType === 'company' ? 'EMP' : ($entityType === 'patient' ? 'PAC' : 'PROF');
$entIdPart = $entityType === 'company' ? 'EMP' : (string)($d['entity_id'] ?? '');

$storedName = $typePrefix . '_' . $entPart . $entIdPart . '_' . $day . '_' . sprintf('%03d', $seq) . $ext;
$storedPath = $baseDir . '/' . $storedName;

if (!move_uploaded_file($tmp, $storedPath)) {
    flash_set('error', 'Não foi possível salvar o arquivo.');
    header('Location: /documents_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('INSERT INTO document_versions (document_id, version_no, stored_path, original_name, mime_type, file_size, valid_until, uploaded_by_user_id) VALUES (:did, :v, :path, :orig, :mime, :size, :valid, :uid)');
$stmt->execute([
    'did' => $id,
    'v' => $next,
    'path' => 'storage/documents/' . $storedName,
    'orig' => $original,
    'mime' => $mime,
    'size' => $size,
    'valid' => $validUntil !== '' ? $validUntil : null,
    'uid' => auth_user_id(),
]);

audit_log('create', 'document_versions', (string)$id, null, ['version_no' => $next]);

flash_set('success', 'Nova versão enviada (v' . $next . ').');
header('Location: /documents_view.php?id=' . $id);
exit;
