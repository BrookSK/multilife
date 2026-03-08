<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = (int)($_POST['employee_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$documentType = trim((string)($_POST['document_type'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($title === '') {
    flash_set('error', 'Informe o título do documento.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=documentos');
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    flash_set('error', 'Selecione um arquivo para upload.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=documentos');
    exit;
}

// Upload do arquivo
$uploadDir = __DIR__ . '/uploads/hr_documents/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$fileExtension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
$fileName = uniqid('hr_doc_') . '.' . $fileExtension;
$filePath = $uploadDir . $fileName;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
    flash_set('error', 'Erro ao fazer upload do arquivo.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=documentos');
    exit;
}

// Salvar no banco de dados (tabela documents)
$sql = 'INSERT INTO documents (
    entity_type, entity_id, title, document_type, file_path, file_name, file_size, 
    uploaded_by_user_id, notes
) VALUES (
    :entity_type, :entity_id, :title, :document_type, :file_path, :file_name, :file_size,
    :uploaded_by_user_id, :notes
)';

$stmt = db()->prepare($sql);
$stmt->execute([
    'entity_type' => 'hr_employee',
    'entity_id' => $employeeId,
    'title' => $title,
    'document_type' => $documentType,
    'file_path' => '/uploads/hr_documents/' . $fileName,
    'file_name' => $_FILES['file']['name'],
    'file_size' => $_FILES['file']['size'],
    'uploaded_by_user_id' => auth_user_id(),
    'notes' => $notes !== '' ? $notes : null,
]);

flash_set('success', 'Documento enviado com sucesso!');
header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=documentos');
exit;
