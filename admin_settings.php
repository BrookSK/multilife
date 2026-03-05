<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$stmt = db()->query('SELECT setting_key, setting_value FROM admin_settings ORDER BY setting_key ASC');
$rows = $stmt->fetchAll();

$settings = [];
foreach ($rows as $r) {
    $settings[(string)$r['setting_key']] = (string)($r['setting_value'] ?? '');
}

view_header('Configurações');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Configurações do Sistema</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Parâmetros operacionais, integrações e valores.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/specialties_list.php">Gerenciar Especialidades</a>';
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

$fields = [
    'docs.reminder_days_before_due' => 'Dias antes para lembrete de formulário',
    'finance.repasse_cycle_days' => 'Ciclo de repasse (dias)',
    'demands.assume_timeout_hours' => 'Timeout para assumir demanda (horas)',
    'chat.unanswered_timeout_minutes' => 'Chat - Tempo sem resposta (min) para gerar pendência',
    'demands.whatsapp_template' => 'Captação - Template WhatsApp (placeholders: {id},{title},{city},{state},{specialty},{description},{origin})',
    'appointments.patient_whatsapp_template' => 'Agendamento - Template WhatsApp para paciente (placeholders: {appointment_id},{patient_name},{professional_name},{first_at})',
    'appointments.email_subject_template' => 'Agendamento - Template assunto e-mail (placeholders: {appointment_id},{patient_name},{professional_name},{first_at})',
    'appointments.email_body_template' => 'Agendamento - Template corpo e-mail (placeholders: {appointment_id},{patient_name},{professional_name},{first_at})',
    'professional.onboarding_whatsapp_template' => 'Profissional - Onboarding WhatsApp (placeholders: {name},{email},{password},{login_url})',
    'professional.onboarding_email_subject_template' => 'Profissional - Onboarding e-mail assunto (placeholders: {name},{email},{password},{login_url})',
    'professional.onboarding_email_body_template' => 'Profissional - Onboarding e-mail corpo (placeholders: {name},{email},{password},{login_url})',

    'professional.application_need_more_info_whatsapp_template' => 'Candidatura - Complemento WhatsApp (placeholders: {name},{message},{application_id})',
    'professional.application_need_more_info_email_subject_template' => 'Candidatura - Complemento e-mail assunto (placeholders: {name},{application_id})',
    'professional.application_need_more_info_email_body_template' => 'Candidatura - Complemento e-mail corpo (placeholders: {name},{message},{application_id})',
    'professional.application_rejected_whatsapp_template' => 'Candidatura - Reprovada WhatsApp (placeholders: {name},{message},{application_id})',
    'professional.application_rejected_email_subject_template' => 'Candidatura - Reprovada e-mail assunto (placeholders: {name},{application_id})',
    'professional.application_rejected_email_body_template' => 'Candidatura - Reprovada e-mail corpo (placeholders: {name},{message},{application_id})',
    'professional.docs_expiry_notice_days' => 'Profissional - Avisar vencimento docs (dias antes)',
    'professional.required_doc_categories' => 'Profissional - Categorias obrigatórias (separadas por vírgula) para validar bloqueio',
    'professional.docs_reminder_days_before_due' => 'Profissional - Lembrete formulário (dias antes do prazo)',
    'professional.docs_reminder_whatsapp_template' => 'Profissional - Template WhatsApp lembrete (placeholders: {doc_id},{patient_ref},{due_at})',
    'professional.docs_overdue_whatsapp_template' => 'Profissional - Template WhatsApp cobrança atraso (placeholders: {doc_id},{patient_ref},{due_at},{days_overdue})',

    'professional.docs_reminder_email_subject_template' => 'Profissional - Lembrete e-mail assunto (placeholders: {doc_id},{patient_ref},{due_at})',
    'professional.docs_reminder_email_body_template' => 'Profissional - Lembrete e-mail corpo (placeholders: {name},{doc_id},{patient_ref},{due_at})',
    'professional.docs_overdue_email_subject_template' => 'Profissional - Cobrança atraso e-mail assunto (placeholders: {doc_id},{patient_ref},{due_at},{days_overdue})',
    'professional.docs_overdue_email_body_template' => 'Profissional - Cobrança atraso e-mail corpo (placeholders: {name},{doc_id},{patient_ref},{due_at},{days_overdue})',

    'professional.docs_approved_whatsapp_template' => 'Profissional - Confirmação aprovação WhatsApp (placeholders: {name},{doc_id},{patient_ref},{sessions_count})',
    'professional.docs_approved_email_subject_template' => 'Profissional - Confirmação aprovação e-mail assunto (placeholders: {name},{doc_id},{patient_ref},{sessions_count})',
    'professional.docs_approved_email_body_template' => 'Profissional - Confirmação aprovação e-mail corpo (placeholders: {name},{doc_id},{patient_ref},{sessions_count})',
    'app.public_base_url' => 'App - Base URL pública (ex: https://suaurl.com) para links enviados via WhatsApp',
    'app.session_lifetime_seconds' => 'Sessão expira após (segundos)',
    'cron.token' => 'Token do CRON (segredo)',

    'smtp.in.host' => 'SMTP/IMAP Entrada - Host',
    'smtp.in.port' => 'SMTP/IMAP Entrada - Porta',
    'smtp.in.encryption' => 'SMTP/IMAP Entrada - Encryption (ssl/tls/none)',
    'smtp.in.username' => 'SMTP/IMAP Entrada - Usuário',
    'smtp.in.password' => 'SMTP/IMAP Entrada - Senha',
    'smtp.in.mailbox' => 'SMTP/IMAP Entrada - Mailbox (ex: INBOX)',
    'smtp.in.archive_mailbox' => 'SMTP/IMAP Entrada - Arquivar em (Mailbox)',
    'smtp.in.poll_minutes' => 'SMTP/IMAP Entrada - Intervalo (min)',
    'smtp.demands.to_address' => 'Endereço de demandas (ex: demandas@multilife.sistema)',

    'smtp.out.host' => 'SMTP Saída - Host',
    'smtp.out.port' => 'SMTP Saída - Porta',
    'smtp.out.encryption' => 'SMTP Saída - Encryption (ssl/tls/none)',
    'smtp.out.username' => 'SMTP Saída - Usuário',
    'smtp.out.password' => 'SMTP Saída - Senha',
    'smtp.out.from_email' => 'SMTP Saída - From e-mail',
    'smtp.out.from_name' => 'SMTP Saída - From nome',

    'openai.base_url' => 'OpenAI - Base URL',
    'openai.api_key' => 'OpenAI - API Key',
    'openai.model' => 'OpenAI - Model',
    'openai.extract_prompt' => 'OpenAI - Prompt extração (e-mail → demanda)',

    'evolution.base_url' => 'Evolution - Base URL',
    'evolution.api_key' => 'Evolution - API Key',
    'evolution.instance' => 'Evolution - Instance',
];

echo '<section class="card col12">';
echo '<form method="post" action="/admin_settings_post.php" style="max-width:980px">';

$sections = [
    'Operacional' => ['docs.reminder_days_before_due', 'finance.repasse_cycle_days', 'demands.assume_timeout_hours', 'chat.unanswered_timeout_minutes', 'professional.docs_expiry_notice_days', 'professional.required_doc_categories', 'professional.docs_reminder_days_before_due', 'app.session_lifetime_seconds', 'cron.token', 'app.public_base_url'],
    'SMTP Entrada (IMAP)' => ['smtp.in.host', 'smtp.in.port', 'smtp.in.encryption', 'smtp.in.username', 'smtp.in.password', 'smtp.in.mailbox', 'smtp.in.archive_mailbox', 'smtp.in.poll_minutes', 'smtp.demands.to_address'],
    'SMTP Saída' => ['smtp.out.host', 'smtp.out.port', 'smtp.out.encryption', 'smtp.out.username', 'smtp.out.password', 'smtp.out.from_email', 'smtp.out.from_name'],
    'OpenAI API' => ['openai.base_url', 'openai.api_key', 'openai.model', 'openai.extract_prompt'],
    'Evolution API' => ['evolution.base_url', 'evolution.api_key', 'evolution.instance'],
    'ZapSign API' => ['zapsign.base_url', 'zapsign.api_token'],
    'Templates WhatsApp' => ['demands.whatsapp_template', 'appointments.patient_whatsapp_template', 'professional.onboarding_whatsapp_template', 'professional.application_need_more_info_whatsapp_template', 'professional.application_rejected_whatsapp_template', 'professional.docs_reminder_whatsapp_template', 'professional.docs_overdue_whatsapp_template', 'professional.docs_approved_whatsapp_template'],
    'Templates E-mail' => ['appointments.email_subject_template', 'appointments.email_body_template', 'professional.onboarding_email_subject_template', 'professional.onboarding_email_body_template', 'professional.application_need_more_info_email_subject_template', 'professional.application_need_more_info_email_body_template', 'professional.application_rejected_email_subject_template', 'professional.application_rejected_email_body_template', 'professional.docs_reminder_email_subject_template', 'professional.docs_reminder_email_body_template', 'professional.docs_overdue_email_subject_template', 'professional.docs_overdue_email_body_template', 'professional.docs_approved_email_subject_template', 'professional.docs_approved_email_body_template'],
];

$fieldsAdded = ['zapsign.base_url' => 'ZapSign - Base URL', 'zapsign.api_token' => 'ZapSign - API Token'];
$fields = array_merge($fields, $fieldsAdded);

foreach ($sections as $sectionTitle => $sectionKeys) {
    echo '<div class="formSection">';
    echo '<div class="formSectionTitle">' . h($sectionTitle) . '</div>';
    echo '<div style="display:grid;gap:12px">';
    
    foreach ($sectionKeys as $key) {
        if (!isset($fields[$key])) continue;
        $label = $fields[$key];
        $val = $settings[$key] ?? '';
        $isSensitive = in_array($key, ['cron.token', 'smtp.in.password', 'smtp.out.password', 'openai.api_key', 'evolution.api_key', 'zapsign.api_token'], true);
        $isTemplate = str_contains($key, 'template') || $key === 'openai.extract_prompt';
        
        if ($isSensitive) {
            echo '<label>' . h($label) . '<input type="password" name="settings[' . h($key) . ']" value="" placeholder="(mantém se vazio)"><span class="helpText">Deixe vazio para manter o valor atual</span></label>';
        } elseif ($isTemplate) {
            echo '<label>' . h($label) . '<textarea name="settings[' . h($key) . ']" rows="4" placeholder="(configure)">' . h($val) . '</textarea></label>';
        } else {
            echo '<label>' . h($label) . '<input name="settings[' . h($key) . ']" value="' . h($val) . '"></label>';
        }
    }
    
    echo '</div>';
    echo '</div>';
}

echo '<div style="display:flex;justify-content:flex-end;margin-top:6px">';
echo '<button class="btn btnPrimary" type="submit">Salvar Configurações</button>';
echo '</div>';
echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
