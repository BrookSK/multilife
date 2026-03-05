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
echo '<div style="font-size:22px;font-weight:900">Configurações do Admin</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Parâmetros operacionais (stub). Depois adicionamos mais chaves.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
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
    'professional.docs_expiry_notice_days' => 'Profissional - Avisar vencimento docs (dias antes)',
    'professional.required_doc_categories' => 'Profissional - Categorias obrigatórias (separadas por vírgula) para validar bloqueio',
    'professional.docs_reminder_days_before_due' => 'Profissional - Lembrete formulário (dias antes do prazo)',
    'professional.docs_reminder_whatsapp_template' => 'Profissional - Template WhatsApp lembrete (placeholders: {doc_id},{patient_ref},{due_at})',
    'professional.docs_overdue_whatsapp_template' => 'Profissional - Template WhatsApp cobrança atraso (placeholders: {doc_id},{patient_ref},{due_at},{days_overdue})',
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
];

echo '<section class="card col12">';
echo '<form method="post" action="/admin_settings_post.php" style="display:grid;gap:12px;max-width:720px">';
foreach ($fields as $key => $label) {
    $val = $settings[$key] ?? '';
    $isSensitive = in_array($key, ['cron.token', 'smtp.in.password', 'smtp.out.password'], true);
    $isTemplate = str_contains($key, 'template');
    if ($isSensitive) {
        echo '<label>' . h($label) . '<input type="password" name="settings[' . h($key) . ']" value="" placeholder="(mantém se vazio)"></label>';
    } elseif ($isTemplate) {
        echo '<label>' . h($label) . '<textarea name="settings[' . h($key) . ']" rows="4" placeholder="(configure)">' . h($val) . '</textarea></label>';
    } else {
        echo '<label>' . h($label) . '<input name="settings[' . h($key) . ']" value="' . h($val) . '"></label>';
    }
}

echo '<div style="display:flex;justify-content:flex-end">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
