<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$db = db();

$logId = (int)($_POST['log_id'] ?? 0);
$resendNotes = trim((string)($_POST['resend_notes'] ?? ''));

if ($logId <= 0) {
    flash_set('error', 'Registro de envio não identificado.');
    header('Location: /admin_documents_sent.php');
    exit;
}

// Buscar o registro original
$stmt = $db->prepare("SELECT * FROM document_send_logs WHERE id = ?");
$stmt->execute([$logId]);
$original = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$original) {
    flash_set('error', 'Registro de envio não encontrado.');
    header('Location: /admin_documents_sent.php');
    exit;
}

$recipientType = (string)($original['recipient_type'] ?? 'professional');
$recipientId = (int)($original['recipient_id'] ?? 0);
$filePath = (string)($original['file_path'] ?? '');
$fileName = (string)($original['file_name'] ?? '');
$sendMethod = (string)($original['send_method'] ?? 'email');
$healthInsurerId = $original['health_insurer_id'] ?? null;
$assignmentId = $original['assignment_id'] ?? null;

if ($filePath === '') {
    flash_set('error', 'Documento sem arquivo associado. Não é possível reenviar.');
    header('Location: /admin_documents_sent.php');
    exit;
}

// Buscar dados atualizados do destinatário
$recipientEmail = '';
$recipientName = '';
try {
    if ($recipientType === 'professional') {
        $s = $db->prepare("SELECT name, email FROM users WHERE id = ?");
        $s->execute([$recipientId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $recipientEmail = (string)($row['email'] ?? '');
        $recipientName = (string)($row['name'] ?? '');
    } else {
        $s = $db->prepare("SELECT full_name, email FROM patients WHERE id = ?");
        $s->execute([$recipientId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        $recipientEmail = (string)($row['email'] ?? '');
        $recipientName = (string)($row['full_name'] ?? '');
    }
} catch (Throwable $e) {}

$sendStatus = 'enviado';

// Enviar por e-mail
if ($sendMethod === 'email' && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        require_once __DIR__ . '/app/email_base_template.php';
        $baseUrl = rtrim((string)admin_setting_get('app.base_url', 'https://multilife.onsolutionsbrasil.com.br'), '/');

        $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($recipientName) . '</strong>!</p>';
        $body .= '<p style="font-size:14px;color:#4b5563">Segue reenvio de documento pela equipe MultiLife Care:</p>';
        $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
        $icon = preg_match('/\.pdf$/i', $fileName) ? '📄' : '📎';
        $body .= '<p style="margin:0">' . $icon . ' <a href="' . $baseUrl . htmlspecialchars($filePath) . '" style="color:#0284c7;text-decoration:underline">' . htmlspecialchars($fileName) . '</a></p>';
        if ($resendNotes !== '') {
            $body .= '<p style="margin:8px 0 0;font-size:13px;color:#6b7280">' . nl2br(htmlspecialchars($resendNotes)) . '</p>';
        }
        $body .= '</div>';
        $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';

        $htmlBody = email_base_layout('Documento Reenviado', $body);
        $smtp = new SmtpClient();
        $smtp->send(
            (string)admin_setting_get('smtp.out.from_email', ''),
            (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care'),
            $recipientEmail,
            'Documento (Reenvio) - ' . $fileName,
            $htmlBody
        );
        $sendStatus = 'entregue';
    } catch (Throwable $e) {
        $sendStatus = 'falha';
        error_log('[RESEND_DOC] Erro ao enviar e-mail: ' . $e->getMessage());
        flash_set('error', 'Falha ao reenviar por e-mail: ' . $e->getMessage());
        header('Location: /admin_documents_sent.php');
        exit;
    }
} elseif ($sendMethod === 'portal') {
    $sendStatus = 'enviado';
} else {
    // Sem e-mail válido
    if ($sendMethod === 'email') {
        flash_set('error', 'Destinatário sem e-mail válido. Não é possível reenviar por e-mail.');
        header('Location: /admin_documents_sent.php');
        exit;
    }
}

// Registrar o reenvio no histórico
try {
    $notes = $resendNotes !== '' ? 'Reenvio: ' . $resendNotes : 'Reenvio do documento';
    $db->prepare("INSERT INTO document_send_logs (document_id, document_source, recipient_type, recipient_id, recipient_email, recipient_name, assignment_id, health_insurer_id, send_method, sent_by_user_id, file_name, file_path, notes, send_status, send_action, resent_from_log_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $original['document_id'] ?? 0,
            $original['document_source'] ?? 'manual',
            $recipientType,
            $recipientId,
            $recipientEmail,
            $recipientName,
            $assignmentId,
            $healthInsurerId,
            $sendMethod,
            auth_user_id(),
            $fileName,
            $filePath,
            $notes,
            $sendStatus,
            'reenvio',
            $logId,
        ]);
} catch (Throwable $e) {
    error_log('[RESEND_DOC] Erro ao registrar log: ' . $e->getMessage());
}

flash_set('success', 'Documento reenviado com sucesso para ' . ($recipientName ?: $recipientEmail) . '!');
header('Location: /admin_documents_sent.php');
exit;
