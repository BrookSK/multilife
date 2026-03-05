<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($limit <= 0 || $limit > 200) {
    $limit = 20;
}

$now = (new DateTime('now'))->format('Y-m-d H:i:s');

$db = db();

$stmt = $db->prepare(
    "SELECT *
     FROM integration_jobs
     WHERE status IN ('pending','error')
       AND (next_run_at IS NULL OR next_run_at <= :now)
       AND attempts < max_attempts
     ORDER BY COALESCE(next_run_at, created_at) ASC, id ASC
     LIMIT $limit"
);
$stmt->execute(['now' => $now]);
$jobs = $stmt->fetchAll();

if (count($jobs) === 0) {
    echo "OK: no jobs\n";
    exit;
}

$runAt = (new DateTime('now'))->format('Y-m-d H:i:s');

$updRun = $db->prepare(
    "UPDATE integration_jobs
     SET status = :status,
         attempts = :attempts,
         last_error = :last_error,
         next_run_at = :next_run_at,
         last_run_at = :last_run_at
     WHERE id = :id"
);

$markDead = $db->prepare(
    "UPDATE integration_jobs
     SET status = 'dead',
         attempts = :attempts,
         last_error = :last_error,
         last_run_at = :last_run_at,
         next_run_at = NULL
     WHERE id = :id"
);

$success = 0;
$failed = 0;
$dead = 0;

foreach ($jobs as $j) {
    $id = (int)$j['id'];
    $provider = (string)$j['provider'];
    $action = (string)$j['action'];

    $attempts = (int)$j['attempts'];
    $maxAttempts = (int)$j['max_attempts'];

    $payloadRaw = $j['payload'];
    $payload = null;
    if (is_string($payloadRaw) && trim($payloadRaw) !== '') {
        $decoded = json_decode($payloadRaw, true);
        $payload = $decoded !== null ? $decoded : $payloadRaw;
    }

    try {
        // Marca como running
        $updRun->execute([
            'status' => 'running',
            'attempts' => $attempts,
            'last_error' => null,
            'next_run_at' => null,
            'last_run_at' => $runAt,
            'id' => $id,
        ]);

        if ($provider === 'evolution' && $action === 'patient_notify_appointment') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }
            $appointmentId = (int)($payload['appointment_id'] ?? 0);
            $patientId = (int)($payload['patient_id'] ?? 0);
            if ($appointmentId <= 0 || $patientId <= 0) {
                throw new RuntimeException('appointment_id/patient_id ausentes.');
            }

            $stmt = db()->prepare(
                'SELECT a.id, a.first_at, p.full_name AS patient_name, p.whatsapp, p.phone_primary, p.phone_secondary, u.name AS professional_name\n'
                . 'FROM appointments a\n'
                . 'INNER JOIN patients p ON p.id = a.patient_id\n'
                . 'INNER JOIN users u ON u.id = a.professional_user_id\n'
                . 'WHERE a.id = :id AND p.deleted_at IS NULL'
            );
            $stmt->execute(['id' => $appointmentId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Agendamento não encontrado.');
            }

            $phone = trim((string)($row['whatsapp'] ?? ''));
            if ($phone === '') {
                $phone = trim((string)($row['phone_primary'] ?? ''));
            }
            if ($phone === '') {
                $phone = trim((string)($row['phone_secondary'] ?? ''));
            }
            $digits = preg_replace('/\D+/', '', $phone);
            if ($digits === '') {
                throw new RuntimeException('Paciente sem telefone cadastrado.');
            }

            $tpl = (string)admin_setting_get(
                'appointments.patient_whatsapp_template',
                "Agendamento confirmado (# {appointment_id})\nPaciente: {patient_name}\nProfissional: {professional_name}\nData/hora: {first_at}\n\nConfirme/cancele: {feedback_url}"
            );

            $stmt = db()->prepare('SELECT token FROM appointment_patient_feedback WHERE appointment_id = :id LIMIT 1');
            $stmt->execute(['id' => $appointmentId]);
            $fb = $stmt->fetch();
            $fbToken = $fb ? (string)($fb['token'] ?? '') : '';
            $base = trim((string)admin_setting_get('app.public_base_url', ''));
            if ($base === '') {
                $base = trim((string)admin_setting_get('app.login_url', ''));
                if ($base !== '') {
                    $purl = parse_url($base);
                    if (is_array($purl) && isset($purl['scheme'], $purl['host'])) {
                        $port = isset($purl['port']) ? (':' . (string)$purl['port']) : '';
                        $base = (string)$purl['scheme'] . '://' . (string)$purl['host'] . $port;
                    }
                }
            }
            $feedbackUrl = ($base !== '' && $fbToken !== '') ? (rtrim($base, '/') . '/public/appointment_feedback.php?token=' . urlencode($fbToken)) : '';

            $msg = strtr($tpl, [
                '{appointment_id}' => (string)$appointmentId,
                '{patient_name}' => (string)($row['patient_name'] ?? ''),
                '{professional_name}' => (string)($row['professional_name'] ?? ''),
                '{first_at}' => (string)($row['first_at'] ?? ''),
                '{feedback_url}' => $feedbackUrl,
            ]);

            $api = new EvolutionApiV1();
            $res = $api->sendText($digits, $msg);
            $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if (!$ok) {
                throw new RuntimeException('Evolution HTTP ' . (string)($res['status'] ?? '')); 
            }

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, $payload, $res['json'] ?? $res, null, $attempts);
            continue;
        }

        if ($provider === 'evolution' && $action === 'professional_docs_approved_notify') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }
            $docId = (int)($payload['documentation_id'] ?? 0);
            if ($docId <= 0) {
                throw new RuntimeException('documentation_id ausente.');
            }

            $stmt = db()->prepare(
                'SELECT d.id, d.patient_ref, d.sessions_count, u.id AS professional_user_id, u.name AS professional_name, u.phone, pa.phone AS application_phone\n'
                . 'FROM professional_documentations d\n'
                . 'INNER JOIN users u ON u.id = d.professional_user_id\n'
                . 'LEFT JOIN professional_applications pa ON pa.created_user_id = u.id\n'
                . 'WHERE d.id = :id\n'
                . 'ORDER BY pa.id DESC\n'
                . 'LIMIT 1'
            );
            $stmt->execute(['id' => $docId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Documentação não encontrada.');
            }

            $phone = (string)($row['phone'] ?? '');
            if ($phone === '') {
                $phone = (string)($row['application_phone'] ?? '');
            }
            $digits = preg_replace('/\D+/', '', $phone);
            if ($digits === '') {
                throw new RuntimeException('Profissional sem telefone.');
            }

            $tpl = (string)admin_setting_get(
                'professional.docs_approved_whatsapp_template',
                "Formulário aprovado (Doc #{doc_id}).\nPaciente: {patient_ref}\nSessões: {sessions_count}"
            );
            $msg = strtr($tpl, [
                '{doc_id}' => (string)$docId,
                '{patient_ref}' => (string)($row['patient_ref'] ?? ''),
                '{sessions_count}' => (string)($row['sessions_count'] ?? ''),
                '{name}' => (string)($row['professional_name'] ?? ''),
            ]);

            $api = new EvolutionApiV1();
            $res = $api->sendText($digits, $msg);
            $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if (!$ok) {
                throw new RuntimeException('Evolution HTTP ' . (string)($res['status'] ?? ''));
            }

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, ['documentation_id' => $docId], $res['json'] ?? $res, null, $attempts);
            continue;
        }

        if ($provider === 'smtp' && $action === 'professional_docs_approved_email') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }
            $docId = (int)($payload['documentation_id'] ?? 0);
            if ($docId <= 0) {
                throw new RuntimeException('documentation_id ausente.');
            }

            $stmt = db()->prepare(
                'SELECT d.id, d.patient_ref, d.sessions_count, u.name AS professional_name, u.email AS professional_email\n'
                . 'FROM professional_documentations d\n'
                . 'INNER JOIN users u ON u.id = d.professional_user_id\n'
                . 'WHERE d.id = :id\n'
                . 'LIMIT 1'
            );
            $stmt->execute(['id' => $docId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Documentação não encontrada.');
            }

            $to = trim((string)($row['professional_email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Profissional sem e-mail válido.');
            }

            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('SMTP from_email não configurado/ inválido.');
            }

            $subjectTpl = (string)admin_setting_get(
                'professional.docs_approved_email_subject_template',
                'Formulário aprovado (Doc #{doc_id})'
            );
            $bodyTpl = (string)admin_setting_get(
                'professional.docs_approved_email_body_template',
                "Olá {name},\n\nSeu formulário foi aprovado.\n\nDoc #{doc_id}\nPaciente: {patient_ref}\nSessões: {sessions_count}\n\nAtenciosamente,\nMultiLife Care"
            );

            $repl = [
                '{name}' => (string)($row['professional_name'] ?? ''),
                '{doc_id}' => (string)$docId,
                '{patient_ref}' => (string)($row['patient_ref'] ?? ''),
                '{sessions_count}' => (string)($row['sessions_count'] ?? ''),
            ];

            $subject = strtr($subjectTpl, $repl);
            $body = strtr($bodyTpl, $repl);

            $client = new SmtpClient();
            $client->send($fromEmail, $fromName, $to, $subject, $body);

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, ['to' => $to, 'documentation_id' => $docId], null, null, $attempts);
            continue;
        }

        if ($provider === 'smtp' && $action === 'professional_doc_reminder_email') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }

            $docId = (int)($payload['doc_id'] ?? 0);
            $profUid = (int)($payload['professional_user_id'] ?? 0);
            $patientRef = (string)($payload['patient_ref'] ?? '');
            $dueAt = (string)($payload['due_at'] ?? '');
            $kind = (string)($payload['kind'] ?? 'overdue');
            $daysOverdue = (int)($payload['days_overdue'] ?? 0);

            if ($docId <= 0 || $profUid <= 0) {
                throw new RuntimeException('doc_id/professional_user_id ausentes.');
            }

            $stmt = db()->prepare('SELECT name, email FROM users WHERE id = :id AND status = \'active\' LIMIT 1');
            $stmt->execute(['id' => $profUid]);
            $u = $stmt->fetch();
            if (!$u) {
                throw new RuntimeException('Profissional não encontrado.');
            }

            $to = trim((string)($u['email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Profissional sem e-mail válido.');
            }

            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('SMTP from_email não configurado/ inválido.');
            }

            $subjectKey = ($kind === 'before_due')
                ? 'professional.docs_reminder_email_subject_template'
                : 'professional.docs_overdue_email_subject_template';
            $bodyKey = ($kind === 'before_due')
                ? 'professional.docs_reminder_email_body_template'
                : 'professional.docs_overdue_email_body_template';

            $subjectDefault = ($kind === 'before_due')
                ? 'Lembrete: formulário pendente (Doc #{doc_id})'
                : 'Atenção: formulário em atraso (Doc #{doc_id})';
            $bodyDefault = ($kind === 'before_due')
                ? "Olá {name},\n\nLembrete: você tem um formulário pendente (Doc #{doc_id}) para {patient_ref}.\nPrazo: {due_at}.\n\nAtenciosamente,\nMultiLife Care"
                : "Olá {name},\n\nAtenção: formulário atrasado (Doc #{doc_id}) para {patient_ref}.\nPrazo: {due_at}.\nAtraso: {days_overdue} dias.\n\nAtenciosamente,\nMultiLife Care";

            $repl = [
                '{name}' => (string)($u['name'] ?? ''),
                '{doc_id}' => (string)$docId,
                '{patient_ref}' => $patientRef,
                '{due_at}' => $dueAt,
                '{days_overdue}' => (string)$daysOverdue,
            ];

            $subjectTpl = (string)admin_setting_get($subjectKey, $subjectDefault);
            $bodyTpl = (string)admin_setting_get($bodyKey, $bodyDefault);
            $subject = strtr($subjectTpl, $repl);
            $body = strtr($bodyTpl, $repl);

            $client = new SmtpClient();
            $client->send($fromEmail, $fromName, $to, $subject, $body);

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, ['to' => $to, 'doc_id' => $docId, 'kind' => $kind], null, null, $attempts);
            continue;
        }

        if ($provider === 'evolution' && $action === 'professional_application_notify') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }

            $applicationId = (int)($payload['application_id'] ?? 0);
            $kind = (string)($payload['kind'] ?? '');
            $message = trim((string)($payload['message'] ?? ''));

            if ($applicationId <= 0 || !in_array($kind, ['need_more_info','rejected'], true)) {
                throw new RuntimeException('application_id/kind inválidos.');
            }

            $stmt = db()->prepare('SELECT full_name, phone FROM professional_applications WHERE id = :id');
            $stmt->execute(['id' => $applicationId]);
            $pa = $stmt->fetch();
            if (!$pa) {
                throw new RuntimeException('Candidatura não encontrada.');
            }

            $digits = preg_replace('/\D+/', '', (string)($pa['phone'] ?? ''));
            if ($digits === '') {
                throw new RuntimeException('Candidato sem telefone cadastrado.');
            }

            $tplKey = $kind === 'need_more_info'
                ? 'professional.application_need_more_info_whatsapp_template'
                : 'professional.application_rejected_whatsapp_template';

            $default = $kind === 'need_more_info'
                ? "Olá {name}!\n\nPrecisamos de complemento na sua candidatura:\n{message}\n\nApós enviar, retornaremos com a avaliação."
                : "Olá {name}!\n\nSua candidatura não foi aprovada.\nMotivo:\n{message}\n\nVocê pode se candidatar novamente quando desejar.";

            $tpl = (string)admin_setting_get($tplKey, $default);
            $msg = strtr($tpl, [
                '{name}' => (string)($pa['full_name'] ?? ''),
                '{message}' => $message,
                '{application_id}' => (string)$applicationId,
            ]);

            $api = new EvolutionApiV1();
            $res = $api->sendText($digits, $msg);
            $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if (!$ok) {
                throw new RuntimeException('Evolution HTTP ' . (string)($res['status'] ?? ''));
            }

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, ['application_id' => $applicationId, 'kind' => $kind], $res['json'] ?? $res, null, $attempts);
            continue;
        }

        if ($provider === 'smtp' && $action === 'professional_application_notify_email') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }

            $applicationId = (int)($payload['application_id'] ?? 0);
            $kind = (string)($payload['kind'] ?? '');
            $message = trim((string)($payload['message'] ?? ''));

            if ($applicationId <= 0 || !in_array($kind, ['need_more_info','rejected'], true)) {
                throw new RuntimeException('application_id/kind inválidos.');
            }

            $stmt = db()->prepare('SELECT full_name, email FROM professional_applications WHERE id = :id');
            $stmt->execute(['id' => $applicationId]);
            $pa = $stmt->fetch();
            if (!$pa) {
                throw new RuntimeException('Candidatura não encontrada.');
            }

            $to = trim((string)($pa['email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Candidato sem e-mail válido.');
            }

            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('SMTP from_email não configurado/ inválido.');
            }

            $subjectKey = $kind === 'need_more_info'
                ? 'professional.application_need_more_info_email_subject_template'
                : 'professional.application_rejected_email_subject_template';
            $bodyKey = $kind === 'need_more_info'
                ? 'professional.application_need_more_info_email_body_template'
                : 'professional.application_rejected_email_body_template';

            $subjectDefault = $kind === 'need_more_info'
                ? 'Complemento necessário na sua candidatura #{application_id}'
                : 'Retorno da sua candidatura #{application_id}';
            $bodyDefault = $kind === 'need_more_info'
                ? "Olá {name},\n\nPrecisamos de complemento na sua candidatura:\n\n{message}\n\nAtenciosamente,\nMultiLife Care"
                : "Olá {name},\n\nSua candidatura não foi aprovada.\n\nMotivo:\n{message}\n\nAtenciosamente,\nMultiLife Care";

            $repl = [
                '{name}' => (string)($pa['full_name'] ?? ''),
                '{message}' => $message,
                '{application_id}' => (string)$applicationId,
            ];

            $subjectTpl = (string)admin_setting_get($subjectKey, $subjectDefault);
            $bodyTpl = (string)admin_setting_get($bodyKey, $bodyDefault);
            $subject = strtr($subjectTpl, $repl);
            $body = strtr($bodyTpl, $repl);

            $client = new SmtpClient();
            $client->send($fromEmail, $fromName, $to, $subject, $body);

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, ['to' => $to, 'application_id' => $applicationId, 'kind' => $kind], null, null, $attempts);
            continue;
        }

        if ($provider === 'smtp' && $action === 'send_email_confirmation') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }

            $appointmentId = (int)($payload['appointment_id'] ?? 0);
            if ($appointmentId <= 0) {
                throw new RuntimeException('appointment_id ausente.');
            }

            $stmt = db()->prepare(
                'SELECT a.id, a.first_at, a.demand_id, p.full_name AS patient_name, p.email AS patient_email, u.name AS professional_name\n'
                . 'FROM appointments a\n'
                . 'INNER JOIN patients p ON p.id = a.patient_id\n'
                . 'INNER JOIN users u ON u.id = a.professional_user_id\n'
                . 'WHERE a.id = :id AND p.deleted_at IS NULL'
            );
            $stmt->execute(['id' => $appointmentId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Agendamento não encontrado.');
            }

            $originEmail = '';
            if ($row['demand_id'] !== null) {
                $stmt = db()->prepare('SELECT origin_email FROM demands WHERE id = :id');
                $stmt->execute(['id' => (int)$row['demand_id']]);
                $d = $stmt->fetch();
                if ($d && isset($d['origin_email'])) {
                    $originEmail = (string)$d['origin_email'];
                }
            }

            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('SMTP from_email não configurado/ inválido.');
            }

            $subjectTpl = (string)admin_setting_get(
                'appointments.email_subject_template',
                'Agendamento confirmado #{appointment_id}'
            );
            $bodyTpl = (string)admin_setting_get(
                'appointments.email_body_template',
                "Olá {patient_name},\n\nSeu agendamento foi confirmado.\n\nProfissional: {professional_name}\nData/hora: {first_at}\n\nAtenciosamente,\nMultiLife Care"
            );

            $repl = [
                '{appointment_id}' => (string)$appointmentId,
                '{patient_name}' => (string)($row['patient_name'] ?? ''),
                '{professional_name}' => (string)($row['professional_name'] ?? ''),
                '{first_at}' => (string)($row['first_at'] ?? ''),
            ];
            $subject = strtr($subjectTpl, $repl);
            $body = strtr($bodyTpl, $repl);

            $client = new SmtpClient();

            $sentTo = [];
            $patientEmail = trim((string)($row['patient_email'] ?? ''));
            if ($patientEmail !== '' && filter_var($patientEmail, FILTER_VALIDATE_EMAIL)) {
                $client->send($fromEmail, $fromName, $patientEmail, $subject, $body);
                $sentTo[] = $patientEmail;
            }
            $originEmail = trim($originEmail);
            if ($originEmail !== '' && filter_var($originEmail, FILTER_VALIDATE_EMAIL) && !in_array($originEmail, $sentTo, true)) {
                $client->send($fromEmail, $fromName, $originEmail, $subject, $body);
                $sentTo[] = $originEmail;
            }
            if (count($sentTo) === 0) {
                throw new RuntimeException('Sem e-mail válido para envio (paciente/origem).');
            }

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, $payload, ['sent_to' => $sentTo], null, $attempts);
            continue;
        }

        if ($provider === 'evolution' && $action === 'professional_onboarding_credentials') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }

            $name = trim((string)($payload['name'] ?? ''));
            $email = trim((string)($payload['email'] ?? ''));
            $phone = trim((string)($payload['phone'] ?? ''));
            $tmpPassword = (string)($payload['tmp_password'] ?? '');
            $loginUrl = trim((string)($payload['login_url'] ?? '/login.php'));

            $digits = preg_replace('/\D+/', '', $phone);
            if ($digits === '') {
                throw new RuntimeException('Profissional sem telefone cadastrado na candidatura.');
            }
            if ($tmpPassword === '') {
                throw new RuntimeException('tmp_password ausente.');
            }

            $tpl = (string)admin_setting_get(
                'professional.onboarding_whatsapp_template',
                "Olá {name}!\n\nSeu acesso ao MultiLife Care foi aprovado.\n\nLogin: {email}\nSenha provisória: {password}\nAcesso: {login_url}\n\nTroque sua senha após entrar."
            );
            $msg = strtr($tpl, [
                '{name}' => $name,
                '{email}' => $email,
                '{password}' => $tmpPassword,
                '{login_url}' => $loginUrl,
            ]);

            $api = new EvolutionApiV1();
            $res = $api->sendText($digits, $msg);
            $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if (!$ok) {
                throw new RuntimeException('Evolution HTTP ' . (string)($res['status'] ?? ''));
            }

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, ['application_id' => $payload['application_id'] ?? null], $res['json'] ?? $res, null, $attempts);
            continue;
        }

        if ($provider === 'smtp' && $action === 'professional_onboarding_email') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }

            $name = trim((string)($payload['name'] ?? ''));
            $email = trim((string)($payload['email'] ?? ''));
            $tmpPassword = (string)($payload['tmp_password'] ?? '');
            $loginUrl = trim((string)($payload['login_url'] ?? '/login.php'));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('E-mail inválido do profissional.');
            }
            if ($tmpPassword === '') {
                throw new RuntimeException('tmp_password ausente.');
            }

            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('SMTP from_email não configurado/ inválido.');
            }

            $subjectTpl = (string)admin_setting_get(
                'professional.onboarding_email_subject_template',
                'Acesso aprovado - MultiLife Care'
            );
            $bodyTpl = (string)admin_setting_get(
                'professional.onboarding_email_body_template',
                "Olá {name},\n\nSeu acesso ao MultiLife Care foi aprovado.\n\nLogin: {email}\nSenha provisória: {password}\nAcesso: {login_url}\n\nTroque sua senha após entrar."
            );
            $repl = [
                '{name}' => $name,
                '{email}' => $email,
                '{password}' => $tmpPassword,
                '{login_url}' => $loginUrl,
            ];
            $subject = strtr($subjectTpl, $repl);
            $body = strtr($bodyTpl, $repl);

            $client = new SmtpClient();
            $client->send($fromEmail, $fromName, $email, $subject, $body);

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, ['to' => $email], null, null, $attempts);
            continue;
        }

        if ($provider === 'evolution' && $action === 'professional_doc_reminder') {
            if (!is_array($payload)) {
                throw new RuntimeException('Payload inválido (esperado JSON).');
            }

            $docId = (int)($payload['doc_id'] ?? 0);
            $profUid = (int)($payload['professional_user_id'] ?? 0);
            $patientRef = (string)($payload['patient_ref'] ?? '');
            $dueAt = (string)($payload['due_at'] ?? '');
            $kind = (string)($payload['kind'] ?? 'overdue');
            $daysOverdue = (int)($payload['days_overdue'] ?? 0);

            if ($docId <= 0 || $profUid <= 0) {
                throw new RuntimeException('doc_id/professional_user_id ausentes.');
            }

            $stmt = db()->prepare(
                "SELECT u.phone, pa.phone AS application_phone\n"
                . "FROM users u\n"
                . "LEFT JOIN professional_applications pa ON pa.created_user_id = u.id\n"
                . "WHERE u.id = :uid\n"
                . "ORDER BY pa.id DESC\n"
                . "LIMIT 1"
            );
            $stmt->execute(['uid' => $profUid]);
            $row = $stmt->fetch();
            $phone = $row ? (string)($row['phone'] ?? '') : '';
            if ($phone === '' && $row) {
                $phone = (string)($row['application_phone'] ?? '');
            }
            $digits = preg_replace('/\D+/', '', $phone);
            if ($digits === '') {
                throw new RuntimeException('Não foi possível obter telefone do profissional (candidatura vinculada).');
            }

            $tplKey = ($kind === 'before_due') ? 'professional.docs_reminder_whatsapp_template' : 'professional.docs_overdue_whatsapp_template';
            $default = ($kind === 'before_due')
                ? "Lembrete: você tem um formulário pendente (Doc #{doc_id}) para {patient_ref}. Prazo: {due_at}."
                : "Atenção: formulário atrasado (Doc #{doc_id}) para {patient_ref}. Prazo: {due_at}. Atraso: {days_overdue} dias.";

            $tpl = (string)admin_setting_get($tplKey, $default);
            $msg = strtr($tpl, [
                '{doc_id}' => (string)$docId,
                '{patient_ref}' => $patientRef,
                '{due_at}' => $dueAt,
                '{days_overdue}' => (string)$daysOverdue,
            ]);

            $api = new EvolutionApiV1();
            $res = $api->sendText($digits, $msg);
            $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            if (!$ok) {
                throw new RuntimeException('Evolution HTTP ' . (string)($res['status'] ?? ''));
            }

            $updRun->execute([
                'status' => 'success',
                'attempts' => $attempts,
                'last_error' => null,
                'next_run_at' => null,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $success++;
            integration_log($provider, 'job ' . $action, 'success', null, ['doc_id' => $docId], $res['json'] ?? $res, null, $attempts);
            continue;
        }

        throw new RuntimeException('Unknown job action: ' . $provider . ' / ' . $action);
    } catch (Throwable $e) {
        $attempts++;
        $err = mb_strimwidth($e->getMessage(), 0, 255, '');

        if ($attempts >= $maxAttempts) {
            // Após 3 tentativas, marca como dead (Módulo 12.1)
            $markDead->execute([
                'attempts' => $attempts,
                'last_error' => $err,
                'last_run_at' => $runAt,
                'id' => $id,
            ]);
            $dead++;
            integration_log($provider, 'job ' . $action, 'error', null, $payload, null, 'DEAD: ' . $err, $attempts);
            continue;
        }

        // Retry exponencial: 2^attempts minutos (Módulo 12.1)
        $delayMinutes = pow(2, $attempts);
        $nextRun = (new DateTime('now'))->modify("+{$delayMinutes} minutes")->format('Y-m-d H:i:s');

        $updRun->execute([
            'status' => 'error',
            'attempts' => $attempts,
            'last_error' => $err,
            'next_run_at' => $nextRun,
            'last_run_at' => $runAt,
            'id' => $id,
        ]);

        $failed++;
        integration_log($provider, 'job ' . $action, 'error', null, $payload, null, $err, $attempts);
        continue;
}

echo 'OK: success=' . $success . ' failed=' . $failed . ' dead=' . $dead . "\n";
