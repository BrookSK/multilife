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
echo '<a class="btn" href="/admin_dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

$fields = [
    'app.logo_url' => 'Logo do Sistema - Sidebar (URL da imagem - ex: /uploads/logo.png)',
    'app.login_logo_url' => 'Logo da Tela de Login (URL da imagem - ex: /uploads/login_logo.png)',
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

    'smtp.in.host' => 'IMAP Recebimento - Host (servidor de e-mail)',
    'smtp.in.port' => 'IMAP Recebimento - Porta (993 para SSL, 143 para TLS)',
    'smtp.in.encryption' => 'IMAP Recebimento - Criptografia (ssl/tls/none)',
    'smtp.in.username' => 'IMAP Recebimento - Usuário (e-mail completo)',
    'smtp.in.password' => 'IMAP Recebimento - Senha',
    'smtp.in.mailbox' => 'IMAP Recebimento - Caixa de entrada (INBOX)',
    'smtp.in.archive_mailbox' => 'IMAP Recebimento - Arquivar em (INBOX.Archive)',
    'smtp.in.poll_minutes' => 'IMAP Recebimento - Verificar a cada (minutos)',
    'smtp.demands.to_address' => 'Endereço de demandas (ex: demandas@multilife.sistema)',

    'smtp.out.host' => 'SMTP Envio - Host (servidor de e-mail)',
    'smtp.out.port' => 'SMTP Envio - Porta (465 para SSL, 587 para TLS)',
    'smtp.out.encryption' => 'SMTP Envio - Criptografia (ssl/tls/none)',
    'smtp.out.username' => 'SMTP Envio - Usuário (e-mail completo)',
    'smtp.out.password' => 'SMTP Envio - Senha',
    'smtp.out.from_email' => 'SMTP Envio - Remetente (e-mail)',
    'smtp.out.from_name' => 'SMTP Envio - Remetente (nome)',

    'openai.base_url' => 'OpenAI - Base URL',
    'openai.api_key' => 'OpenAI - API Key',
    'openai.model' => 'OpenAI - Model',
    'openai.extract_prompt' => 'OpenAI - Prompt extração (e-mail → demanda)',

    'evolution.base_url' => 'Evolution - Base URL',
    'evolution.api_key' => 'Evolution - API Key',
    'evolution.instance' => 'Evolution - Instance',
];

$fieldsAdded = ['zapsign.base_url' => 'ZapSign - Base URL', 'zapsign.api_token' => 'ZapSign - API Token'];
$fields = array_merge($fields, $fieldsAdded);

echo '<section class="card col12">';
echo '<style>';
echo '.configTabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;border-bottom:2px solid hsl(var(--border));padding-bottom:8px}';
echo '.configTab{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:8px 8px 0 0;background:transparent;border:none;cursor:pointer;font-size:14px;font-weight:600;color:hsl(var(--muted-foreground));transition:all .15s ease}';
echo '.configTab:hover{background:hsla(var(--primary)/.05);color:hsl(var(--foreground))}';
echo '.configTab.isActive{background:hsl(var(--primary));color:hsl(var(--primary-foreground));box-shadow:0 2px 4px rgba(0,0,0,.1)}';
echo '.configTab svg{width:16px;height:16px;flex-shrink:0}';
echo '.configPanel{display:none}';
echo '.configPanel.isActive{display:block}';
echo '</style>';

$sections = [
    'Aparência' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'keys' => ['app.logo_url', 'app.login_logo_url', '_upload_logos_']
    ],
    'Especialidades' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        'keys' => ['_specialties_']
    ],
    'Operadoras' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'keys' => ['_health_insurers_']
    ],
    'Centros de Custo' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'keys' => ['_cost_centers_']
    ],
    'Operacional' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"/></svg>',
        'keys' => ['docs.reminder_days_before_due', 'finance.repasse_cycle_days', 'demands.assume_timeout_hours', 'chat.unanswered_timeout_minutes', 'professional.docs_expiry_notice_days', 'professional.required_doc_categories', 'professional.docs_reminder_days_before_due', 'app.session_lifetime_seconds', 'cron.token', 'app.public_base_url']
    ],
    'E-mail Recebimento (IMAP)' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'keys' => ['smtp.in.host', 'smtp.in.port', 'smtp.in.encryption', 'smtp.in.username', 'smtp.in.password', 'smtp.in.mailbox', 'smtp.in.archive_mailbox', 'smtp.in.poll_minutes', 'smtp.demands.to_address']
    ],
    'E-mail Envio (SMTP)' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        'keys' => ['smtp.out.host', 'smtp.out.port', 'smtp.out.encryption', 'smtp.out.username', 'smtp.out.password', 'smtp.out.from_email', 'smtp.out.from_name']
    ],
    'OpenAI' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'keys' => ['openai.base_url', 'openai.api_key', 'openai.model', 'openai.extract_prompt']
    ],
    'Evolution' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>',
        'keys' => ['evolution.base_url', 'evolution.api_key', 'evolution.instance', '_evolution_manage_']
    ],
    'ZapSign' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'keys' => ['_zapsign_manage_']
    ],
    'WhatsApp' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
        'keys' => ['demands.whatsapp_template', 'appointments.patient_whatsapp_template', 'professional.onboarding_whatsapp_template', 'professional.application_need_more_info_whatsapp_template', 'professional.application_rejected_whatsapp_template', 'professional.docs_reminder_whatsapp_template', 'professional.docs_overdue_whatsapp_template', 'professional.docs_approved_whatsapp_template']
    ],
    'E-mail' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'keys' => ['appointments.email_subject_template', 'appointments.email_body_template', 'professional.onboarding_email_subject_template', 'professional.onboarding_email_body_template', 'professional.application_need_more_info_email_subject_template', 'professional.application_need_more_info_email_body_template', 'professional.application_rejected_email_subject_template', 'professional.application_rejected_email_body_template', 'professional.docs_reminder_email_subject_template', 'professional.docs_reminder_email_body_template', 'professional.docs_overdue_email_subject_template', 'professional.docs_overdue_email_body_template', 'professional.docs_approved_email_subject_template', 'professional.docs_approved_email_body_template']
    ],
    'Funções' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg>',
        'keys' => ['_roles_manage_']
    ],
    'WhatsApp Instâncias' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>',
        'keys' => ['_whatsapp_instances_']
    ],
    'WhatsApp Console' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
        'keys' => ['_whatsapp_console_']
    ],
    'Logs Técnicos' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>',
        'keys' => ['_logs_technical_']
    ],
    'Ajuda' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'keys' => ['_help_']
    ],
];

echo '<div class="configTabs">';
$idx = 0;
foreach ($sections as $sectionTitle => $sectionData) {
    $isActive = $idx === 0 ? 'isActive' : '';
    echo '<button type="button" class="configTab ' . $isActive . '" data-tab="tab' . $idx . '">';
    echo $sectionData['icon'];
    echo '<span>' . h($sectionTitle) . '</span>';
    echo '</button>';
    $idx++;
}
echo '</div>';

echo '<form method="post" action="/admin_settings_post.php">';

$idx = 0;
foreach ($sections as $sectionTitle => $sectionData) {
    $isActive = $idx === 0 ? 'isActive' : '';
    echo '<div class="configPanel ' . $isActive . '" id="tab' . $idx . '">';
    
    // Aba especial de Aparência
    if ($sectionTitle === 'Aparência') {
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Aparência do Sistema</div>';
        echo '<div style="display:grid;gap:16px">';
        
        // Logo do Sistema (Sidebar)
        $logoUrl = $settings['app.logo_url'] ?? '';
        echo '<div>';
        echo '<label style="font-weight:600;margin-bottom:8px;display:block">Logo do Sistema - Sidebar</label>';
        if (!empty($logoUrl)) {
            echo '<div style="margin-bottom:8px">';
            echo '<img src="' . h($logoUrl) . '" alt="Logo atual" style="max-height:60px;border:1px solid hsl(var(--border));border-radius:8px;padding:8px">';
            echo '</div>';
        }
        echo '<a class="btn btnPrimary" href="/admin_logo_upload.php?type=system" style="font-size:13px">Upload Logo Sidebar</a>';
        echo '<div style="margin-top:6px;font-size:12px;color:hsl(var(--muted-foreground))">Dimensões ideais: 280px × 70px (PNG transparente)</div>';
        echo '</div>';
        
        // Logo da Tela de Login
        $loginLogoUrl = $settings['app.login_logo_url'] ?? '';
        echo '<div>';
        echo '<label style="font-weight:600;margin-bottom:8px;display:block">Logo da Tela de Login</label>';
        if (!empty($loginLogoUrl)) {
            echo '<div style="margin-bottom:8px">';
            echo '<img src="' . h($loginLogoUrl) . '" alt="Logo login atual" style="max-height:60px;border:1px solid hsl(var(--border));border-radius:8px;padding:8px">';
            echo '</div>';
        }
        echo '<a class="btn btnPrimary" href="/admin_logo_upload.php?type=login" style="font-size:13px">Upload Logo Login</a>';
        echo '<div style="margin-top:6px;font-size:12px;color:hsl(var(--muted-foreground))">Dimensões ideais: 280px × 70px (PNG transparente)</div>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
    } elseif ($sectionTitle === 'Especialidades') {
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle" style="display:flex;align-items:center;justify-content:space-between">';
        echo '<span>Especialidades</span>';
        echo '<a class="btn btnPrimary" href="/specialties_create.php" style="font-size:12px;padding:6px 12px">Nova Especialidade</a>';
        echo '</div>';
        
        // Buscar especialidades
        $specStmt = db()->query('SELECT id, name, status FROM specialties ORDER BY name ASC');
        $specialties = $specStmt->fetchAll();
        
        if (count($specialties) === 0) {
            echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhuma especialidade cadastrada</div>';
        } else {
            echo '<div style="display:grid;gap:8px;margin-top:12px">';
            foreach ($specialties as $spec) {
                $statusColor = $spec['status'] === 'active' ? 'hsl(var(--primary))' : 'hsl(var(--muted-foreground))';
                $statusText = $spec['status'] === 'active' ? 'Ativa' : 'Inativa';
                echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
                echo '<div>';
                echo '<strong>' . h((string)$spec['name']) . '</strong>';
                echo '<span style="margin-left:10px;font-size:12px;color:' . $statusColor . '">' . $statusText . '</span>';
                echo '</div>';
                echo '<div style="display:flex;gap:8px">';
                echo '<a href="/specialty_services_final.php?id=' . (int)$spec['id'] . '" class="btn btnPrimary" style="font-size:12px;padding:6px 10px">⚙️ Serviços</a>';
                echo '<a href="/specialties_edit.php?id=' . (int)$spec['id'] . '" class="btn" style="font-size:12px;padding:6px 10px">Editar</a>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    } elseif ($sectionTitle === 'Operadoras') {
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle" style="display:flex;align-items:center;justify-content:space-between">';
        echo '<span>Operadoras de Saúde</span>';
        echo '<a class="btn btnPrimary" href="/health_insurers_config.php" style="font-size:12px;padding:6px 12px">Gerenciar Operadoras</a>';
        echo '</div>';
        
        // Buscar operadoras
        $insurersStmt = db()->query('SELECT id, name, is_active FROM health_insurers ORDER BY name ASC');
        $insurers = $insurersStmt->fetchAll();
        
        if (count($insurers) === 0) {
            echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhuma operadora cadastrada</div>';
        } else {
            echo '<div style="display:grid;gap:8px;margin-top:12px">';
            foreach ($insurers as $ins) {
                $statusColor = $ins['is_active'] ? 'hsl(var(--primary))' : 'hsl(var(--muted-foreground))';
                $statusText = $ins['is_active'] ? 'Ativa' : 'Inativa';
                echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
                echo '<div>';
                echo '<strong>' . h((string)$ins['name']) . '</strong>';
                echo '<span style="margin-left:10px;font-size:12px;color:' . $statusColor . '">' . $statusText . '</span>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '<div style="margin-top:16px;padding:12px;background:hsla(var(--info)/.08);border-radius:8px;font-size:13px;color:hsl(var(--muted-foreground))">';
        echo '💡 <strong>Dica:</strong> As operadoras são usadas para identificar convênios nos atendimentos. Clique em "Gerenciar Operadoras" para adicionar, editar ou desativar.';
        echo '</div>';
        
        echo '</div>';
    } elseif ($sectionTitle === 'Centros de Custo') {
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle" style="display:flex;align-items:center;justify-content:space-between">';
        echo '<span>Centros de Custo</span>';
        echo '<a class="btn btnPrimary" href="/cost_centers_config.php" style="font-size:12px;padding:6px 12px">Gerenciar Centros de Custo</a>';
        echo '</div>';
        
        // Buscar centros de custo
        $costCentersStmt = db()->query('SELECT id, name, color, is_active FROM cost_centers ORDER BY name ASC');
        $costCenters = $costCentersStmt->fetchAll();
        
        if (count($costCenters) === 0) {
            echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum centro de custo cadastrado</div>';
        } else {
            echo '<div style="display:grid;gap:8px;margin-top:12px">';
            foreach ($costCenters as $cc) {
                $statusColor = $cc['is_active'] ? 'hsl(var(--primary))' : 'hsl(var(--muted-foreground))';
                $statusText = $cc['is_active'] ? 'Ativo' : 'Inativo';
                echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
                echo '<div style="display:flex;align-items:center;gap:10px">';
                echo '<div style="width:16px;height:16px;border-radius:4px;background:' . h((string)$cc['color']) . '"></div>';
                echo '<strong>' . h((string)$cc['name']) . '</strong>';
                echo '<span style="margin-left:10px;font-size:12px;color:' . $statusColor . '">' . $statusText . '</span>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '<div style="margin-top:16px;padding:12px;background:hsla(var(--info)/.08);border-radius:8px;font-size:13px;color:hsl(var(--muted-foreground))">';
        echo '💡 <strong>Dica:</strong> Os centros de custo são usados para organizar e categorizar receitas e despesas. Clique em "Gerenciar Centros de Custo" para adicionar, editar ou desativar.';
        echo '</div>';
        
        echo '</div>';
    } elseif ($sectionTitle === 'Funções') {
        // Aba Funções - Conteúdo completo
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Gerenciamento de Funções (Roles)</div>';
        
        echo '<div style="padding:16px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
        echo 'Funções definem o que cada usuário pode acessar no sistema. Cada função tem um conjunto de permissões específicas.';
        echo '</div>';
        echo '</div>';
        
        $qRoles = isset($_GET['q_roles']) ? trim((string)$_GET['q_roles']) : '';
        
        $sqlRoles = 'SELECT id, name, slug, created_at FROM roles';
        $paramsRoles = [];
        if ($qRoles !== '') {
            $sqlRoles .= ' WHERE name LIKE :q OR slug LIKE :q';
            $paramsRoles['q'] = '%' . $qRoles . '%';
        }
        $sqlRoles .= ' ORDER BY id DESC';
        
        $stmtRoles = db()->prepare($sqlRoles);
        $stmtRoles->execute($paramsRoles);
        $rowsRoles = $stmtRoles->fetchAll();
        
        // Formulário de busca
        echo '<div style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap">';
        echo '<input name="q_roles" value="' . h($qRoles) . '" placeholder="Buscar por nome ou slug" style="flex:1;min-width:220px">';
        echo '<button class="btn" type="submit" formmethod="get" formaction="/admin_settings.php">Buscar</button>';
        echo '</div>';
        
        // Formulário de criar nova função
        echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-weight:700;font-size:16px;margin-bottom:12px">Criar Nova Função</div>';
        
        echo '<div style="display:grid;gap:12px">';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
        echo '<label>Nome da Função *<input name="name" required placeholder="ex: Financeiro"></label>';
        echo '<label>Slug (identificador) *<input name="slug" required placeholder="ex: financeiro" pattern="[a-z0-9_-]+" title="Apenas letras minúsculas, números, hífen e underscore"></label>';
        echo '</div>';
        echo '<label>Descrição (opcional)<textarea name="description" rows="2" placeholder="Descrição da função"></textarea></label>';
        echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
        echo '<button class="btn btnPrimary" type="submit" formaction="/roles_create_post.php">Criar Função</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Listagem de funções
        echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '<div style="font-weight:700;font-size:16px;margin-bottom:12px">Funções Cadastradas</div>';
        
        if (count($rowsRoles) > 0) {
            echo '<div style="overflow:auto">';
            echo '<table style="width:100%;border-collapse:collapse">';
            echo '<thead><tr style="background:hsl(var(--muted));border-bottom:2px solid hsl(var(--border))">';
            echo '<th style="padding:12px;text-align:left">ID</th>';
            echo '<th style="padding:12px;text-align:left">Nome</th>';
            echo '<th style="padding:12px;text-align:left">Slug</th>';
            echo '<th style="padding:12px;text-align:left">Criado</th>';
            echo '<th style="padding:12px;text-align:right">Ações</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($rowsRoles as $rRole) {
                echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
                echo '<td style="padding:12px">' . (int)$rRole['id'] . '</td>';
                echo '<td style="padding:12px;font-weight:700">' . h((string)$rRole['name']) . '</td>';
                echo '<td style="padding:12px"><code>' . h((string)$rRole['slug']) . '</code></td>';
                echo '<td style="padding:12px;font-size:13px">' . h((string)$rRole['created_at']) . '</td>';
                echo '<td style="padding:12px;text-align:right">';
                echo '<div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">';
                echo '<a class="btn btnSmall" href="/roles_edit.php?id=' . (int)$rRole['id'] . '" target="_blank">Editar</a> ';
                echo '<a class="btn btnSmall btnPrimary" href="/roles_permissions_edit.php?id=' . (int)$rRole['id'] . '" target="_blank">Permissões</a> ';
                echo '<form method="post" action="/roles_delete_post.php" style="display:inline" onsubmit="return confirm(\'Excluir função ' . h((string)$rRole['name']) . '?\')">';
                echo '<input type="hidden" name="id" value="' . (int)$rRole['id'] . '">';
                echo '<button class="btn btnSmall btnDanger" type="submit">Excluir</button>';
                echo '</form>';
                echo '</div>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:8px">';
            echo '<div style="font-size:48px;margin-bottom:12px">👥</div>';
            echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhuma função encontrada</div>';
            echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Crie uma nova função acima.</div>';
            echo '</div>';
        }
        
        echo '</div>';
        echo '</div>';
    } elseif ($sectionTitle === 'WhatsApp Instâncias') {
        
        // Aba WhatsApp Instâncias - QR Code para Conexão
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Conectar WhatsApp</div>';
        
        $instanceNameQR = admin_setting_get('evolution.instance', '');
        $baseUrlQR = admin_setting_get('evolution.base_url', '');
        $apiKeyQR = admin_setting_get('evolution.api_key', '');
        
        if (empty($baseUrlQR) || empty($apiKeyQR) || empty($instanceNameQR)) {
            echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">';
            echo 'Configure as credenciais da Evolution API e a instância padrão na aba "Evolution" primeiro.';
            echo '</div>';
        } else {
            echo '<div style="margin-bottom:16px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
            echo 'Clique no botão abaixo para gerar o QR Code e conectar a instância <strong>' . h($instanceNameQR) . '</strong>.';
            echo '</div>';
            
            echo '<div style="text-align:center;padding:20px">';
            echo '<button class="btn btnPrimary" onclick="loadQRCode()" style="margin-bottom:20px">Gerar QR Code</button>';
            echo '<div id="qrcode-container" style="display:inline-block;padding:20px;background:white;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.1);display:none">';
            echo '<div style="color:#666;padding:40px">Carregando QR Code...</div>';
            echo '</div>';
            echo '<div id="status-message" style="margin-top:20px;font-size:14px;color:hsl(var(--muted-foreground))"></div>';
            echo '</div>';
            
            echo '<script>';
            echo 'let checkInterval;';
            echo 'function loadQRCode(){';
            echo '  document.getElementById("qrcode-container").style.display = "inline-block";';
            echo '  console.log("Iniciando carregamento do QR Code...");';
            echo '  fetch("/evolution_proxy.php?action=connect&instance=' . urlencode($instanceNameQR) . '")';
            echo '  .then(r => {';
            echo '    console.log("Resposta recebida, status:", r.status);';
            echo '    return r.json();';
            echo '  })';
            echo '  .then(data => {';
            echo '    console.log("Dados recebidos:", data);';
            echo '    const container = document.getElementById("qrcode-container");';
            echo '    if(data.instance && data.instance.state === "open"){';
            echo '      console.log("Instância já está conectada!");';
            echo '      container.innerHTML = \'<div style="color:hsl(142,76%,36%);padding:40px"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><div style="margin-top:16px;font-size:18px;font-weight:700">WhatsApp Conectado!</div></div>\';';
            echo '      document.getElementById("status-message").innerHTML = \'<span style="color:hsl(142,76%,36%);font-weight:600">A instância "\' + data.instance.instanceName + \'" já está conectada e ativa.</span>\';';
            echo '      return;';
            echo '    }';
            echo '    if(data.base64){';
            echo '      console.log("QR Code base64 encontrado");';
            echo '      container.innerHTML = \'<img src="\' + data.base64 + \'" alt="QR Code" style="max-width:300px;width:100%">\';';
            echo '      document.getElementById("status-message").innerHTML = "Escaneie o QR Code com seu WhatsApp";';
            echo '      startStatusCheck();';
            echo '    } else if(data.qrcode && data.qrcode.base64){';
            echo '      console.log("QR Code em data.qrcode.base64");';
            echo '      container.innerHTML = \'<img src="\' + data.qrcode.base64 + \'" alt="QR Code" style="max-width:300px;width:100%">\';';
            echo '      document.getElementById("status-message").innerHTML = "Escaneie o QR Code com seu WhatsApp";';
            echo '      startStatusCheck();';
            echo '    } else if(data.qrcode && data.qrcode.code){';
            echo '      console.log("QR Code em data.qrcode.code");';
            echo '      container.innerHTML = \'<img src="\' + data.qrcode.code + \'" alt="QR Code" style="max-width:300px;width:100%">\';';
            echo '      document.getElementById("status-message").innerHTML = "Escaneie o QR Code com seu WhatsApp";';
            echo '      startStatusCheck();';
            echo '    } else if(data.error){';
            echo '      console.error("Erro da API:", data.error);';
            echo '      let errorMsg = data.error;';
            echo '      if(data.url) errorMsg += "<br><small>URL: " + data.url + "</small>";';
            echo '      if(data.response) errorMsg += "<br><small>Resposta: " + data.response + "</small>";';
            echo '      container.innerHTML = \'<div style="color:red;padding:20px">\' + errorMsg + \'</div>\';';
            echo '    } else {';
            echo '      console.error("Resposta inesperada:", data);';
            echo '      container.innerHTML = \'<div style="color:orange;padding:20px">Resposta da API em formato inesperado.<br><small>Verifique o console para detalhes.</small></div>\';';
            echo '    }';
            echo '  })';
            echo '  .catch(e => {';
            echo '    console.error("Erro ao carregar QR Code:", e);';
            echo '    document.getElementById("qrcode-container").innerHTML = \'<div style="color:red;padding:20px">Erro ao carregar QR Code: \' + e.message + \'</div>\';';
            echo '  });';
            echo '}';
            echo 'function checkStatus(){';
            echo '  fetch("/evolution_proxy.php?action=status&instance=' . urlencode($instanceNameQR) . '")';
            echo '  .then(r => r.json())';
            echo '  .then(data => {';
            echo '    if(data.state === "open"){';
            echo '      clearInterval(checkInterval);';
            echo '      document.getElementById("status-message").innerHTML = \'<span style="color:hsl(142,76%,36%);font-weight:600">✓ Conectado com sucesso!</span>\';';
            echo '      setTimeout(() => location.reload(), 2000);';
            echo '    }';
            echo '  });';
            echo '}';
            echo 'function startStatusCheck(){';
            echo '  checkInterval = setInterval(checkStatus, 3000);';
            echo '}';
            echo '</script>';
        }
        
        echo '</div>';
    } elseif ($sectionTitle === 'WhatsApp Console') {
        // Aba WhatsApp Console - Logs de Mensagens e Arquivos
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Logs de Mensagens e Arquivos WhatsApp</div>';
        
        echo '<div style="padding:16px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
        echo 'Registro completo de todas as movimentações de mensagens e arquivos via Evolution API (GET e POST).';
        echo '</div>';
        echo '</div>';
        
        // Filtros
        $filterMethod = isset($_GET['method']) ? trim((string)$_GET['method']) : '';
        $filterAction = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
        $filterDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
        
        echo '<form method="get" action="/admin_settings.php" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap">';
        echo '<select name="method" style="min-width:120px">';
        echo '<option value="">Todos Métodos</option>';
        echo '<option value="GET"' . ($filterMethod === 'GET' ? ' selected' : '') . '>GET</option>';
        echo '<option value="POST"' . ($filterMethod === 'POST' ? ' selected' : '') . '>POST</option>';
        echo '</select>';
        echo '<input name="action" value="' . h($filterAction) . '" placeholder="Ação (ex: sendText, sendMedia)" style="flex:1;min-width:200px">';
        echo '<input type="date" name="date" value="' . h($filterDate) . '" style="min-width:150px">';
        echo '<button class="btn" type="submit">Filtrar</button>';
        echo '</form>';
        
        // Buscar logs
        $sqlLogs = 'SELECT id, method, url, request_body, response_body, status_code, created_at 
                    FROM tech_logs 
                    WHERE provider = :provider';
        $paramsLogs = ['provider' => 'evolution'];
        
        if ($filterMethod !== '') {
            $sqlLogs .= ' AND method = :method';
            $paramsLogs['method'] = $filterMethod;
        }
        
        if ($filterAction !== '') {
            $sqlLogs .= ' AND url LIKE :action';
            $paramsLogs['action'] = '%' . $filterAction . '%';
        }
        
        if ($filterDate !== '') {
            $sqlLogs .= ' AND DATE(created_at) = :date';
            $paramsLogs['date'] = $filterDate;
        }
        
        $sqlLogs .= ' ORDER BY id DESC LIMIT 100';
        
        try {
            $stmtLogs = db()->prepare($sqlLogs);
            $stmtLogs->execute($paramsLogs);
            $logsWC = $stmtLogs->fetchAll();
        } catch (PDOException $e) {
            $logsWC = [];
        }
        
        if (count($logsWC) > 0) {
            echo '<div style="overflow:auto">';
            echo '<table style="width:100%;border-collapse:collapse">';
            echo '<thead><tr style="background:hsl(var(--muted));border-bottom:2px solid hsl(var(--border))">';
            echo '<th style="padding:12px;text-align:left">ID</th>';
            echo '<th style="padding:12px;text-align:left">Método</th>';
            echo '<th style="padding:12px;text-align:left">URL/Ação</th>';
            echo '<th style="padding:12px;text-align:left">Status</th>';
            echo '<th style="padding:12px;text-align:left">Request</th>';
            echo '<th style="padding:12px;text-align:left">Response</th>';
            echo '<th style="padding:12px;text-align:left">Data/Hora</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($logsWC as $log) {
                $statusColor = ((int)$log['status_code'] >= 200 && (int)$log['status_code'] < 300) ? '#10b981' : '#ef4444';
                
                echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
                echo '<td style="padding:12px">' . (int)$log['id'] . '</td>';
                echo '<td style="padding:12px"><span style="padding:4px 8px;background:#3b82f6;color:white;border-radius:4px;font-size:11px;font-weight:600">' . h((string)$log['method']) . '</span></td>';
                echo '<td style="padding:12px;font-size:12px;max-width:250px;overflow:hidden;text-overflow:ellipsis">' . h((string)$log['url']) . '</td>';
                echo '<td style="padding:12px"><span style="padding:4px 8px;background:' . $statusColor . ';color:white;border-radius:4px;font-size:11px;font-weight:600">' . h((string)$log['status_code']) . '</span></td>';
                echo '<td style="padding:12px;font-size:11px;max-width:200px">';
                echo '<details><summary style="cursor:pointer;color:hsl(var(--primary))">Ver Request</summary>';
                echo '<pre style="margin-top:8px;padding:8px;background:#f9fafb;border-radius:4px;font-size:10px;overflow:auto;max-height:200px">' . h((string)$log['request_body']) . '</pre>';
                echo '</details>';
                echo '</td>';
                echo '<td style="padding:12px;font-size:11px;max-width:200px">';
                echo '<details><summary style="cursor:pointer;color:hsl(var(--primary))">Ver Response</summary>';
                echo '<pre style="margin-top:8px;padding:8px;background:#f9fafb;border-radius:4px;font-size:10px;overflow:auto;max-height:200px">' . h((string)$log['response_body']) . '</pre>';
                echo '</details>';
                echo '</td>';
                echo '<td style="padding:12px;font-size:12px">' . h((string)$log['created_at']) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:8px">';
            echo '<div style="font-size:48px;margin-bottom:12px">📋</div>';
            echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum log encontrado</div>';
            echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Os logs de mensagens e arquivos aparecerão aqui.</div>';
            echo '</div>';
        }
        
        echo '</div>';
    } elseif ($sectionTitle === 'Jobs') {
        // Aba Jobs - Conteúdo completo
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Fila de Jobs de Integração</div>';
        
        echo '<div style="padding:16px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
        echo 'Fila para retentativas (até 3x) e reprocessamento manual.';
        echo '</div>';
        echo '</div>';
        
        $statusJobs = isset($_GET['status']) ? (string)$_GET['status'] : 'pending';
        $providerJobs = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';
        
        $allowedJobs = ['pending','running','success','error','dead',''];
        if (!in_array($statusJobs, $allowedJobs, true)) {
            $statusJobs = 'pending';
        }
        
        $sqlJobs = 'SELECT id, provider, action, status, attempts, max_attempts, next_run_at, last_run_at, last_error, created_at
                FROM integration_jobs
                WHERE 1=1';
        $paramsJobs = [];
        
        if ($statusJobs !== '') {
            $sqlJobs .= ' AND status = :s';
            $paramsJobs['s'] = $statusJobs;
        }
        
        if ($providerJobs !== '') {
            $sqlJobs .= ' AND provider LIKE :p';
            $paramsJobs['p'] = '%' . $providerJobs . '%';
        }
        
        $sqlJobs .= ' ORDER BY id DESC LIMIT 500';
        
        $stmtJobs = db()->prepare($sqlJobs);
        $stmtJobs->execute($paramsJobs);
        $rowsJobs = $stmtJobs->fetchAll();
        
        echo '<form method="get" action="/admin_settings.php" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap">';
        echo '<select name="status" style="min-width:220px">';
        $labelsJobs = [
            '' => 'Todos',
            'pending' => 'pending',
            'running' => 'running',
            'success' => 'success',
            'error' => 'error',
            'dead' => 'dead',
        ];
        foreach ($labelsJobs as $kJobs => $labelJobs) {
            $selJobs = ($statusJobs === $kJobs) ? ' selected' : '';
            echo '<option value="' . h($kJobs) . '"' . $selJobs . '>' . h($labelJobs) . '</option>';
        }
        echo '</select>';
        echo '<input name="provider" value="' . h($providerJobs) . '" placeholder="Provider" style="flex:1;min-width:220px">';
        echo '<button class="btn" type="submit">Filtrar</button>';
        echo '</form>';
        
        if (count($rowsJobs) > 0) {
            echo '<div style="overflow:auto">';
            echo '<table style="width:100%;border-collapse:collapse">';
            echo '<thead><tr style="background:hsl(var(--muted));border-bottom:2px solid hsl(var(--border))">';
            echo '<th style="padding:12px;text-align:left">ID</th>';
            echo '<th style="padding:12px;text-align:left">Provider</th>';
            echo '<th style="padding:12px;text-align:left">Ação</th>';
            echo '<th style="padding:12px;text-align:left">Status</th>';
            echo '<th style="padding:12px;text-align:left">Tentativas</th>';
            echo '<th style="padding:12px;text-align:left">Próx. execução</th>';
            echo '<th style="padding:12px;text-align:left">Erro</th>';
            echo '<th style="padding:12px;text-align:right">Ações</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($rowsJobs as $rJobs) {
                $statusColorJobs = $rJobs['status'] === 'success' ? '#10b981' : ($rJobs['status'] === 'error' || $rJobs['status'] === 'dead' ? '#ef4444' : ($rJobs['status'] === 'running' ? '#3b82f6' : '#f59e0b'));
                
                echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
                echo '<td style="padding:12px">' . (int)$rJobs['id'] . '</td>';
                echo '<td style="padding:12px;font-weight:700">' . h((string)$rJobs['provider']) . '</td>';
                echo '<td style="padding:12px">' . h((string)$rJobs['action']) . '</td>';
                echo '<td style="padding:12px"><span style="padding:4px 8px;background:' . $statusColorJobs . ';color:white;border-radius:4px;font-size:12px;font-weight:600">' . h((string)$rJobs['status']) . '</span></td>';
                echo '<td style="padding:12px">' . h((string)$rJobs['attempts']) . '/' . h((string)$rJobs['max_attempts']) . '</td>';
                echo '<td style="padding:12px;font-size:13px">' . h((string)($rJobs['next_run_at'] ?? '')) . '</td>';
                echo '<td style="padding:12px;font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis">' . h(mb_strimwidth((string)($rJobs['last_error'] ?? ''), 0, 80, '...')) . '</td>';
                echo '<td style="padding:12px;text-align:right">';
                echo '<a class="btn btnSmall" href="/integration_jobs_view.php?id=' . (int)$rJobs['id'] . '" target="_blank">Abrir</a> ';
                echo '<form method="post" action="/integration_jobs_run_post.php" style="display:inline">';
                echo '<input type="hidden" name="id" value="' . (int)$rJobs['id'] . '">';
                echo '<button class="btn btnSmall btnPrimary" type="submit">Rodar</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:8px">';
            echo '<div style="font-size:48px;margin-bottom:12px">⏱️</div>';
            echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum job encontrado</div>';
            echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Altere o filtro para ver outros jobs.</div>';
            echo '</div>';
        }
        
        echo '</div>';
    } elseif ($sectionTitle === 'Logs Técnicos') {
        // Aba Logs Técnicos - Conteúdo completo
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Logs Técnicos de Integrações</div>';
        
        echo '<div style="padding:16px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
        echo 'Integrações (OpenAI/Evolution/ZapSign/SMTP/Webhooks).';
        echo '</div>';
        echo '</div>';
        
        $providerLogs = isset($_GET['provider']) ? trim((string)$_GET['provider']) : '';
        $statusLogs = isset($_GET['status']) ? (string)$_GET['status'] : '';
        
        if (!in_array($statusLogs, ['', 'success', 'error'], true)) {
            $statusLogs = '';
        }
        
        $sqlLogs = 'SELECT id, provider, action, status, http_status, error_message, attempts, created_at
                FROM integration_logs
                WHERE 1=1';
        $paramsLogs = [];
        
        if ($providerLogs !== '') {
            $sqlLogs .= ' AND provider LIKE :p';
            $paramsLogs['p'] = '%' . $providerLogs . '%';
        }
        
        if ($statusLogs !== '') {
            $sqlLogs .= ' AND status = :s';
            $paramsLogs['s'] = $statusLogs;
        }
        
        $sqlLogs .= ' ORDER BY id DESC LIMIT 500';
        
        $stmtLogs = db()->prepare($sqlLogs);
        $stmtLogs->execute($paramsLogs);
        $rowsLogs = $stmtLogs->fetchAll();
        
        echo '<form method="get" action="/admin_settings.php" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap">';
        echo '<input name="provider" value="' . h($providerLogs) . '" placeholder="Provider" style="flex:1;min-width:220px">';
        echo '<select name="status" style="min-width:200px">';
        echo '<option value=""' . ($statusLogs === '' ? ' selected' : '') . '>Todos</option>';
        echo '<option value="success"' . ($statusLogs === 'success' ? ' selected' : '') . '>success</option>';
        echo '<option value="error"' . ($statusLogs === 'error' ? ' selected' : '') . '>error</option>';
        echo '</select>';
        echo '<button class="btn" type="submit">Filtrar</button>';
        echo '</form>';
        
        if (count($rowsLogs) > 0) {
            echo '<div style="overflow:auto">';
            echo '<table style="width:100%;border-collapse:collapse">';
            echo '<thead><tr style="background:hsl(var(--muted));border-bottom:2px solid hsl(var(--border))">';
            echo '<th style="padding:12px;text-align:left">ID</th>';
            echo '<th style="padding:12px;text-align:left">Provider</th>';
            echo '<th style="padding:12px;text-align:left">Ação</th>';
            echo '<th style="padding:12px;text-align:left">Status</th>';
            echo '<th style="padding:12px;text-align:left">HTTP</th>';
            echo '<th style="padding:12px;text-align:left">Tentativas</th>';
            echo '<th style="padding:12px;text-align:left">Erro</th>';
            echo '<th style="padding:12px;text-align:left">Quando</th>';
            echo '<th style="padding:12px;text-align:right">Ações</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($rowsLogs as $rLogs) {
                $statusColorLogs = $rLogs['status'] === 'success' ? '#10b981' : '#ef4444';
                
                echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
                echo '<td style="padding:12px">' . (int)$rLogs['id'] . '</td>';
                echo '<td style="padding:12px;font-weight:700">' . h((string)$rLogs['provider']) . '</td>';
                echo '<td style="padding:12px">' . h((string)$rLogs['action']) . '</td>';
                echo '<td style="padding:12px"><span style="padding:4px 8px;background:' . $statusColorLogs . ';color:white;border-radius:4px;font-size:12px;font-weight:600">' . h((string)$rLogs['status']) . '</span></td>';
                echo '<td style="padding:12px">' . h((string)($rLogs['http_status'] ?? '')) . '</td>';
                echo '<td style="padding:12px">' . h((string)$rLogs['attempts']) . '</td>';
                echo '<td style="padding:12px;font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis">' . h(mb_strimwidth((string)($rLogs['error_message'] ?? ''), 0, 80, '...')) . '</td>';
                echo '<td style="padding:12px;font-size:13px">' . h((string)$rLogs['created_at']) . '</td>';
                echo '<td style="padding:12px;text-align:right">';
                echo '<a class="btn btnSmall" href="/tech_logs_view.php?id=' . (int)$rLogs['id'] . '" target="_blank">Abrir</a>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:8px">';
            echo '<div style="font-size:48px;margin-bottom:12px">📋</div>';
            echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum log encontrado</div>';
            echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Altere o filtro para ver outros logs.</div>';
            echo '</div>';
        }
        
        echo '</div>';
    } elseif ($sectionTitle === 'Ajuda') {
        // Aba especial de Ajuda
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Como Funciona o Sistema</div>';
        echo '<div style="margin-top:12px">';
        
        $helpTopics = [
            '📋 1. CAPTAÇÃO DE DEMANDAS' => [
                '🎯 O QUE É' => 'Módulo responsável por receber, organizar e distribuir solicitações de atendimento para profissionais de saúde.',
                
                '📥 PASSO 1: Recebimento' => 'AÇÃO: E-mail chega na caixa configurada em SMTP Entrada. RESULTADO: Sistema cria automaticamente um card na coluna "Recebimento de E-mail" com status "aguardando_captacao". TESTE: Envie e-mail para o endereço configurado e verifique se aparece em Captação.',
                
                '👤 PASSO 2: Assumir Demanda' => 'AÇÃO: Captador acessa Captação → Demandas, clica no card e clica em "Assumir Demanda". RESULTADO: Campo assumed_by_user_id é preenchido com ID do captador, status muda para "em_captacao", card move para coluna "Em Captação". TESTE: Assuma uma demanda e verifique se seu nome aparece como responsável.',
                
                '📱 PASSO 3: Disparar para Grupos WhatsApp' => 'AÇÃO: No card da demanda, clicar em "Realizar Captação". RESULTADO: Sistema busca grupos WhatsApp compatíveis (mesma especialidade/região), gera token único (#CAP123-XXXXX), envia mensagem para cada grupo, cria registros em demand_dispatch_logs. TESTE: Verifique se mensagem chegou nos grupos WhatsApp com o token.',
                
                '💬 PASSO 4: Profissional Responde' => 'AÇÃO: Profissional responde no grupo WhatsApp citando o token. RESULTADO: Sistema registra em whatsapp_group_messages vinculado ao demand_id. TESTE: Responda no grupo e verifique se aparece na aba "Respostas" do card.',
                
                '✅ PASSO 5: Confirmar Admissão via Chat' => 'AÇÃO: Captador abre Chat, seleciona conversa com profissional, clica em "Confirmar Admissão", preenche dados do paciente. RESULTADO: Cria paciente em patients, cria atendimento em patient_assignments, cria agendamento em appointments, atualiza demand para status "admitido". TESTE: Confirme admissão e verifique se paciente foi criado.',
                
                '📊 STATUS POSSÍVEIS' => 'aguardando_captacao (inicial) → tratamento_manual (requer ação manual) → em_captacao (captador assumiu) → admitido (paciente confirmado) → concluido (atendimento finalizado) → cancelado (demanda cancelada).',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Envie e-mail → 2) Assuma demanda → 3) Dispare para grupos → 4) Responda no WhatsApp → 5) Confirme admissão → 6) Verifique se paciente e agendamento foram criados.'
            ],
            
            '💼 2. ATENDIMENTOS (PATIENT ASSIGNMENTS)' => [
                '🎯 O QUE É' => 'Módulo que gerencia todo o ciclo de vida de um atendimento, desde a admissão até o pagamento final.',
                
                '📝 PASSO 1: Criação do Atendimento' => 'AÇÃO: Ao confirmar admissão no chat, sistema cria automaticamente. RESULTADO: INSERT em patient_assignments com status "admitted", vincula paciente + profissional + especialidade + valores. TESTE: Confirme admissão e verifique registro em Faturamento.',
                
                '📄 PASSO 2: Upload de Documentos' => 'AÇÃO: Profissional acessa "Meus Atendimentos", clica no atendimento, faz upload de documentos obrigatórios. RESULTADO: Arquivos salvos em billing_document_requirements com status "uploaded". TESTE: Faça login como profissional e envie documentos.',
                
                '📤 PASSO 3: Solicitar Aprovação' => 'AÇÃO: Após enviar todos documentos, profissional clica em "Solicitar Aprovação Financeira". RESULTADO: Status muda para "awaiting_financial_approval", notificação enviada para equipe financeira. TESTE: Solicite aprovação e verifique se aparece na aba "Aguardando Aprovação".',
                
                '💰 PASSO 4: Aprovação Financeira' => 'AÇÃO: Equipe financeira acessa Faturamento → Aguardando Aprovação, revisa documentos, preenche valores, clica em "Aprovar". RESULTADO: Cria billing_invoices, cria 2 financial_entries (receita + despesa), atualiza patient_assignment para "approved", registra em patient_prontuario_entries. TESTE: Aprove atendimento e verifique se fatura foi criada.',
                
                '✔️ PASSO 5: Conclusão' => 'AÇÃO: Após sessões realizadas, clicar em "Finalizar Atendimento". RESULTADO: Status muda para "completed", atualiza billing_invoices, marca financial_entries como "paid", atualiza demand vinculada para "concluido". TESTE: Finalize atendimento e verifique se tudo foi atualizado.',
                
                '📊 STATUS POSSÍVEIS' => 'admitted (inicial) → awaiting_financial_approval (aguardando) → approved (aprovado) → completed (concluído) → paid (pago) → cancelled (cancelado).',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Crie atendimento → 2) Envie documentos → 3) Solicite aprovação → 4) Aprove financeiramente → 5) Finalize → 6) Verifique se status = completed e valores foram lançados.'
            ],
            
            '💵 3. FINANCEIRO' => [
                '🎯 O QUE É' => 'Módulo de gestão financeira com receitas, despesas, contas a pagar/receber e dashboard de indicadores.',
                
                '📊 Dashboard Financeiro' => 'AÇÃO: Acessar Finance → Dashboard. RESULTADO: Exibe faturamento total, custos, margem operacional, lucro líquido, crescimento vs período anterior, gráficos por especialidade/operadora. TESTE: Acesse e verifique se valores batem com atendimentos aprovados.',
                
                '💰 Contas a Receber' => 'AÇÃO: Acessar Finance → Contas a Receber. RESULTADO: Lista todos financial_entries com entry_type="income" e status="pending". TESTE: Crie lançamento de receita e verifique se aparece na lista.',
                
                '💸 Contas a Pagar' => 'AÇÃO: Acessar Finance → Contas a Pagar. RESULTADO: Lista todos financial_entries com entry_type="expense" e status="pending". TESTE: Crie lançamento de despesa e verifique se aparece na lista.',
                
                '➕ Criar Lançamento' => 'AÇÃO: Clicar em "Novo Lançamento", preencher tipo (receita/despesa), valor, data, categoria, descrição. RESULTADO: INSERT em financial_entries. TESTE: Crie lançamento e verifique se aparece no dashboard.',
                
                '✅ Marcar como Pago' => 'AÇÃO: Na lista de contas, clicar em "Marcar como Pago". RESULTADO: UPDATE financial_entries SET status="paid". TESTE: Marque como pago e verifique se saiu da lista de pendentes.',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Aprove atendimento → 2) Verifique se receita apareceu em Contas a Receber → 3) Verifique se despesa apareceu em Contas a Pagar → 4) Marque como pago → 5) Verifique dashboard atualizado.'
            ],
            
            '💬 4. CHAT E WHATSAPP' => [
                '🎯 O QUE É' => 'Sistema de comunicação integrado com WhatsApp via Evolution API para envio/recebimento de mensagens.',
                
                '📱 Enviar Mensagem Texto' => 'AÇÃO: Acessar Chat, selecionar conversa, digitar mensagem, clicar em Enviar. RESULTADO: POST para Evolution API /message/sendText, salva em chat_messages com from_me=1. TESTE: Envie mensagem e verifique se chegou no WhatsApp.',
                
                '📎 Enviar Mídia' => 'AÇÃO: Clicar em ícone de anexo, selecionar arquivo (imagem/áudio/vídeo/PDF), enviar. RESULTADO: Upload para servidor, POST para Evolution API /message/sendMedia, salva em chat_messages. TESTE: Envie imagem e verifique se chegou.',
                
                '📥 Receber Mensagens' => 'AÇÃO: Webhook configurado em Evolution API aponta para /chat_webhook.php. RESULTADO: Ao receber mensagem, Evolution envia POST para webhook, sistema salva em chat_messages com from_me=0. TESTE: Envie mensagem do WhatsApp e verifique se aparece no chat.',
                
                '👥 Criar Grupo WhatsApp' => 'AÇÃO: Acessar Configurações → Evolution → Gerenciar Grupos → Criar Grupo, preencher especialidade/UF/cidade. RESULTADO: POST para Evolution API /group/create, salva em whatsapp_groups. TESTE: Crie grupo e verifique se foi criado no WhatsApp.',
                
                '🔗 Vincular Contato' => 'AÇÃO: No chat, clicar em "Vincular Contato", selecionar tipo (paciente/profissional) e ID. RESULTADO: UPDATE chat_messages SET contact_kind e contact_ref_id. TESTE: Vincule contato e verifique se nome aparece no chat.',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Configure Evolution API → 2) Conecte instância via QR Code → 3) Envie mensagem texto → 4) Envie mídia → 5) Responda do WhatsApp → 6) Verifique se apareceu no chat.'
            ],
            
            '👨‍⚕️ 5. GESTÃO DE PROFISSIONAIS' => [
                '🎯 O QUE É' => 'Módulo completo de recrutamento, onboarding, documentação e gestão de profissionais de saúde.',
                
                '📝 PASSO 1: Candidatura Pública' => 'AÇÃO: Profissional acessa /public/apply_professional.php, preenche formulário. RESULTADO: INSERT em professional_applications com status="pending". TESTE: Acesse link público e preencha candidatura.',
                
                '👀 PASSO 2: Análise' => 'AÇÃO: Admin acessa Candidaturas, visualiza dados, pode: Aprovar / Reprovar / Solicitar Complemento. RESULTADO: UPDATE professional_applications SET status. TESTE: Aprove candidatura e verifique se profissional foi criado.',
                
                '✅ PASSO 3: Aprovação e Onboarding' => 'AÇÃO: Ao aprovar, sistema cria usuário automaticamente. RESULTADO: INSERT em users, INSERT em user_roles (role=profissional), envia e-mail/WhatsApp com login e senha. TESTE: Aprove e verifique se e-mail foi enviado.',
                
                '📄 PASSO 4: Upload de Documentos' => 'AÇÃO: Profissional faz login, acessa "Meus Documentos", faz upload de RG, CPF, certificados, etc. RESULTADO: Arquivos salvos em professional_documents. TESTE: Faça login como profissional e envie documentos.',
                
                '🔍 PASSO 5: Revisão de Documentos' => 'AÇÃO: Admin acessa Profissionais → Documentos para Revisão, aprova ou rejeita cada documento. RESULTADO: UPDATE professional_documents SET status="approved" ou "rejected". TESTE: Revise documentos e aprove.',
                
                '🟢 PASSO 6: Profissional Ativo' => 'AÇÃO: Com todos documentos aprovados, profissional fica apto. RESULTADO: Aparece em listas de seleção para atendimentos. TESTE: Verifique se profissional aparece ao criar atendimento.',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Preencha candidatura → 2) Aprove no admin → 3) Faça login como profissional → 4) Envie documentos → 5) Aprove documentos → 6) Verifique se está ativo.'
            ],
            
            '🏥 6. GESTÃO DE PACIENTES' => [
                '🎯 O QUE É' => 'Cadastro completo de pacientes com dados pessoais, saúde, vínculos com profissionais e prontuário.',
                
                '➕ Criar Paciente' => 'AÇÃO: Acessar Pacientes → Novo Paciente, preencher dados pessoais, saúde, contato, endereço. RESULTADO: INSERT em patients. TESTE: Cadastre paciente e verifique se aparece na lista.',
                
                '🔗 Vincular Profissional' => 'AÇÃO: No perfil do paciente, clicar em "Vínculos", adicionar profissional + especialidade. RESULTADO: INSERT em patient_assignments. TESTE: Vincule profissional e verifique se aparece em "Meus Pacientes" do profissional.',
                
                '📋 Prontuário' => 'AÇÃO: No perfil do paciente, acessar aba "Prontuário". RESULTADO: Exibe todos registros de patient_prontuario_entries (consultas, procedimentos, observações). TESTE: Adicione entrada no prontuário e verifique se aparece.',
                
                '📄 Documentos' => 'AÇÃO: No perfil do paciente, acessar aba "Documentos", fazer upload. RESULTADO: Arquivos salvos em patient_documents. TESTE: Envie documento e verifique se aparece na lista.',
                
                '📊 Histórico' => 'AÇÃO: Visualizar todas interações do paciente. RESULTADO: Exibe atendimentos, agendamentos, mensagens, documentos. TESTE: Verifique se histórico está completo.',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Cadastre paciente → 2) Vincule a profissional → 3) Crie atendimento → 4) Adicione entrada no prontuário → 5) Envie documento → 6) Verifique histórico completo.'
            ],
            
            '👥 7. RH - RECURSOS HUMANOS' => [
                '🎯 O QUE É' => 'Gestão completa de funcionários internos (não profissionais de saúde), com contratos, benefícios, folha de pagamento.',
                
                '➕ Cadastrar Funcionário' => 'AÇÃO: Acessar RH → Novo Funcionário, preencher dados pessoais, cargo, departamento, salário. RESULTADO: INSERT em hr_employees. TESTE: Cadastre funcionário e verifique se aparece no dashboard RH.',
                
                '📄 Gerar Contrato (ZapSign)' => 'AÇÃO: No perfil do funcionário, clicar em "Gerar Contrato", selecionar template (CLT/PJ/Estágio). RESULTADO: POST para ZapSign API, cria documento, envia para assinatura. TESTE: Gere contrato e verifique se e-mail foi enviado.',
                
                '💰 Folha de Pagamento' => 'AÇÃO: Acessar RH → Folha de Pagamento, selecionar mês, gerar. RESULTADO: Cria hr_payroll_entries para cada funcionário ativo. TESTE: Gere folha e verifique se valores estão corretos.',
                
                '🎁 Benefícios' => 'AÇÃO: No perfil do funcionário, adicionar benefícios (VT, VR, plano de saúde). RESULTADO: INSERT em hr_employee_benefits. TESTE: Adicione benefício e verifique se aparece na folha.',
                
                '📊 Relatórios' => 'AÇÃO: Acessar RH → Relatórios, selecionar tipo (aniversariantes, férias, admissões). RESULTADO: Gera relatório em PDF/Excel. TESTE: Gere relatório e verifique dados.',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Cadastre funcionário → 2) Gere contrato → 3) Funcionário assina → 4) Adicione benefícios → 5) Gere folha de pagamento → 6) Verifique relatórios.'
            ],
            
            '📅 8. AGENDAMENTOS' => [
                '🎯 O QUE É' => 'Gestão de consultas e sessões agendadas entre pacientes e profissionais.',
                
                '➕ Criar Agendamento' => 'AÇÃO: Acessar Agendamentos → Novo, selecionar paciente, profissional, especialidade, data/hora, recorrência. RESULTADO: INSERT em appointments. TESTE: Crie agendamento e verifique se aparece na lista.',
                
                '🔁 Recorrência' => 'AÇÃO: Ao criar, selecionar tipo (single/weekly/monthly/custom), definir regra. RESULTADO: Sistema cria múltiplos appointments baseado na regra. TESTE: Crie agendamento semanal e verifique se criou todas sessões.',
                
                '✅ Marcar como Realizado' => 'AÇÃO: Na lista, clicar em "Marcar como Realizado". RESULTADO: UPDATE appointments SET status="realizado". TESTE: Marque como realizado e verifique se status mudou.',
                
                '❌ Cancelar' => 'AÇÃO: Clicar em "Cancelar Agendamento", informar motivo. RESULTADO: UPDATE appointments SET status="cancelado", cancellation_reason. TESTE: Cancele e verifique se motivo foi salvo.',
                
                '📧 Notificações' => 'AÇÃO: Sistema envia e-mail/WhatsApp automático para paciente e profissional. RESULTADO: Usa templates configurados em admin_settings. TESTE: Crie agendamento e verifique se notificações foram enviadas.',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Crie agendamento → 2) Verifique notificações → 3) Marque como realizado → 4) Verifique se aparece no histórico do paciente.'
            ],
            
            '🔧 9. INTEGRAÇÕES' => [
                '🎯 O QUE É' => 'Conexões com serviços externos: Evolution API (WhatsApp), OpenAI (IA), ZapSign (Assinatura Digital).',
                
                '📱 Evolution API (WhatsApp)' => 'CONFIGURAR: Admin → Configurações → Evolution, preencher Base URL, API Key, Instance. TESTAR: Admin → Evolution → Console, enviar mensagem de teste. VALIDAR: Mensagem deve chegar no WhatsApp.',
                
                '🤖 OpenAI (Inteligência Artificial)' => 'CONFIGURAR: Admin → Configurações → OpenAI, preencher API Key, Model, Prompt. TESTAR: Admin → OpenAI → Console, enviar texto para processar. VALIDAR: Deve retornar resposta da IA.',
                
                '✍️ ZapSign (Assinatura Digital)' => 'CONFIGURAR: Admin → Configurações → ZapSign → Configurar, preencher Token, criar templates. TESTAR: RH → Funcionário → Gerar Contrato. VALIDAR: E-mail de assinatura deve ser enviado.',
                
                '📧 SMTP (E-mail)' => 'CONFIGURAR: Admin → Configurações → SMTP Entrada/Saída, preencher host, porta, usuário, senha. TESTAR: Enviar e-mail de teste. VALIDAR: E-mail deve chegar na caixa.',
                
                '🔍 COMO VALIDAR' => 'Teste completo: 1) Configure cada integração → 2) Teste no console → 3) Use em fluxo real → 4) Verifique logs de erro.'
            ],
            
            '✅ 10. TESTES COMPLETOS PONTA A PONTA' => [
                '🧪 TESTE 1: Fluxo Completo de Captação' => '1) Envie e-mail para caixa de demandas → 2) Verifique se card foi criado → 3) Assuma demanda → 4) Dispare para grupos WhatsApp → 5) Responda no grupo → 6) Confirme admissão → 7) Verifique se paciente, atendimento e agendamento foram criados. RESULTADO ESPERADO: Tudo criado automaticamente.',
                
                '🧪 TESTE 2: Fluxo Completo de Atendimento' => '1) Crie atendimento → 2) Faça login como profissional → 3) Envie documentos → 4) Solicite aprovação → 5) Faça login como admin → 6) Aprove financeiramente → 7) Finalize atendimento → 8) Verifique se valores foram lançados no financeiro. RESULTADO ESPERADO: Status = completed, fatura criada.',
                
                '🧪 TESTE 3: Fluxo Completo de Profissional' => '1) Acesse link público de candidatura → 2) Preencha formulário → 3) Aprove no admin → 4) Verifique e-mail de boas-vindas → 5) Faça login como profissional → 6) Envie documentos → 7) Aprove documentos → 8) Verifique se profissional está ativo. RESULTADO ESPERADO: Profissional apto para receber demandas.',
                
                '🧪 TESTE 4: Fluxo Completo de Chat' => '1) Configure Evolution API → 2) Conecte via QR Code → 3) Envie mensagem texto → 4) Envie imagem → 5) Responda do WhatsApp → 6) Verifique se mensagens aparecem no chat → 7) Vincule contato. RESULTADO ESPERADO: Chat sincronizado.',
                
                '🧪 TESTE 5: Fluxo Completo Financeiro' => '1) Aprove 3 atendimentos → 2) Verifique dashboard financeiro → 3) Acesse contas a receber → 4) Marque 1 como pago → 5) Acesse contas a pagar → 6) Marque 1 como pago → 7) Verifique se dashboard atualizou. RESULTADO ESPERADO: Valores corretos.',
                
                '🧪 TESTE 6: Fluxo Completo de RH' => '1) Cadastre funcionário → 2) Configure ZapSign → 3) Gere contrato → 4) Assine contrato → 5) Adicione benefícios → 6) Gere folha de pagamento → 7) Verifique se valores estão corretos. RESULTADO ESPERADO: Folha gerada com benefícios.',
                
                '📊 CHECKLIST DE VALIDAÇÃO FINAL' => 'Todos os testes acima devem passar sem erros. Se algum falhar, verifique: 1) Configurações corretas 2) Permissões de usuário 3) Integrações ativas 4) Logs de erro 5) Banco de dados atualizado.'
            ]
        ];
        
        echo '<style>';
        echo '.accordion{border:1px solid hsl(var(--border));border-radius:8px;margin-bottom:12px;overflow:hidden}';
        echo '.accordionHeader{padding:14px 16px;background:hsl(var(--card));cursor:pointer;font-weight:600;display:flex;align-items:center;justify-content:space-between;transition:background .15s ease}';
        echo '.accordionHeader:hover{background:hsl(var(--accent))}';
        echo '.accordionHeader.isOpen{background:hsla(var(--primary)/.08)}';
        echo '.accordionIcon{transition:transform .2s ease;font-size:18px;color:hsl(var(--muted-foreground))}';
        echo '.accordionHeader.isOpen .accordionIcon{transform:rotate(180deg)}';
        echo '.accordionContent{display:none;padding:16px;background:hsl(var(--card));border-top:1px solid hsl(var(--border))}';
        echo '.accordionContent.isOpen{display:block}';
        echo '.helpStep{padding:10px 0;border-bottom:1px solid hsl(var(--border))}';
        echo '.helpStep:last-child{border-bottom:none}';
        echo '.helpStepTitle{font-weight:600;margin-bottom:6px;color:hsl(var(--foreground))}';
        echo '.helpStepDesc{color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6}';
        echo '</style>';
        
        foreach ($helpTopics as $topic => $steps) {
            echo '<div class="accordion">';
            echo '<div class="accordionHeader" onclick="toggleAccordion(this)">';
            echo '<span>' . h($topic) . '</span>';
            echo '<span class="accordionIcon">▼</span>';
            echo '</div>';
            echo '<div class="accordionContent">';
            foreach ($steps as $stepTitle => $stepDesc) {
                echo '<div class="helpStep">';
                echo '<div class="helpStepTitle">' . h($stepTitle) . '</div>';
                echo '<div class="helpStepDesc">' . h($stepDesc) . '</div>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
        }
        
        echo '</div>';
        echo '</div>';
    } elseif ($sectionTitle === 'ZapSign') {
        // Incluir conteúdo completo de configuração e console ZapSign
        
        // Buscar configuração atual
        $stmtZapConfig = db()->query('SELECT * FROM zapsign_config LIMIT 1');
        $zapConfig = $stmtZapConfig->fetch();
        
        if (!$zapConfig) {
            db()->exec("INSERT INTO zapsign_config (api_token, sandbox_mode) VALUES ('', 1)");
            $zapConfig = db()->query('SELECT * FROM zapsign_config LIMIT 1')->fetch();
        }
        
        // Buscar templates
        $zapTemplates = db()->query('SELECT * FROM zapsign_contract_templates ORDER BY name ASC')->fetchAll();
        
        // Seção 1: Configuração da API
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">🔑 Configuração da API ZapSign</div>';
        
        echo '<form method="post" action="/zapsign_config_save_post.php" style="display:grid;gap:16px;max-width:800px">';
        
        echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
        echo '<h3 style="font-size:16px;font-weight:700;margin-bottom:12px">Credenciais da API</h3>';
        
        echo '<label>Token da API ZapSign *<input name="api_token" required value="' . h((string)$zapConfig['api_token']) . '" placeholder="Cole aqui o token da API do ZapSign" style="font-family:monospace"></label>';
        
        echo '<div style="margin-top:12px">';
        echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer">';
        $checked = $zapConfig['sandbox_mode'] ? ' checked' : '';
        echo '<input type="checkbox" name="sandbox_mode" value="1"' . $checked . ' style="width:18px;height:18px">';
        echo '<div>';
        echo '<div style="font-weight:600">Modo Sandbox (Teste)</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-top:2px">Ative para testar sem enviar documentos reais</div>';
        echo '</div>';
        echo '</label>';
        echo '</div>';
        
        echo '<div style="margin-top:16px;padding:12px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px">';
        echo '<div style="font-size:13px;color:#92400e">';
        echo '<strong>� Como obter o Token:</strong><br>';
        echo '1. Acesse <a href="https://app.zapsign.com.br" target="_blank" style="color:#b45309;text-decoration:underline">app.zapsign.com.br</a><br>';
        echo '2. Vá em Configurações → API<br>';
        echo '3. Copie o Token de API';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        
        echo '<div style="padding:16px;background:hsl(var(--muted));border-radius:8px">';
        echo '<h3 style="font-size:16px;font-weight:700;margin-bottom:12px">🔔 Webhook (Recomendado)</h3>';
        
        $webhookUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/zapsign_webhook.php';
        echo '<label>URL do Webhook<input name="webhook_url" value="' . h($webhookUrl) . '" readonly style="font-family:monospace;background:#f0f0f0"></label>';
        
        echo '<div style="margin-top:12px;padding:12px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:4px">';
        echo '<div style="font-size:13px;color:#1e40af;line-height:1.6">';
        echo '<strong>🔄 Acompanhamento Automático:</strong><br>';
        echo '• O sistema receberá notificações automáticas do ZapSign<br>';
        echo '• Status dos contratos será atualizado em tempo real<br>';
        echo '• Você será notificado quando um contrato for assinado<br>';
        echo '• Histórico do funcionário será atualizado automaticamente';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        
        echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
        echo '<button class="btn btnPrimary" type="submit">💾 Salvar Configurações</button>';
        echo '</div>';
        
        echo '</form>';
        echo '</div>';
        
        // Seção 2: Templates de Contratos
        echo '<div class="formSection" style="margin-top:20px">';
        echo '<div class="formSectionTitle">📄 Templates de Contratos</div>';
        
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">';
        echo '<div style="color:hsl(var(--muted-foreground));font-size:14px">Gerencie os modelos de contratos para cada tipo de vínculo.</div>';
        echo '<button class="btn btnPrimary" onclick="showZapTemplateModal(0)">+ Novo Template</button>';
        echo '</div>';
        
        if (count($zapTemplates) > 0) {
            echo '<div style="display:grid;gap:12px">';
            
            foreach ($zapTemplates as $tpl) {
                $typeLabels = [
                    'clt' => 'CLT',
                    'pj' => 'PJ',
                    'estagio' => 'Estágio',
                    'temporario' => 'Temporário',
                    'autonomo' => 'Autônomo',
                    'outro' => 'Outro'
                ];
                $typeLabel = $typeLabels[$tpl['template_type']] ?? $tpl['template_type'];
                
                $statusBadge = $tpl['is_active'] 
                    ? '<span style="padding:4px 10px;background:#10b981;color:#fff;border-radius:4px;font-size:12px;font-weight:600">ATIVO</span>'
                    : '<span style="padding:4px 10px;background:#6b7280;color:#fff;border-radius:4px;font-size:12px;font-weight:600">INATIVO</span>';
                
                echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:8px">';
                echo '<div style="display:flex;justify-content:space-between;align-items:start;gap:16px">';
                echo '<div style="flex:1">';
                echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">';
                echo '<div style="font-weight:700;font-size:16px">' . h((string)$tpl['name']) . '</div>';
                echo '<span style="padding:2px 8px;background:hsl(var(--primary));color:#fff;border-radius:4px;font-size:11px;font-weight:600">' . $typeLabel . '</span>';
                echo $statusBadge;
                echo '</div>';
                
                if (!empty($tpl['description'])) {
                    echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:8px">' . h((string)$tpl['description']) . '</div>';
                }
                
                if (!empty($tpl['zapsign_template_token'])) {
                    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));font-family:monospace">Token: ' . h((string)$tpl['zapsign_template_token']) . '</div>';
                }
                
                echo '</div>';
                echo '<div style="display:flex;gap:8px">';
                echo '<button class="btn btnSmall" onclick="showZapTemplateModal(' . (int)$tpl['id'] . ')">Editar</button>';
                echo '<form method="post" action="/zapsign_template_delete_post.php" style="display:inline" onsubmit="return confirm(\'Excluir este template?\')"><input type="hidden" name="template_id" value="' . (int)$tpl['id'] . '"><button class="btn btnSmall btnDanger" type="submit">Excluir</button></form>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
            }
            
            echo '</div>';
        } else {
            echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:12px">';
            echo '<div style="font-size:48px;margin-bottom:12px">📄</div>';
            echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum template cadastrado</div>';
            echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Adicione templates de contratos para cada tipo de vínculo.</div>';
            echo '</div>';
        }
        
        echo '</div>';
        
        // Seção 3: Console ZapSign
        echo '<div class="formSection" style="margin-top:20px">';
        echo '<div class="formSectionTitle">🔧 Console ZapSign</div>';
        
        echo '<div style="padding:16px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
        echo 'Criar e detalhar documentos diretamente via API. Toda chamada gera log em Logs TI (provider=zapsign).';
        echo '</div>';
        echo '</div>';
        
        // Criar documento
        echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-weight:700;font-size:16px;margin-bottom:12px">Criar Documento (POST /api/v1/docs/)</div>';
        
        echo '<form method="post" action="/admin_zapsign_create_doc_post.php" style="display:grid;gap:12px">';
        
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
        echo '<label>Nome *<input name="name" required maxlength="255" placeholder="Contrato"></label>';
        echo '<label>Lang<input name="lang" value="pt-br" placeholder="pt-br"></label>';
        echo '</div>';
        
        echo '<div style="font-weight:600;margin-top:8px">Arquivo</div>';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
        echo '<label>url_pdf (opcional)<input name="url_pdf" placeholder="https://...pdf"></label>';
        echo '<label>url_docx (opcional)<input name="url_docx" placeholder="https://...docx"></label>';
        echo '</div>';
        echo '<label>markdown_text (opcional)<textarea name="markdown_text" rows="4" placeholder="# Título\n\nConteúdo..."></textarea></label>';
        
        echo '<div style="font-weight:600;margin-top:8px">Signatários (mínimo 1)</div>';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
        echo '<label>Signer 1 - Nome *<input name="signer1_name" required placeholder="Fulano"></label>';
        echo '<label>Signer 1 - E-mail *<input type="email" name="signer1_email" required placeholder="fulano@email.com"></label>';
        echo '<label>Signer 2 - Nome (opcional)<input name="signer2_name" placeholder="Ciclano"></label>';
        echo '<label>Signer 2 - E-mail (opcional)<input type="email" name="signer2_email" placeholder="ciclano@email.com"></label>';
        echo '</div>';
        
        echo '<label style="display:flex;align-items:center;gap:10px;padding:12px">';
        echo '<input type="checkbox" name="disable_signer_emails" value="1"> Desabilitar e-mails automáticos do ZapSign';
        echo '</label>';
        
        echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
        echo '<button class="btn btnPrimary" type="submit">Criar Documento</button>';
        echo '</div>';
        
        echo '</form>';
        echo '</div>';
        
        // Detalhar documento
        echo '<div style="padding:16px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '<div style="font-weight:700;font-size:16px;margin-bottom:12px">Detalhar Documento (GET /api/v1/docs/{doc_token}/)</div>';
        
        echo '<form method="post" action="/admin_zapsign_detail_doc_post.php" style="display:flex;gap:10px;flex-wrap:wrap">';
        echo '<input name="doc_token" required placeholder="doc_token" style="flex:1;min-width:280px">';
        echo '<button class="btn" type="submit">Detalhar</button>';
        echo '</form>';
        
        echo '</div>';
        
        echo '</div>';
        
        // Modal para criar/editar template
        echo '<div id="zapTemplateModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">';
        echo '<div class="card" style="max-width:700px;width:90%;max-height:90vh;overflow-y:auto">';
        echo '<h3 style="font-size:20px;font-weight:700;margin-bottom:16px" id="zapTemplateModalTitle">Novo Template</h3>';
        
        echo '<form method="post" action="/zapsign_template_save_post.php" enctype="multipart/form-data" style="display:grid;gap:12px">';
        echo '<input type="hidden" name="template_id" id="zapTemplateId" value="0">';
        
        echo '<label>Nome do Template *<input name="name" id="zapTemplateName" required maxlength="160" placeholder="Ex: Contrato CLT Padrão"></label>';
        
        echo '<label>Tipo de Contrato *<select name="template_type" id="zapTemplateType" required>';
        echo '<option value="">Selecione...</option>';
        echo '<option value="clt">CLT</option>';
        echo '<option value="pj">PJ (Pessoa Jurídica)</option>';
        echo '<option value="estagio">Estágio</option>';
        echo '<option value="temporario">Temporário</option>';
        echo '<option value="autonomo">Autônomo</option>';
        echo '<option value="outro">Outro</option>';
        echo '</select></label>';
        
        echo '<label>Descrição<textarea name="description" id="zapTemplateDescription" rows="3" placeholder="Descreva quando usar este template"></textarea></label>';
        
        echo '<label>Token do Template ZapSign (Opcional)<input name="zapsign_template_token" id="zapTemplateToken" maxlength="255" placeholder="Cole o token do template criado no ZapSign" style="font-family:monospace"></label>';
        
        echo '<label>Upload de PDF (Opcional)<input type="file" name="pdf_file" accept=".pdf"></label>';
        
        echo '<label style="display:flex;align-items:center;gap:12px;cursor:pointer">';
        echo '<input type="checkbox" name="is_active" id="zapTemplateIsActive" value="1" checked style="width:18px;height:18px">';
        echo '<span>Template Ativo</span>';
        echo '</label>';
        
        echo '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">';
        echo '<button type="button" class="btn" onclick="closeZapTemplateModal()">Cancelar</button>';
        echo '<button type="submit" class="btn btnPrimary">Salvar Template</button>';
        echo '</div>';
        
        echo '</form>';
        echo '</div>';
        echo '</div>';
        
        echo '<script>';
        echo 'const zapTemplatesData = ' . json_encode($zapTemplates) . ';';
        echo 'function showZapTemplateModal(id) {';
        echo '  const modal = document.getElementById("zapTemplateModal");';
        echo '  const title = document.getElementById("zapTemplateModalTitle");';
        echo '  if (id === 0) {';
        echo '    title.textContent = "Novo Template";';
        echo '    document.getElementById("zapTemplateId").value = "0";';
        echo '    document.getElementById("zapTemplateName").value = "";';
        echo '    document.getElementById("zapTemplateType").value = "";';
        echo '    document.getElementById("zapTemplateDescription").value = "";';
        echo '    document.getElementById("zapTemplateToken").value = "";';
        echo '    document.getElementById("zapTemplateIsActive").checked = true;';
        echo '  } else {';
        echo '    const tpl = zapTemplatesData.find(t => t.id == id);';
        echo '    if (tpl) {';
        echo '      title.textContent = "Editar Template";';
        echo '      document.getElementById("zapTemplateId").value = tpl.id;';
        echo '      document.getElementById("zapTemplateName").value = tpl.name;';
        echo '      document.getElementById("zapTemplateType").value = tpl.template_type;';
        echo '      document.getElementById("zapTemplateDescription").value = tpl.description || "";';
        echo '      document.getElementById("zapTemplateToken").value = tpl.zapsign_template_token || "";';
        echo '      document.getElementById("zapTemplateIsActive").checked = tpl.is_active == 1;';
        echo '    }';
        echo '  }';
        echo '  modal.style.display = "flex";';
        echo '}';
        echo 'function closeZapTemplateModal() {';
        echo '  document.getElementById("zapTemplateModal").style.display = "none";';
        echo '}';
        echo 'document.getElementById("zapTemplateModal").addEventListener("click", function(e) {';
        echo '  if (e.target === this) closeZapTemplateModal();';
        echo '});';
        echo '</script>';
        
        echo '</div>';
    } elseif ($sectionTitle === 'Logs Técnicos') {
        // Aba Logs Técnicos - implementação já existe no código original
        // Esta aba continua funcionando normalmente
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Logs Técnicos</div>';
        echo '<div style="padding:20px;text-align:center;color:hsl(var(--muted-foreground))">';
        echo 'Acesse os logs técnicos através do menu principal em <a href="/tech_logs_list.php" class="btn btnPrimary">Logs Técnicos</a>';
        echo '</div>';
        echo '</div>';
    } elseif ($sectionTitle === 'Evolution') {
        // Aba especial de Evolution com gerenciamento de instâncias
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Configurações Evolution API</div>';
        
        echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
        echo '<strong>ℹ️ Formato das Credenciais:</strong><br>';
        echo '• <strong>Base URL:</strong> http://IP:PORTA (ex: http://31.97.83.150:8080)<br>';
        echo '• <strong>Link Manager:</strong> http://IP:PORTA/manager/ (ex: http://31.97.83.150:8080/manager/)<br>';
        echo '• <strong>Token (API Key):</strong> Chave de acesso da Evolution API';
        echo '</div>';
        echo '</div>';
        
        echo '<div style="display:grid;gap:12px">';
        
        $baseUrlVal = $settings['evolution.base_url'] ?? '';
        $apiKeyVal = $settings['evolution.api_key'] ?? '';
        $instanceVal = $settings['evolution.instance'] ?? '';
        
        echo '<label>Base URL<input name="settings[evolution.base_url]" value="' . h($baseUrlVal) . '" placeholder="http://31.97.83.150:8080" required><span class="helpText">URL base da Evolution API (sem barra no final)</span></label>';
        
        echo '<label>Link Manager (Opcional)<input name="settings[evolution.manager_url]" value="' . h($settings['evolution.manager_url'] ?? '') . '" placeholder="http://31.97.83.150:8080/manager/"><span class="helpText">URL do painel de gerenciamento (opcional)</span></label>';
        
        $apiKeyVal = $settings['evolution.api_key'] ?? '';
        $hasApiKey = !empty($apiKeyVal);
        $maskedApiKey = $hasApiKey ? str_repeat('•', 32) : '';
        echo '<label>Token (API Key)';
        echo '<div style="position:relative">';
        echo '<input type="password" id="field_evolution_api_key_special" name="settings[evolution.api_key]" value="' . h($apiKeyVal) . '" placeholder="' . ($hasApiKey ? $maskedApiKey : 'Cole o token da Evolution API aqui') . '" style="padding-right:80px">';
        if ($hasApiKey) {
            echo '<button type="button" onclick="togglePassword(\'field_evolution_api_key_special\')" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:hsl(var(--primary));color:white;border:none;padding:4px 10px;border-radius:4px;font-size:12px;cursor:pointer">Revelar</button>';
        }
        echo '</div>';
        echo '<span class="helpText">Deixe vazio para manter o valor atual. Token de autenticação da API.</span>';
        echo '</label>';
        
        echo '<label>Nome da Instância Padrão<input name="settings[evolution.instance]" value="' . h($instanceVal) . '" placeholder="multilife"><span class="helpText">Nome da instância WhatsApp padrão</span></label>';
        
        echo '</div>';
        echo '</div>';
        
        // Seção de gerenciamento de instâncias
        echo '<div class="formSection" style="margin-top:20px">';
        echo '<div class="formSectionTitle">Gerenciamento de Instâncias</div>';
        echo '<div style="display:grid;gap:12px;margin-top:12px">';
        
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
        echo '<a class="btn btnPrimary" href="/evolution_instances.php">Gerenciar Instâncias</a>';
        echo '<a class="btn" href="/evolution_qrcode.php">Ver QR Code</a>';
        echo '<a class="btn" href="/whatsapp_groups_list.php">Gerenciar Grupos WhatsApp</a>';
        echo '</div>';
        
        echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--border));border-radius:8px">';
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));line-height:1.6">';
        echo '<strong>Funcionalidades:</strong><br>';
        echo '• Criar e excluir instâncias WhatsApp<br>';
        echo '• Gerar QR Code para conectar dispositivo<br>';
        echo '• Verificar status de conexão<br>';
        echo '• Gerenciar grupos e membros';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
    } elseif ($sectionTitle === 'WhatsApp') {
        // Incluir conteúdo de gerenciamento de eventos WhatsApp diretamente
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Eventos WhatsApp</div>';
        
        echo '<div style="padding:16px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
        echo '<strong>📱 Sistema de Eventos WhatsApp</strong><br>';
        echo 'Gerencie mensagens automáticas baseadas em eventos do sistema.<br><br>';
        echo '<strong>Funcionalidades:</strong><br>';
        echo '• Templates configuráveis para profissionais e pacientes<br>';
        echo '• Variáveis dinâmicas (nome, data, links, etc)<br>';
        echo '• Anexos de arquivos (PDF, imagens, documentos)<br>';
        echo '• Links adicionais personalizados<br>';
        echo '• Log completo de envios para auditoria<br>';
        echo '• Ativar/desativar eventos individualmente';
        echo '</div>';
        echo '</div>';
        
        // Buscar eventos WhatsApp
        $stmtEvents = db()->query("
            SELECT 
                id,
                name,
                system_event,
                status,
                send_to_professional,
                send_to_patient,
                created_at
            FROM whatsapp_events
            ORDER BY name ASC
        ");
        $whatsappEvents = $stmtEvents->fetchAll();
        
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">';
        echo '<a class="btn btnPrimary" href="/admin_whatsapp_events_edit.php">+ Criar Evento</a>';
        echo '</div>';
        
        if (empty($whatsappEvents)) {
            echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border:1px solid hsl(var(--border));border-radius:8px">';
            echo '<div style="font-size:48px;margin-bottom:16px">📭</div>';
            echo '<div style="font-size:16px;color:hsl(var(--muted-foreground));margin-bottom:16px">Nenhum evento configurado</div>';
            echo '<a class="btn btnPrimary" href="/admin_whatsapp_events_edit.php">Criar primeiro evento</a>';
            echo '</div>';
        } else {
            echo '<div style="overflow:auto">';
            echo '<table style="width:100%;border-collapse:collapse">';
            echo '<thead>';
            echo '<tr style="background:hsl(var(--muted));border-bottom:2px solid hsl(var(--border))">';
            echo '<th style="padding:12px;text-align:left;font-weight:600">Evento</th>';
            echo '<th style="padding:12px;text-align:left;font-weight:600">Evento do Sistema</th>';
            echo '<th style="padding:12px;text-align:left;font-weight:600">Destinatário</th>';
            echo '<th style="padding:12px;text-align:left;font-weight:600">Status</th>';
            echo '<th style="padding:12px;text-align:right;font-weight:600">Ações</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($whatsappEvents as $evt) {
                $sendToProfessional = (bool)$evt['send_to_professional'];
                $sendToPatient = (bool)$evt['send_to_patient'];
                
                $recipientType = 'Nenhum';
                $recipientBadge = 'background:#6c757d;color:white';
                
                if ($sendToProfessional && $sendToPatient) {
                    $recipientType = 'Ambos';
                    $recipientBadge = 'background:#0dcaf0;color:white';
                } elseif ($sendToProfessional) {
                    $recipientType = 'Profissional';
                    $recipientBadge = 'background:#0d6efd;color:white';
                } elseif ($sendToPatient) {
                    $recipientType = 'Paciente';
                    $recipientBadge = 'background:#198754;color:white';
                }
                
                $statusBadge = $evt['status'] === 'active' 
                    ? '<span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;background:#198754;color:white">Ativo</span>'
                    : '<span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;background:#6c757d;color:white">Inativo</span>';
                
                echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
                echo '<td style="padding:12px"><strong>' . h($evt['name']) . '</strong></td>';
                echo '<td style="padding:12px"><code style="background:hsl(var(--muted));padding:2px 6px;border-radius:4px;font-size:12px">' . h($evt['system_event']) . '</code></td>';
                echo '<td style="padding:12px"><span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;' . $recipientBadge . '">' . h($recipientType) . '</span></td>';
                echo '<td style="padding:12px">' . $statusBadge . '</td>';
                echo '<td style="padding:12px;text-align:right">';
                echo '<a class="btn btnSmall" href="/admin_whatsapp_events_edit.php?id=' . (int)$evt['id'] . '">Editar</a> ';
                echo '<button class="btn btnSmall btnDanger" onclick="deleteWhatsAppEvent(' . (int)$evt['id'] . ', \'' . h($evt['name']) . '\')">Excluir</button>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
        
        echo '<script>';
        echo 'function deleteWhatsAppEvent(eventId, eventName) {';
        echo '  if (!confirm("Tem certeza que deseja excluir o evento \\"" + eventName + "\\"?\\n\\nEsta ação não pode ser desfeita.")) return;';
        echo '  fetch("/admin_whatsapp_events_delete_post.php", {';
        echo '    method: "POST",';
        echo '    headers: {"Content-Type": "application/x-www-form-urlencoded"},';
        echo '    body: "id=" + eventId';
        echo '  })';
        echo '  .then(response => response.json())';
        echo '  .then(data => {';
        echo '    if (data.success) {';
        echo '      window.location.reload();';
        echo '    } else {';
        echo '      alert("Erro ao excluir evento: " + (data.error || "Erro desconhecido"));';
        echo '    }';
        echo '  })';
        echo '  .catch(error => {';
        echo '    alert("Erro ao excluir evento: " + error.message);';
        echo '  });';
        echo '}';
        echo '</script>';
        
        echo '</div>';
    } elseif ($sectionTitle === 'E-mail') {
        // Incluir conteúdo de gerenciamento de eventos de E-mail diretamente
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Eventos de E-mail</div>';
        
        echo '<div style="padding:16px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--primary));border-radius:8px;margin-bottom:16px">';
        echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
        echo '<strong>📧 Sistema de Eventos de E-mail</strong><br>';
        echo 'Gerencie e-mails automáticos baseados em eventos do sistema.<br><br>';
        echo '<strong>Funcionalidades:</strong><br>';
        echo '• Templates HTML configuráveis para profissionais e pacientes<br>';
        echo '• Variáveis dinâmicas (nome, data, links, etc)<br>';
        echo '• Anexos de arquivos (PDF, imagens, documentos)<br>';
        echo '• Links adicionais personalizados<br>';
        echo '• Log completo de envios para auditoria<br>';
        echo '• Ativar/desativar eventos individualmente';
        echo '</div>';
        echo '</div>';
        
        // Buscar eventos de E-mail
        $stmtEmailEvents = db()->query("
            SELECT 
                id,
                name,
                system_event,
                status,
                send_to_professional,
                send_to_patient,
                created_at
            FROM email_events
            ORDER BY name ASC
        ");
        $emailEvents = $stmtEmailEvents->fetchAll();
        
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">';
        echo '<a class="btn btnPrimary" href="/admin_email_events_edit.php">+ Criar Evento</a>';
        echo '</div>';
        
        if (empty($emailEvents)) {
            echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border:1px solid hsl(var(--border));border-radius:8px">';
            echo '<div style="font-size:48px;margin-bottom:16px">📧</div>';
            echo '<div style="font-size:16px;color:hsl(var(--muted-foreground));margin-bottom:16px">Nenhum evento configurado</div>';
            echo '<a class="btn btnPrimary" href="/admin_email_events_edit.php">Criar primeiro evento</a>';
            echo '</div>';
        } else {
            echo '<div style="overflow:auto">';
            echo '<table style="width:100%;border-collapse:collapse">';
            echo '<thead>';
            echo '<tr style="background:hsl(var(--muted));border-bottom:2px solid hsl(var(--border))">';
            echo '<th style="padding:12px;text-align:left;font-weight:600">Evento</th>';
            echo '<th style="padding:12px;text-align:left;font-weight:600">Evento do Sistema</th>';
            echo '<th style="padding:12px;text-align:left;font-weight:600">Destinatário</th>';
            echo '<th style="padding:12px;text-align:left;font-weight:600">Status</th>';
            echo '<th style="padding:12px;text-align:right;font-weight:600">Ações</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($emailEvents as $evt) {
                $sendToProfessional = (bool)$evt['send_to_professional'];
                $sendToPatient = (bool)$evt['send_to_patient'];
                
                $recipientType = 'Nenhum';
                $recipientBadge = 'background:#6c757d;color:white';
                
                if ($sendToProfessional && $sendToPatient) {
                    $recipientType = 'Ambos';
                    $recipientBadge = 'background:#0dcaf0;color:white';
                } elseif ($sendToProfessional) {
                    $recipientType = 'Profissional';
                    $recipientBadge = 'background:#0d6efd;color:white';
                } elseif ($sendToPatient) {
                    $recipientType = 'Paciente';
                    $recipientBadge = 'background:#198754;color:white';
                }
                
                $statusBadge = $evt['status'] === 'active' 
                    ? '<span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;background:#198754;color:white">Ativo</span>'
                    : '<span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;background:#6c757d;color:white">Inativo</span>';
                
                echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
                echo '<td style="padding:12px"><strong>' . h($evt['name']) . '</strong></td>';
                echo '<td style="padding:12px"><code style="background:hsl(var(--muted));padding:2px 6px;border-radius:4px;font-size:12px">' . h($evt['system_event']) . '</code></td>';
                echo '<td style="padding:12px"><span style="padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;' . $recipientBadge . '">' . h($recipientType) . '</span></td>';
                echo '<td style="padding:12px">' . $statusBadge . '</td>';
                echo '<td style="padding:12px;text-align:right">';
                echo '<a class="btn btnSmall" href="/admin_email_events_edit.php?id=' . (int)$evt['id'] . '">Editar</a> ';
                echo '<button class="btn btnSmall btnDanger" onclick="deleteEmailEvent(' . (int)$evt['id'] . ', \'' . h($evt['name']) . '\')">Excluir</button>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
        
        echo '<script>';
        echo 'function deleteEmailEvent(eventId, eventName) {';
        echo '  if (!confirm("Tem certeza que deseja excluir o evento \\"" + eventName + "\\"?\\n\\nEsta ação não pode ser desfeita.")) return;';
        echo '  fetch("/admin_email_events_delete_post.php", {';
        echo '    method: "POST",';
        echo '    headers: {"Content-Type": "application/x-www-form-urlencoded"},';
        echo '    body: "id=" + eventId';
        echo '  })';
        echo '  .then(response => response.json())';
        echo '  .then(data => {';
        echo '    if (data.success) {';
        echo '      window.location.reload();';
        echo '    } else {';
        echo '      alert("Erro ao excluir evento: " + (data.error || "Erro desconhecido"));';
        echo '    }';
        echo '  })';
        echo '  .catch(error => {';
        echo '    alert("Erro ao excluir evento: " + error.message);';
        echo '  });';
        echo '}';
        echo '</script>';
        
        echo '</div>';
    } else {
        // Abas normais de configuração
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">' . h($sectionTitle) . '</div>';
        echo '<div style="display:grid;gap:12px">';
        
        foreach ($sectionData['keys'] as $key) {
            if (!isset($fields[$key])) continue;
            $label = $fields[$key];
            $val = $settings[$key] ?? '';
            $isSensitive = in_array($key, ['cron.token', 'smtp.in.password', 'smtp.out.password', 'openai.api_key', 'evolution.api_key', 'zapsign.api_token'], true);
            $isTemplate = str_contains($key, 'template') || $key === 'openai.extract_prompt';
            
            if ($isSensitive) {
                $hasValue = !empty($val);
                $maskedValue = $hasValue ? str_repeat('•', 32) : '';
                $fieldId = 'field_' . str_replace('.', '_', $key);
                
                echo '<label>' . h($label);
                echo '<div style="position:relative">';
                echo '<input type="password" id="' . $fieldId . '" name="settings[' . h($key) . ']" value="' . h($val) . '" placeholder="' . ($hasValue ? $maskedValue : '(vazio - preencha para alterar)') . '" style="padding-right:80px">';
                if ($hasValue) {
                    echo '<button type="button" onclick="togglePassword(\'' . $fieldId . '\')" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:hsl(var(--primary));color:white;border:none;padding:4px 10px;border-radius:4px;font-size:12px;cursor:pointer">Revelar</button>';
                }
                echo '</div>';
                echo '<span class="helpText">Deixe vazio para manter o valor atual</span>';
                echo '</label>';
            } elseif ($isTemplate) {
                echo '<label>' . h($label) . '<textarea name="settings[' . h($key) . ']" rows="4" placeholder="(configure)">' . h($val) . '</textarea></label>';
            } else {
                echo '<label>' . h($label) . '<input name="settings[' . h($key) . ']" value="' . h($val) . '"></label>';
            }
        }
        
        echo '</div>';
        echo '</div>';
    }
    
    echo '</div>';
    $idx++;
}

echo '<div style="display:flex;justify-content:flex-end;margin-top:20px">';
echo '<button class="btn btnPrimary" type="submit">Salvar Configurações</button>';
echo '</div>';
echo '</form>';

echo '</section>';
echo '</div>';

view_footer();
?>

<script>
(function(){
  var tabs = document.querySelectorAll(".configTab");
  var panels = document.querySelectorAll(".configPanel");
  
  tabs.forEach(function(tab){
    tab.addEventListener("click", function(e){
      e.preventDefault();
      var targetId = this.getAttribute("data-tab");
      var targetPanel = document.getElementById(targetId);
      
      tabs.forEach(function(t){ t.classList.remove("isActive"); });
      panels.forEach(function(p){ p.classList.remove("isActive"); });
      
      this.classList.add("isActive");
      if(targetPanel){
        targetPanel.classList.add("isActive");
      }
    });
  });
})();

function togglePassword(fieldId){
  var field = document.getElementById(fieldId);
  var button = field.nextElementSibling;
  if(field.type === "password"){
    field.type = "text";
    button.textContent = "Ocultar";
  } else {
    field.type = "password";
    button.textContent = "Revelar";
  }
}

function toggleAccordion(header){
  var content = header.nextElementSibling;
  var isOpen = header.classList.contains("isOpen");
  if(isOpen){
    header.classList.remove("isOpen");
    content.classList.remove("isOpen");
  }else{
    header.classList.add("isOpen");
    content.classList.add("isOpen");
  }
}
</script><?php
