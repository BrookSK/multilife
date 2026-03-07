<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /faturamento_profissional.php');
    exit;
}

$requirementId = isset($_POST['requirement_id']) ? (int)$_POST['requirement_id'] : 0;
$title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
$category = isset($_POST['category']) ? trim((string)$_POST['category']) : '';
$sessionDate = isset($_POST['session_date']) ? trim((string)$_POST['session_date']) : '';
$notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
$userId = auth_user_id();

if ($requirementId === 0 || $title === '' || $category === '' || $sessionDate === '') {
    $_SESSION['error'] = 'Todos os campos obrigatórios devem ser preenchidos';
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

// Processar upload do arquivo
if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error'] = 'Erro ao fazer upload do arquivo';
    header('Location: /faturamento_upload_doc.php?requirement_id=' . $requirementId);
    exit;
}

$file = $_FILES['document'];
$allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
$maxSize = 10 * 1024 * 1024; // 10MB

if (!in_array($file['type'], $allowedTypes)) {
    $_SESSION['error'] = 'Tipo de arquivo não permitido. Use PDF, JPG ou PNG';
    header('Location: /faturamento_upload_doc.php?requirement_id=' . $requirementId);
    exit;
}

if ($file['size'] > $maxSize) {
    $_SESSION['error'] = 'Arquivo muito grande. Tamanho máximo: 10MB';
    header('Location: /faturamento_upload_doc.php?requirement_id=' . $requirementId);
    exit;
}

// Criar diretório de uploads se não existir
$uploadDir = __DIR__ . '/uploads/billing_documents';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Gerar nome único para o arquivo
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$fileName = 'billing_' . $requirementId . '_' . time() . '_' . uniqid() . '.' . $extension;
$filePath = $uploadDir . '/' . $fileName;
$storedPath = '/uploads/billing_documents/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    $_SESSION['error'] = 'Erro ao salvar arquivo';
    header('Location: /faturamento_upload_doc.php?requirement_id=' . $requirementId);
    exit;
}

try {
    db()->beginTransaction();
    
    // Inserir documento na tabela documents
    $docStmt = db()->prepare("
        INSERT INTO documents (
            entity_type, entity_id, category, title, status, file_path, created_at
        ) VALUES (
            'professional', ?, ?, ?, 'active', ?, NOW()
        )
    ");
    $docStmt->execute([$userId, $category, $title, $storedPath]);
    $documentId = (int)db()->lastInsertId();
    
    // Inserir versão do documento
    $versionStmt = db()->prepare("
        INSERT INTO document_versions (
            document_id, version_no, stored_path, file_size, uploaded_by_user_id, created_at
        ) VALUES (
            ?, 1, ?, ?, ?, NOW()
        )
    ");
    $versionStmt->execute([$documentId, $storedPath, $file['size'], $userId]);
    
    // Atualizar requisito de documento
    $updateStmt = db()->prepare("
        UPDATE billing_document_requirements
        SET 
            document_id = ?,
            session_date = ?,
            status = 'uploaded',
            uploaded_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$documentId, $sessionDate, $requirementId]);
    
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
    $prontuarioNotes = "Documento de comprovação enviado:\n";
    $prontuarioNotes .= "Sessão: " . (int)$requirement['session_number'] . "\n";
    $prontuarioNotes .= "Data: " . $sessionDate . "\n";
    $prontuarioNotes .= "Título: " . $title . "\n";
    if ($notes !== '') {
        $prontuarioNotes .= "Observações: " . $notes;
    }
    $prontuarioStmt->execute([$requirement['patient_id'], $userId, $prontuarioNotes]);
    
    db()->commit();
    
    $_SESSION['success'] = 'Documento enviado com sucesso! Aguarde a revisão do financeiro.';
    header('Location: /faturamento_profissional.php');
    exit;
    
} catch (Exception $e) {
    db()->rollBack();
    
    // Remover arquivo se houver erro
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    error_log('Erro ao processar upload de documento de faturamento: ' . $e->getMessage());
    $_SESSION['error'] = 'Erro ao processar documento. Tente novamente.';
    header('Location: /faturamento_upload_doc.php?requirement_id=' . $requirementId);
    exit;
}
