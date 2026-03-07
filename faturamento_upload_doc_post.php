<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /profissional_registros.php');
    exit;
}

$requirementId = isset($_POST['requirement_id']) ? (int)$_POST['requirement_id'] : 0;
$sessionDate = isset($_POST['session_date']) ? trim((string)$_POST['session_date']) : '';
$notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
$userId = auth_user_id();

if ($requirementId === 0 || $sessionDate === '') {
    $_SESSION['error'] = 'Data da sessão é obrigatória';
    header('Location: /faturamento_upload_doc.php?requirement_id=' . $requirementId);
    exit;
}

// Buscar requisito
$stmt = db()->prepare("
    SELECT bdr.*, pa.patient_id
    FROM billing_document_requirements bdr
    INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
    WHERE bdr.id = ? AND bdr.professional_user_id = ?
");
$stmt->execute([$requirementId, $userId]);
$requirement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$requirement) {
    $_SESSION['error'] = 'Requisito não encontrado';
    header('Location: /faturamento_profissional.php');
    exit;
}

// Validar arquivos
$prodFiles = isset($_FILES['produtividade']) ? $_FILES['produtividade'] : null;
$fatFiles = isset($_FILES['faturamento']) ? $_FILES['faturamento'] : null;

if ((!$prodFiles || empty($prodFiles['name'][0])) && (!$fatFiles || empty($fatFiles['name'][0]))) {
    $_SESSION['error'] = 'Envie pelo menos um arquivo de Produtividade ou Faturamento';
    header('Location: /faturamento_upload_doc.php?requirement_id=' . $requirementId);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png'];
$maxSize = 10 * 1024 * 1024; // 10MB
$maxFiles = 20;

// Criar diretório de uploads se não existir
$uploadDir = __DIR__ . '/uploads/billing_documents';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$uploadedFiles = [];

// Função para processar array de arquivos
function processFileArray($files, $docType, $requirementId, $userId, $allowedTypes, $maxSize, $maxFiles, $uploadDir) {
    $uploaded = [];
    
    if (!$files || empty($files['name'][0])) {
        return $uploaded;
    }
    
    $fileCount = count($files['name']);
    
    if ($fileCount > $maxFiles) {
        throw new Exception("Máximo de {$maxFiles} arquivos por tipo");
    }
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        $fileType = $files['type'][$i];
        $fileSize = $files['size'][$i];
        $fileName = $files['name'][$i];
        $fileTmp = $files['tmp_name'][$i];
        
        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception("Arquivo {$fileName}: apenas JPEG e PNG são permitidos");
        }
        
        if ($fileSize > $maxSize) {
            throw new Exception("Arquivo {$fileName} muito grande. Máximo: 10MB");
        }
        
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $newFileName = $docType . '_' . $requirementId . '_' . time() . '_' . uniqid() . '.' . $extension;
        $filePath = $uploadDir . '/' . $newFileName;
        $storedPath = '/uploads/billing_documents/' . $newFileName;
        
        if (!move_uploaded_file($fileTmp, $filePath)) {
            throw new Exception("Erro ao salvar arquivo {$fileName}");
        }
        
        $uploaded[] = [
            'type' => $docType,
            'path' => $storedPath,
            'size' => $fileSize,
            'mime' => $fileType,
            'original' => $fileName
        ];
    }
    
    return $uploaded;
}

try {
    // Processar arquivos de produtividade
    $prodUploaded = processFileArray($prodFiles, 'produtividade', $requirementId, $userId, $allowedTypes, $maxSize, $maxFiles, $uploadDir);
    
    // Processar arquivos de faturamento
    $fatUploaded = processFileArray($fatFiles, 'faturamento', $requirementId, $userId, $allowedTypes, $maxSize, $maxFiles, $uploadDir);
    
    $allUploaded = array_merge($prodUploaded, $fatUploaded);
    
    if (empty($allUploaded)) {
        throw new Exception('Nenhum arquivo foi enviado com sucesso');
    }
    
    db()->beginTransaction();
    
    // Inserir arquivos na tabela billing_document_files E na tabela documents
    $fileStmt = db()->prepare("
        INSERT INTO billing_document_files (
            requirement_id, document_type, file_path, file_size, mime_type, original_filename, uploaded_by_user_id, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");
    
    // Preparar statements para documents e document_versions
    $docStmt = db()->prepare("
        INSERT INTO documents (
            entity_type, entity_id, category, title, status
        ) VALUES (?, ?, ?, ?, 'active')
    ");
    
    $versionStmt = db()->prepare("
        INSERT INTO document_versions (
            document_id, version_no, stored_path, file_size, uploaded_by_user_id
        ) VALUES (?, 1, ?, ?, ?)
    ");
    
    foreach ($allUploaded as $file) {
        // Salvar em billing_document_files
        $fileStmt->execute([
            $requirementId,
            $file['type'],
            $file['path'],
            $file['size'],
            $file['mime'],
            $file['original'],
            $userId
        ]);
        
        // Criar título descritivo
        $docType = $file['type'] === 'produtividade' ? 'Ficha de Produtividade' : 'Ficha de Faturamento';
        $docTitle = $docType . ' - Sessão ' . (int)$requirement['session_number'] . ' - ' . date('d/m/Y', strtotime($sessionDate));
        
        // Criar documento para o PACIENTE
        $docStmt->execute([
            'patient',
            $requirement['patient_id'],
            $docType,
            $docTitle
        ]);
        $patientDocId = (int)db()->lastInsertId();
        
        // Criar versão do documento do paciente
        $versionStmt->execute([
            $patientDocId,
            $file['path'],
            $file['size'],
            $userId
        ]);
        
        // Criar documento para o PROFISSIONAL
        $docStmt->execute([
            'professional',
            $userId,
            $docType,
            $docTitle
        ]);
        $professionalDocId = (int)db()->lastInsertId();
        
        // Criar versão do documento do profissional
        $versionStmt->execute([
            $professionalDocId,
            $file['path'],
            $file['size'],
            $userId
        ]);
    }
    
    // Atualizar requisito de documento
    $updateStmt = db()->prepare("
        UPDATE billing_document_requirements
        SET 
            session_date = ?,
            status = 'uploaded',
            uploaded_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$sessionDate, $requirementId]);
    
    // Verificar se todos os documentos foram enviados
    $checkStmt = db()->prepare("
        SELECT COUNT(*) as pending_count
        FROM billing_document_requirements
        WHERE assignment_id = ? AND status = 'pending'
    ");
    $checkStmt->execute([$requirement['assignment_id']]);
    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    // Se todos os documentos foram enviados, atualizar status do assignment
    if ((int)$checkResult['pending_count'] === 0) {
        $assignmentStmt = db()->prepare("
            UPDATE patient_assignments
            SET status = 'awaiting_financial_approval', updated_at = NOW()
            WHERE id = ?
        ");
        $assignmentStmt->execute([$requirement['assignment_id']]);
    }
    
    // Registrar no prontuário do paciente
    $prontuarioStmt = db()->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, notes)
        VALUES (?, ?, 'faturamento_upload', NOW(), ?)
    ");
    $prontuarioNotes = "Documentos de comprovação enviados:\n";
    $prontuarioNotes .= "Sessão: " . (int)$requirement['session_number'] . "\n";
    $prontuarioNotes .= "Data: " . $sessionDate . "\n";
    $prontuarioNotes .= "Produtividade: " . count($prodUploaded) . " arquivo(s)\n";
    $prontuarioNotes .= "Faturamento: " . count($fatUploaded) . " arquivo(s)\n";
    if ($notes !== '') {
        $prontuarioNotes .= "Observações: " . $notes;
    }
    $prontuarioStmt->execute([$requirement['patient_id'], $userId, $prontuarioNotes]);
    
    db()->commit();
    
    $totalFiles = count($allUploaded);
    $_SESSION['success'] = "{$totalFiles} arquivo(s) enviado(s) com sucesso! Aguarde a revisão do financeiro.";
    header('Location: /profissional_registros.php');
    exit;
    
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    
    // Remover arquivos enviados se houver erro
    if (isset($allUploaded)) {
        foreach ($allUploaded as $file) {
            $fullPath = __DIR__ . $file['path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
    }
    
    error_log('Erro ao processar upload de documentos de faturamento: ' . $e->getMessage());
    $_SESSION['error'] = $e->getMessage();
    header('Location: /faturamento_upload_doc.php?requirement_id=' . $requirementId);
    exit;
}
