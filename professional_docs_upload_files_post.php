<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

$uid = (int)auth_user_id();
$docId = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM professional_documentations WHERE id = :id AND professional_user_id = :uid');
$stmt->execute(['id' => $docId, 'uid' => $uid]);
$form = $stmt->fetch();

if (!$form) {
    flash_set('error', 'Formulário não encontrado.');
    header('Location: /professional_docs_list.php');
    exit;
}

if ((string)$form['status'] !== 'draft') {
    flash_set('error', 'Apenas rascunhos podem receber anexos.');
    header('Location: /professional_docs_edit.php?id=' . $docId);
    exit;
}

$filesByKind = [
    'billing' => $_FILES['billing_files'] ?? null,
    'productivity' => $_FILES['productivity_files'] ?? null,
];

$uploadedAny = false;
$errors = [];

$baseDir = __DIR__ . '/storage/documents';
if (!is_dir($baseDir) && !mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
    flash_set('error', 'Não foi possível criar diretório de armazenamento.');
    header('Location: /professional_docs_edit.php?id=' . $docId);
    exit;
}

$db = db();

foreach ($filesByKind as $kind => $fileSpec) {
    if (!is_array($fileSpec) || !isset($fileSpec['name']) || !is_array($fileSpec['name'])) {
        continue;
    }

    $count = count($fileSpec['name']);
    for ($i = 0; $i < $count; $i++) {
        $err = (int)($fileSpec['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = $kind . ': falha no upload (arquivo #' . ($i + 1) . ')';
            continue;
        }

        $tmp = (string)($fileSpec['tmp_name'][$i] ?? '');
        $original = (string)($fileSpec['name'][$i] ?? 'arquivo');
        $size = isset($fileSpec['size'][$i]) ? (int)$fileSpec['size'][$i] : null;
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($tmp) : null;

        $ext = pathinfo($original, PATHINFO_EXTENSION);
        $ext = $ext !== '' ? ('.' . strtolower($ext)) : '';

        $day = (new DateTime())->format('Y-m-d');
        $seq = random_int(100, 999);
        $cat = ($kind === 'billing') ? 'Faturamento' : 'Produtividade';
        $typePrefix = strtoupper($cat);
        $typePrefix = preg_replace('/[^A-Z0-9_]+/', '_', $typePrefix) ?? 'DOC';

        $storedName = $typePrefix . '_PROF' . (string)$uid . '_DOC' . (string)$docId . '_' . $day . '_' . sprintf('%03d', $seq) . $ext;
        $storedPath = $baseDir . '/' . $storedName;

        if (!move_uploaded_file($tmp, $storedPath)) {
            $errors[] = $kind . ': não foi possível salvar o arquivo ' . $original;
            continue;
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO documents (entity_type, entity_id, category, title, status) VALUES (\'professional\', :eid, :cat, :title, \'active\')');
            $stmt->execute([
                'eid' => $uid,
                'cat' => $cat,
                'title' => 'Formulário #' . $docId,
            ]);
            $newDocumentId = (int)$db->lastInsertId();

            $stmt = $db->prepare('INSERT INTO document_versions (document_id, version_no, stored_path, original_name, mime_type, file_size, valid_until, uploaded_by_user_id) VALUES (:did, :v, :path, :orig, :mime, :size, NULL, :uid)');
            $stmt->execute([
                'did' => $newDocumentId,
                'v' => 1,
                'path' => 'storage/documents/' . $storedName,
                'orig' => $original,
                'mime' => $mime,
                'size' => $size,
                'uid' => $uid,
            ]);

            $stmt = $db->prepare('INSERT INTO professional_documentation_documents (documentation_id, document_id, doc_kind) VALUES (:docid, :did, :kind)');
            $stmt->execute([
                'docid' => $docId,
                'did' => $newDocumentId,
                'kind' => $kind,
            ]);

            audit_log('create', 'professional_documentation_documents', (string)$docId, null, ['document_id' => $newDocumentId, 'doc_kind' => $kind]);

            $db->commit();
            $uploadedAny = true;
        } catch (Throwable $e) {
            $db->rollBack();
            $errors[] = $kind . ': erro ao registrar arquivo ' . $original;
            continue;
        }
    }
}

if (!$uploadedAny && count($errors) === 0) {
    flash_set('error', 'Nenhum arquivo selecionado.');
    header('Location: /professional_docs_edit.php?id=' . $docId);
    exit;
}

if (count($errors) > 0) {
    flash_set('error', 'Alguns arquivos falharam: ' . mb_strimwidth(implode(' | ', $errors), 0, 240, '...'));
} else {
    flash_set('success', 'Arquivos enviados.');
}

header('Location: /professional_docs_edit.php?id=' . $docId);
exit;
