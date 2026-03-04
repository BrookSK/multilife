<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$entityType = (string)($_POST['entity_type'] ?? '');
$entityIdRaw = trim((string)($_POST['entity_id'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$validUntil = trim((string)($_POST['valid_until'] ?? ''));

$allowedTypes = ['patient','professional','company'];
if (!in_array($entityType, $allowedTypes, true)) {
    flash_set('error', 'Tipo de entidade inválido.');
    header('Location: /documents_upload.php');
    exit;
}

$entityId = null;
if ($entityType !== 'company') {
    if ($entityIdRaw === '' || !ctype_digit($entityIdRaw)) {
        flash_set('error', 'Informe o ID da entidade.');
        header('Location: /documents_upload.php');
        exit;
    }
    $entityId = (int)$entityIdRaw;
}

if ($category === '') {
    flash_set('error', 'Informe a categoria.');
    header('Location: /documents_upload.php');
    exit;
}

if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
    flash_set('error', 'Validade inválida.');
    header('Location: /documents_upload.php');
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    flash_set('error', 'Arquivo obrigatório.');
    header('Location: /documents_upload.php');
    exit;
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_set('error', 'Falha no upload.');
    header('Location: /documents_upload.php');
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
    header('Location: /documents_upload.php');
    exit;
}

$day = (new DateTime())->format('Y-m-d');
$seq = random_int(100, 999);
$typePrefix = strtoupper($category);
$typePrefix = preg_replace('/[^A-Z0-9_]+/', '_', $typePrefix) ?? 'DOC';
$entPart = $entityType === 'company' ? 'EMP' : ($entityType === 'patient' ? 'PAC' : 'PROF');
$entIdPart = $entityType === 'company' ? 'EMP' : (string)$entityId;

$storedName = $typePrefix . '_' . $entPart . $entIdPart . '_' . $day . '_' . sprintf('%03d', $seq) . $ext;
$storedPath = $baseDir . '/' . $storedName;

if (!move_uploaded_file($tmp, $storedPath)) {
    flash_set('error', 'Não foi possível salvar o arquivo.');
    header('Location: /documents_upload.php');
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO documents (entity_type, entity_id, category, title, status) VALUES (:et, :eid, :cat, :title, \'active\')');
    $stmt->execute([
        'et' => $entityType,
        'eid' => $entityId,
        'cat' => $category,
        'title' => $title !== '' ? $title : null,
    ]);

    $docId = (int)$db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO document_versions (document_id, version_no, stored_path, original_name, mime_type, file_size, valid_until, uploaded_by_user_id) VALUES (:did, :v, :path, :orig, :mime, :size, :valid, :uid)');
    $stmt->execute([
        'did' => $docId,
        'v' => 1,
        'path' => 'storage/documents/' . $storedName,
        'orig' => $original,
        'mime' => $mime,
        'size' => $size,
        'valid' => $validUntil !== '' ? $validUntil : null,
        'uid' => auth_user_id(),
    ]);

    audit_log('create', 'documents', (string)$docId, null, ['entity_type' => $entityType, 'entity_id' => $entityId, 'category' => $category]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Documento enviado (v1).');
header('Location: /documents_view.php?id=' . $docId);
exit;
