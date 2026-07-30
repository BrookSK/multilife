<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$recipientType = trim((string)($_POST['recipient_type'] ?? ''));
$recipientId = (int)($_POST['recipient_id'] ?? 0);
$healthInsurerId = (int)($_POST['health_insurer_id'] ?? 0);
$sendMethod = trim((string)($_POST['send_method'] ?? 'email'));
$notes = trim((string)($_POST['notes'] ?? ''));

if (!in_array($recipientType, ['professional', 'patient'], true) || $recipientId <= 0) {
    flash_set('error', 'Selecione um destinatário válido.');
    header('Location: /admin_documents_sent.php');
    exit;
}

if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    flash_set('error', 'Selecione um arquivo para enviar.');
    header('Location: /admin_documents_sent.php');
    exit;
}

$file = $_FILES['document'];
$fileName = $file['name'];
$fileSize = (int)$file['size'];
$fileType = $file['type'];
$tmpName = $file['tmp_name'];

$allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];
$maxSize = 10 * 1024 * 1024;

$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExtensions)) {
    flash_set('error', 'Formato não permitido. Aceitos: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, WEBP.');
    header('Location: /admin_documents_sent.php');
    exit;
}

if ($fileSize > $maxSize) {
    flash_set('error', 'Arquivo excede o tamanho máximo de 10MB.');
    header('Location: /admin_documents_sent.php');
    exit;
}

// Salvar arquivo
$uploadDir = __DIR__ . '/uploads/manual_docs/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
$uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
$destPath = $uploadDir . $uniqueName;

if (!move_uploaded_file($tmpName, $destPath)) {
    flash_set('error', 'Falha ao salvar o arquivo.');
    header('Location: /admin_documents_sent.php');
    exit;
}

$relativePath = '/uploads/manual_docs/' . $uniqueName;

$db = db();

// Salvar documento na tabela de documentos (se vinculado a operadora)
$documentId = 0;
if ($healthInsurerId > 0) {
    $stmt = $db->prepare("
        INSERT INTO health_insurer_documents (health_insurer_id, file_name, file_path, file_size, mime_type, uploaded_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$healthInsurerId, $fileName, $relativePath, $fileSize, $fileType, auth_user_id()]);
    $documentId = (int)$db->lastInsertId();
} else {
    // Documento avulso - usar ID 0 no log
    $documentId = 0;
}

// Buscar dados do destinatário
$recipientEmail = '';
$recipientName = '';

if ($recipientType === 'professional') {
    $stmt = $db->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $stmt->execute([$recipientId]);
    $recipient = $stmt->fetch();
    $recipientEmail = trim((string)($recipient['email'] ?? ''));
    $recipientName = $recipient['name'] ?? '';
} else {
    $stmt = $db->prepare("SELECT full_name, email, whatsapp FROM patients WHERE id = ?");
    $stmt->execute([$recipientId]);
    $recipient = $stmt->fetch();
    $recipientEmail = trim((string)($recipient['email'] ?? ''));
    $recipientName = $recipient['full_name'] ?? '';
}

$sent = false;

// Enviar por e-mail
if ($sendMethod === 'email' && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        require_once __DIR__ . '/app/email_base_template.php';
        
        $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
        $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
        
        if ($fromEmail !== '') {
            $baseUrl = rtrim((string)admin_setting_get('app.base_url', 'https://multilife.onsolutionsbrasil.com.br'), '/');
            $docUrl = $baseUrl . $relativePath;
            
            $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($recipientName) . '</strong>!</p>';
            $body .= '<p style="font-size:14px;color:#4b5563">Segue documento enviado pela equipe MultiLife Care:</p>';
            
            $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
            $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Documento</h3>';
            $icon = preg_match('/\.pdf$/i', $fileName) ? '📄' : '📎';
            $body .= '<p style="margin:6px 0;font-size:14px">' . $icon . ' <a href="' . htmlspecialchars($docUrl) . '" target="_blank" style="color:#0284c7;text-decoration:underline">' . htmlspecialchars($fileName) . '</a></p>';
            if ($notes !== '') {
                $body .= '<p style="margin:12px 0 0;font-size:13px;color:#6b7280">' . nl2br(htmlspecialchars($notes)) . '</p>';
            }
            $body .= '</div>';
            
            $body .= email_divider();
            $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
            
            $htmlBody = email_base_layout('Documento Enviado', $body);
            
            $smtp = new SmtpClient();
            $smtp->send($fromEmail, $fromName, $recipientEmail, 'Documento - ' . $fileName, $htmlBody);
            $sent = true;
        }
    } catch (Throwable $e) {
        error_log('[ADMIN_DOC_SEND] Erro ao enviar e-mail: ' . $e->getMessage());
    }
}

// Se é apenas disponibilizar no portal, considerar como enviado
if ($sendMethod === 'portal') {
    $sent = true;
}

// Registrar log
try {
    $logStmt = $db->prepare("
        INSERT INTO document_send_logs (document_id, document_source, recipient_type, recipient_id, recipient_email, health_insurer_id, send_method, sent_by_user_id, notes)
        VALUES (?, 'manual', ?, ?, ?, ?, ?, ?, ?)
    ");
    $logStmt->execute([
        $documentId,
        $recipientType,
        $recipientId,
        $recipientEmail,
        $healthInsurerId > 0 ? $healthInsurerId : null,
        $sendMethod,
        auth_user_id(),
        $notes ?: 'Envio manual - ' . $fileName
    ]);
} catch (Throwable $e) {
    error_log('[ADMIN_DOC_SEND] Erro ao registrar log: ' . $e->getMessage());
}

if ($sent) {
    $methodLabel = $sendMethod === 'email' ? 'por e-mail' : 'no portal';
    flash_set('success', "Documento enviado {$methodLabel} para {$recipientName}.");
} else {
    flash_set('warning', 'Documento salvo, mas não foi possível enviar por e-mail. Verifique o e-mail do destinatário.');
}

header('Location: /admin_documents_sent.php');
exit;
