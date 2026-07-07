<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = (int)($_POST['id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));

$stmt = db()->prepare('SELECT id, status FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}


if ($reason === '') {
    flash_set('error', 'Informe o motivo da reprovação.');
    header('Location: /professional_applications_reject.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT * FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

$db = db();
$db->beginTransaction();
try {
    $note = 'Reprovada: ' . $reason;
    $stmt = $db->prepare('UPDATE professional_applications SET status = \'rejected\', reviewed_by_user_id = :rid, reviewed_at = NOW(), admin_note = :note WHERE id = :id');
    $stmt->execute(['rid' => auth_user_id(), 'id' => $id, 'note' => $note]);

    audit_log('update', 'professional_applications', (string)$id, $old, ['status' => 'rejected', 'admin_note' => $note]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

$payload = [
    'application_id' => $id,
    'kind' => 'rejected',
    'message' => $reason,
];

// Envio síncrono
$appStmt = db()->prepare('SELECT full_name, phone, email FROM professional_applications WHERE id = :id');
$appStmt->execute(['id' => $id]);
$appData = $appStmt->fetch();

$whatsappSent = false;
$emailSent = false;

if ($appData) {
    $digits = preg_replace('/\D+/', '', (string)($appData['phone'] ?? ''));
    if ($digits !== '') {
        // Adicionar DDI 55 (Brasil) se não tiver
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }
        try {
            $tplKey = 'professional.application_rejected_whatsapp_template';
            $default = "Olá {name}!\n\nSua candidatura não foi aprovada.\nMotivo:\n{message}\n\nVocê pode se candidatar novamente quando desejar.";
            $tpl = (string)admin_setting_get($tplKey, $default);
            $msg = strtr($tpl, [
                '{name}' => (string)($appData['full_name'] ?? ''),
                '{message}' => $reason,
                '{application_id}' => (string)$id,
            ]);
            $api = new EvolutionApiV1();
            $res = $api->sendText($digits, $msg);
            $whatsappSent = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
        } catch (Throwable $e) {
            error_log('[SYNC_NOTIFY] WhatsApp rejeição falhou: ' . $e->getMessage());
        }
    }

    $email = trim((string)($appData['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $subjectTpl = (string)admin_setting_get('professional.application_rejected_email_subject_template', 'Candidatura #{application_id} - Resultado');
            $bodyTpl = (string)admin_setting_get('professional.application_rejected_email_body_template', "Olá {name},\n\nSua candidatura não foi aprovada.\nMotivo:\n{message}\n\nVocê pode se candidatar novamente quando desejar.\n\nAtenciosamente,\nEquipe Multilife");
            $subject = strtr($subjectTpl, ['{name}' => (string)($appData['full_name'] ?? ''), '{application_id}' => (string)$id]);
            $body = strtr($bodyTpl, ['{name}' => (string)($appData['full_name'] ?? ''), '{message}' => $reason, '{application_id}' => (string)$id]);
            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                require_once __DIR__ . '/app/email_html_generators.php';
                $htmlBody = function_exists('email_html_application_rejected')
                    ? email_html_application_rejected((string)($appData['full_name'] ?? ''), (string)$id, $reason)
                    : nl2br(htmlspecialchars($body));
                $client = new SmtpClient();
                $client->send($fromEmail, $fromName, $email, $subject, $htmlBody);
                $emailSent = true;
            }
        } catch (Throwable $e) {
            error_log('[SYNC_NOTIFY] E-mail rejeição falhou: ' . $e->getMessage());
        }
    }
}

if (!$whatsappSent) {
    integration_job_enqueue('evolution', 'professional_application_notify', $payload, null);
}
if (!$emailSent) {
    integration_job_enqueue('smtp', 'professional_application_notify_email', $payload, null);
}

page_history_log(
    '/professional_applications_list.php',
    'Candidaturas',
    'reject',
    'Rejeitou candidatura',
    'professional_application',
    $id
);

$notifStatus = [];
if ($whatsappSent) $notifStatus[] = 'WhatsApp enviado';
if ($emailSent) $notifStatus[] = 'E-mail enviado';
if (!$whatsappSent) $notifStatus[] = 'WhatsApp enfileirado';
if (!$emailSent) $notifStatus[] = 'E-mail enfileirado';

flash_set('success', 'Candidatura rejeitada. ' . implode(', ', $notifStatus) . '.');
header('Location: /professional_applications_view.php?id=' . $id);
exit;
