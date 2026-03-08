<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$templateId = (int)($_POST['template_id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$templateType = trim((string)($_POST['template_type'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$zapsignTemplateToken = trim((string)($_POST['zapsign_template_token'] ?? ''));
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($name === '' || $templateType === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /zapsign_config.php');
    exit;
}

// Upload de PDF se fornecido
$pdfFilePath = null;
if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/uploads/contract_templates/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileExtension = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
    if ($fileExtension !== 'pdf') {
        flash_set('error', 'Apenas arquivos PDF são permitidos.');
        header('Location: /zapsign_config.php');
        exit;
    }
    
    $fileName = uniqid('contract_') . '.pdf';
    $filePath = $uploadDir . $fileName;
    
    if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $filePath)) {
        $pdfFilePath = '/uploads/contract_templates/' . $fileName;
    }
}

if ($templateId === 0) {
    // Criar novo template
    $sql = 'INSERT INTO zapsign_contract_templates (name, template_type, description, zapsign_template_token, pdf_file_path, is_active) 
            VALUES (:name, :template_type, :description, :zapsign_template_token, :pdf_file_path, :is_active)';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'name' => $name,
        'template_type' => $templateType,
        'description' => $description !== '' ? $description : null,
        'zapsign_template_token' => $zapsignTemplateToken !== '' ? $zapsignTemplateToken : null,
        'pdf_file_path' => $pdfFilePath,
        'is_active' => $isActive,
    ]);
    
    flash_set('success', 'Template criado com sucesso!');
} else {
    // Atualizar template existente
    $updateFields = [
        'name = :name',
        'template_type = :template_type',
        'description = :description',
        'zapsign_template_token = :zapsign_template_token',
        'is_active = :is_active'
    ];
    
    $params = [
        'id' => $templateId,
        'name' => $name,
        'template_type' => $templateType,
        'description' => $description !== '' ? $description : null,
        'zapsign_template_token' => $zapsignTemplateToken !== '' ? $zapsignTemplateToken : null,
        'is_active' => $isActive,
    ];
    
    if ($pdfFilePath !== null) {
        $updateFields[] = 'pdf_file_path = :pdf_file_path';
        $params['pdf_file_path'] = $pdfFilePath;
    }
    
    $sql = 'UPDATE zapsign_contract_templates SET ' . implode(', ', $updateFields) . ' WHERE id = :id';
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    
    flash_set('success', 'Template atualizado com sucesso!');
}

header('Location: /zapsign_config.php');
exit;
