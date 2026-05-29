<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$patientId = (int)($_POST['patient_id'] ?? 0);
$category = trim((string)($_POST['doc_category'] ?? ''));
$title = trim((string)($_POST['doc_title'] ?? ''));
$validUntil = trim((string)($_POST['doc_valid_until'] ?? ''));
$notes = trim((string)($_POST['doc_notes'] ?? ''));

if ($patientId <= 0) {
    flash_set('error', 'Paciente inválido.');
    header('Location: /patients_list.php');
    exit;
}

if ($category === '') {
    flash_set('error', 'Selecione a categoria do documento.');
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
    flash_set('error', 'Data de validade inválida.');
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

if (!isset($_FILES['doc_file']) || !is_array($_FILES['doc_file'])) {
    flash_set('error', 'Arquivo obrigatório.');
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

$f = $_FILES['doc_file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_set('error', 'Falha no upload do arquivo.');
    header('Location: /patients_edit.php?id=' . $patientId);
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
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

$day = (new DateTime())->format('Y-m-d');
$seq = random_int(100, 999);
$catPrefix = strtoupper(preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($category)) ?? 'DOC');
$storedName = $catPrefix . '_PAC' . $patientId . '_' . $day . '_' . sprintf('%03d', $seq) . $ext;
$storedPath = $baseDir . '/' . $storedName;

if (!move_uploaded_file($tmp, $storedPath)) {
    flash_set('error', 'Não foi possível salvar o arquivo.');
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

$docTitle = $title !== '' ? $title : $category;
if ($notes !== '') {
    $docTitle .= ' - ' . $notes;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        "INSERT INTO documents (entity_type, entity_id, category, title, status) VALUES ('patient', :eid, :cat, :title, 'active')"
    );
    $stmt->execute([
        'eid' => $patientId,
        'cat' => $category,
        'title' => $docTitle,
    ]);
    $docId = (int)$db->lastInsertId();

    $stmt = $db->prepare(
        'INSERT INTO document_versions (document_id, version_no, stored_path, original_name, mime_type, file_size, valid_until, uploaded_by_user_id) VALUES (:did, 1, :path, :orig, :mime, :size, :valid, :uid)'
    );
    $stmt->execute([
        'did' => $docId,
        'path' => 'storage/documents/' . $storedName,
        'orig' => $original,
        'mime' => $mime,
        'size' => $size,
        'valid' => $validUntil !== '' ? $validUntil : null,
        'uid' => auth_user_id(),
    ]);

    audit_log('create', 'patient_document', (string)$docId, null, [
        'patient_id' => $patientId,
        'category' => $category,
        'file' => $original,
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    @unlink($storedPath);
    flash_set('error', 'Erro ao salvar documento: ' . $e->getMessage());
    header('Location: /patients_edit.php?id=' . $patientId);
    exit;
}

flash_set('success', 'Documento "' . $category . '" enviado com sucesso.');
header('Location: /patients_edit.php?id=' . $patientId);
exit;
