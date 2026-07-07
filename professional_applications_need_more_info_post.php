<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = (int)($_POST['id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));

$stmt = db()->prepare('SELECT id, status FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}


if ($message === '') {
    flash_set('error', 'Informe a mensagem para o candidato.');
    header('Location: /professional_applications_need_more_info.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT * FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'UPDATE professional_applications '
        . 'SET status = \'need_more_info\', reviewed_by_user_id = :rid, reviewed_at = NOW(), admin_note = :note '
        . 'WHERE id = :id'
    );
    $note = 'Solicitação de complemento: ' . $message;
    $stmt->execute(['rid' => auth_user_id(), 'id' => $id, 'note' => $note]);

    // Pendência interna
    $stmt = $db->prepare(
        "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)"
        . " VALUES ('professional_application_followup','open',:title,:detail,'professional_applications',:rid,:uid)"
    );
    $stmt->execute([
        'title' => 'Candidatura: complemento solicitado (#' . $id . ')',
        'detail' => mb_strimwidth($message, 0, 240, '...'),
        'rid' => $id,
        'uid' => auth_user_id(),
    ]);

    audit_log('update', 'professional_applications', (string)$id, $old, ['status' => 'need_more_info', 'admin_note' => $note]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

// Notificações (WhatsApp + e-mail) — envio síncrono + job como backup
$payload = [
    'application_id' => $id,
    'kind' => 'need_more_info',
    'message' => $message,
];

// Buscar dados do candidato
$appStmt = db()->prepare('SELECT full_name, phone, email FROM professional_applications WHERE id = :id');
$appStmt->execute(['id' => $id]);
$appData = $appStmt->fetch();

$whatsappSent = false;
$emailSent = false;

// Envio síncrono WhatsApp
$whatsappError = '';
if ($appData) {
    $digits = preg_replace('/\D+/', '', (string)($appData['phone'] ?? ''));
    if ($digits !== '') {
        // Adicionar DDI 55 (Brasil) se não tiver
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }
        try {
            $tplKey = 'professional.application_need_more_info_whatsapp_template';
            $default = "Olá {name}!\n\nPrecisamos de complemento na sua candidatura:\n{message}\n\nApós enviar, retornaremos com a avaliação.";
            $tpl = (string)admin_setting_get($tplKey, $default);
            if (trim($tpl) === '') {
                $tpl = $default;
            }
            $msg = strtr($tpl, [
                '{name}' => (string)($appData['full_name'] ?? ''),
                '{message}' => $message,
                '{application_id}' => (string)$id,
            ]);
            if (trim($msg) === '') {
                $msg = "Olá " . (string)($appData['full_name'] ?? '') . "!\n\nPrecisamos de complemento na sua candidatura:\n" . $message;
            }
            $api = new EvolutionApiV1();
            $res = $api->sendText($digits, $msg);
            $whatsappSent = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if (!$whatsappSent) {
                $whatsappError = 'HTTP ' . ($res['status'] ?? '?') . ' - ' . json_encode($res['json'] ?? $res['body_raw'] ?? '');
                error_log('[SYNC_NOTIFY] WhatsApp erro: ' . $whatsappError);
            }
        } catch (Throwable $e) {
            $whatsappError = $e->getMessage();
            error_log('[SYNC_NOTIFY] WhatsApp exceção: ' . $whatsappError);
        }
    } else {
        $whatsappError = 'Telefone vazio';
    }

    // Envio síncrono E-mail
    $email = trim((string)($appData['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $subjectTpl = (string)admin_setting_get('professional.application_need_more_info_email_subject_template', 'Candidatura #{application_id} - Complemento necessário');
            $bodyTpl = (string)admin_setting_get('professional.application_need_more_info_email_body_template', "Olá {name},\n\nPrecisamos de complemento na sua candidatura:\n\n{message}\n\nApós enviar, retornaremos com a avaliação.\n\nAtenciosamente,\nEquipe Multilife");
            $subject = strtr($subjectTpl, ['{name}' => (string)($appData['full_name'] ?? ''), '{message}' => $message, '{application_id}' => (string)$id]);
            $body = strtr($bodyTpl, ['{name}' => (string)($appData['full_name'] ?? ''), '{message}' => $message, '{application_id}' => (string)$id]);
            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                require_once __DIR__ . '/app/email_html_generators.php';
                $htmlBody = function_exists('email_html_application_need_info')
                    ? email_html_application_need_info((string)($appData['full_name'] ?? ''), (string)$id, $message)
                    : nl2br(htmlspecialchars($body));
                $client = new SmtpClient();
                $client->send($fromEmail, $fromName, $email, $subject, $htmlBody);
                $emailSent = true;
            }
        } catch (Throwable $e) {
            error_log('[SYNC_NOTIFY] E-mail falhou: ' . $e->getMessage());
        }
    }
}

// Enfileirar como backup caso envio síncrono tenha falhado
if (!$whatsappSent) {
    integration_job_enqueue('evolution', 'professional_application_notify', $payload, null);
}
if (!$emailSent) {
    integration_job_enqueue('smtp', 'professional_application_notify_email', $payload, null);
}

$notifStatus = [];
if ($whatsappSent) $notifStatus[] = 'WhatsApp enviado ✓';
if ($emailSent) $notifStatus[] = 'E-mail enviado ✓';
if (!$whatsappSent) $notifStatus[] = 'WhatsApp falhou (' . ($whatsappError ?: 'enfileirado') . ')';
if (!$emailSent) $notifStatus[] = 'E-mail enfileirado';

flash_set('success', 'Complemento solicitado. ' . implode(' | ', $notifStatus) . '.');
header('Location: /professional_applications_view.php?id=' . $id);
exit;
