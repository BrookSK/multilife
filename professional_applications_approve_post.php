<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = (int)($_POST['id'] ?? 0);

$db = db();

$stmt = $db->prepare('SELECT * FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}

if ((string)$pa['status'] === 'approved' && $pa['created_user_id'] !== null) {
    page_history_log(
        '/professional_applications_list.php',
        'Candidaturas',
        'approve',
        'Aprovou candidatura',
        'professional_application',
        $id
    );

    flash_set('success', 'Candidatura aprovada.');
    header('Location: /professional_applications_view.php?id=' . $id);
    exit;
}

// Gera senha provisória (stub: envio por WhatsApp/e-mail será no módulo de integrações)
$tmpPassword = substr(bin2hex(random_bytes(8)), 0, 12);
$hash = password_hash($tmpPassword, PASSWORD_BCRYPT);

$db->beginTransaction();
try {
    // Cria usuário
    $stmt = $db->prepare('INSERT INTO users (name, email, phone, password_hash, specialty, status) VALUES (:name, :email, :phone, :hash, :specialty, :status)');
    $stmt->execute([
        'name' => (string)$pa['full_name'],
        'email' => (string)$pa['email'],
        'phone' => (string)$pa['phone'],
        'hash' => $hash,
        'specialty' => (string)($pa['specialty'] ?? ''),
        'status' => 'active',
    ]);

    $userId = (int)$db->lastInsertId();

    // Vincula role profissional
    $stmt = $db->prepare("SELECT id FROM roles WHERE slug = 'profissional' LIMIT 1");
    $stmt->execute();
    $role = $stmt->fetch();
    if ($role) {
        $roleId = (int)$role['id'];
        $stmt = $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
        $stmt->execute(['uid' => $userId, 'rid' => $roleId]);
    }

    // Atualiza candidatura
    $stmt = $db->prepare('UPDATE professional_applications SET status = \'approved\', reviewed_by_user_id = :rid, reviewed_at = NOW(), created_user_id = :uid WHERE id = :id');
    $stmt->execute([
        'rid' => auth_user_id(),
        'uid' => $userId,
        'id' => $id,
    ]);

    // Enfileira onboarding (WhatsApp + e-mail) - executado via CRON integration_jobs_run
    $loginUrl = (string)admin_setting_get('app.login_url', '');
    if ($loginUrl === '') {
        $loginUrl = (isset($_SERVER['HTTP_HOST']) ? ('https://' . (string)$_SERVER['HTTP_HOST'] . '/login.php') : '/login.php');
    }
    $payload = [
        'application_id' => $id,
        'user_id' => $userId,
        'name' => (string)$pa['full_name'],
        'email' => (string)$pa['email'],
        'phone' => (string)$pa['phone'],
        'tmp_password' => $tmpPassword,
        'login_url' => $loginUrl,
    ];

    integration_job_enqueue('evolution', 'professional_onboarding_credentials', $payload, null);
    integration_job_enqueue('smtp', 'professional_onboarding_email', $payload, null);

    // Envio síncrono WhatsApp (onboarding com credenciais)
    $whatsappSent = false;
    $emailSent = false;
    $digits = preg_replace('/\D+/', '', (string)$pa['phone']);
    if ($digits !== '') {
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }
        try {
            $tplKey = 'professional.onboarding_whatsapp_template';
            $defaultTpl = "Olá {name}! 🎉\n\nSua candidatura foi aprovada!\n\nAcesse o sistema:\n{login_url}\n\nE-mail: {email}\nSenha provisória: {password}\n\nAltere sua senha no primeiro acesso.";
            $tpl = (string)admin_setting_get($tplKey, $defaultTpl);
            if (trim($tpl) === '') $tpl = $defaultTpl;
            $msg = strtr($tpl, [
                '{name}' => (string)$pa['full_name'],
                '{email}' => (string)$pa['email'],
                '{password}' => $tmpPassword,
                '{login_url}' => $loginUrl,
            ]);
            $api = new EvolutionApiV1();
            $res = $api->sendText($digits, $msg);
            $whatsappSent = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
        } catch (Throwable $e) {
            error_log('[SYNC_ONBOARD] WhatsApp falhou: ' . $e->getMessage());
        }
    }

    // Envio síncrono E-mail (onboarding com credenciais)
    $toEmail = trim((string)$pa['email']);
    if ($toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $subjectTpl = (string)admin_setting_get('professional.onboarding_email_subject_template', 'Bem-vindo(a) à MultiLife Care - Acesso aprovado');
                if (trim($subjectTpl) === '') $subjectTpl = 'Bem-vindo(a) à MultiLife Care - Acesso aprovado';
                $subject = strtr($subjectTpl, ['{name}' => (string)$pa['full_name'], '{email}' => (string)$pa['email'], '{login_url}' => $loginUrl]);

                $bodyTpl = (string)admin_setting_get('professional.onboarding_email_body_template', '');
                if (trim($bodyTpl) === '') {
                    $bodyTpl = "Olá {name},\n\nSua candidatura foi aprovada!\n\nAcesse o sistema:\n{login_url}\n\nE-mail: {email}\nSenha provisória: {password}\n\nAltere sua senha no primeiro acesso.\n\nAtenciosamente,\nEquipe MultiLife Care";
                }
                $body = strtr($bodyTpl, [
                    '{name}' => (string)$pa['full_name'],
                    '{email}' => (string)$pa['email'],
                    '{password}' => $tmpPassword,
                    '{login_url}' => $loginUrl,
                ]);

                $client = new SmtpClient();
                $client->send($fromEmail, $fromName, $toEmail, $subject, nl2br(htmlspecialchars($body)));
                $emailSent = true;
            }
        } catch (Throwable $e) {
            error_log('[SYNC_ONBOARD] E-mail falhou: ' . $e->getMessage());
        }
    }

    // Pendência de acompanhamento
    $stmt = $db->prepare(
        "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)"
        . " VALUES ('professional_onboarding','open',:title,:detail,'professional_applications',:rid,:uid)"
    );
    $stmt->execute([
        'title' => 'Onboarding profissional: ' . (string)$pa['full_name'],
        'detail' => 'Credenciais enfileiradas para envio (WhatsApp/e-mail).',
        'rid' => $id,
        'uid' => auth_user_id(),
    ]);

    audit_log('create', 'users_from_application', (string)$id, null, ['created_user_id' => $userId]);

    $db->commit();
    
    $notifStatus = [];
    if ($whatsappSent) $notifStatus[] = 'WhatsApp enviado ✓';
    else $notifStatus[] = 'WhatsApp enfileirado';
    if ($emailSent) $notifStatus[] = 'E-mail enviado ✓';
    else $notifStatus[] = 'E-mail enfileirado';
    
    flash_set('success', 'Aprovado. Credenciais: ' . implode(' | ', $notifStatus) . '.');
    header('Location: /professional_applications_view.php?id=' . $id);
    exit;
} catch (Throwable $e) {
    $db->rollBack();
    error_log('Erro ao aprovar candidatura ID ' . $id . ': ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    flash_set('error', 'Erro ao aprovar candidatura: ' . $e->getMessage());
    header('Location: /professional_applications_view.php?id=' . $id);
    exit;
}
