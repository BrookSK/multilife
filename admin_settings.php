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
echo '<style>';
echo '.configTabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;border-bottom:2px solid hsl(var(--border));padding-bottom:8px}';
echo '.configTab{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:8px 8px 0 0;background:transparent;border:none;cursor:pointer;font-size:14px;font-weight:600;color:hsl(var(--muted-foreground));transition:all .15s ease}';
echo '.configTab:hover{background:hsla(var(--primary)/.05);color:hsl(var(--foreground))}';
echo '.configTab.isActive{background:hsl(var(--primary));color:hsl(var(--primary-foreground));box-shadow:0 2px 4px rgba(0,0,0,.1)}';
echo '.configTab svg{width:16px;height:16px;flex-shrink:0}';
echo '.configPanel{display:none}';
echo '.configPanel.isActive{display:block}';
echo '</style>';
echo '<form method="post" action="/admin_settings_post.php">';

$sections = [
    'Aparência' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'keys' => ['app.logo_url', 'app.login_logo_url', '_upload_logos_']
    ],
    'Especialidades' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        'keys' => ['_specialties_']
    ],
    'Operacional' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"/></svg>',
        'keys' => ['docs.reminder_days_before_due', 'finance.repasse_cycle_days', 'demands.assume_timeout_hours', 'chat.unanswered_timeout_minutes', 'professional.docs_expiry_notice_days', 'professional.required_doc_categories', 'professional.docs_reminder_days_before_due', 'app.session_lifetime_seconds', 'cron.token', 'app.public_base_url']
    ],
    'SMTP Entrada' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'keys' => ['smtp.in.host', 'smtp.in.port', 'smtp.in.encryption', 'smtp.in.username', 'smtp.in.password', 'smtp.in.mailbox', 'smtp.in.archive_mailbox', 'smtp.in.poll_minutes', 'smtp.demands.to_address']
    ],
    'SMTP Saída' => [
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
        'keys' => ['zapsign.base_url', 'zapsign.api_token']
    ],
    'WhatsApp' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
        'keys' => ['demands.whatsapp_template', 'appointments.patient_whatsapp_template', 'professional.onboarding_whatsapp_template', 'professional.application_need_more_info_whatsapp_template', 'professional.application_rejected_whatsapp_template', 'professional.docs_reminder_whatsapp_template', 'professional.docs_overdue_whatsapp_template', 'professional.docs_approved_whatsapp_template']
    ],
    'E-mail' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'keys' => ['appointments.email_subject_template', 'appointments.email_body_template', 'professional.onboarding_email_subject_template', 'professional.onboarding_email_body_template', 'professional.application_need_more_info_email_subject_template', 'professional.application_need_more_info_email_body_template', 'professional.application_rejected_email_subject_template', 'professional.application_rejected_email_body_template', 'professional.docs_reminder_email_subject_template', 'professional.docs_reminder_email_body_template', 'professional.docs_overdue_email_subject_template', 'professional.docs_overdue_email_body_template', 'professional.docs_approved_email_subject_template', 'professional.docs_approved_email_body_template']
    ],
    'Ajuda' => [
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'keys' => ['_help_']
    ],
];

$fieldsAdded = ['zapsign.base_url' => 'ZapSign - Base URL', 'zapsign.api_token' => 'ZapSign - API Token'];
$fields = array_merge($fields, $fieldsAdded);

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
                echo '<a href="/specialties_edit.php?id=' . (int)$spec['id'] . '" class="btn" style="font-size:12px;padding:6px 10px">Editar</a>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    } elseif ($sectionTitle === 'Ajuda') {
        // Aba especial de Ajuda
        echo '<div class="formSection">';
        echo '<div class="formSectionTitle">Como Funciona o Sistema</div>';
        echo '<div style="margin-top:12px">';
        
        $helpTopics = [
            'Fluxo de Captação de Demandas' => [
                'Recebimento da Demanda' => 'Uma demanda chega por e-mail ou WhatsApp e é registrada automaticamente no sistema.',
                'Captador Assume a Demanda' => 'O captador visualiza a demanda na lista de Captação e clica em "Assumir". A partir desse momento, ele é responsável por encontrar um profissional.',
                'Disparo para Grupos WhatsApp' => 'O captador deve disparar a demanda para os grupos de WhatsApp dos profissionais. O sistema envia automaticamente a mensagem com os detalhes da demanda.',
                'Profissional Manifesta Interesse' => 'Os profissionais que receberem a mensagem no grupo podem responder demonstrando interesse em atender a demanda.',
                'Captador Seleciona Profissional' => 'O captador escolhe o profissional mais adequado e registra no sistema quem foi selecionado.',
                'Finalização' => 'A demanda é marcada como "Atendida" e o fluxo é concluído.'
            ],
            'Gestão de Profissionais' => [
                'Candidatura' => 'O profissional preenche o formulário de candidatura no site público. Os dados são salvos como "Pendente".',
                'Análise da Candidatura' => 'A equipe de RH acessa "Candidaturas" e visualiza os dados. Pode aprovar, reprovar ou solicitar mais informações.',
                'Aprovação e Onboarding' => 'Ao aprovar, o sistema cria automaticamente um usuário para o profissional e envia e-mail/WhatsApp com login e senha.',
                'Documentação' => 'O profissional acessa o sistema e faz upload dos documentos obrigatórios (RG, CPF, certificados, etc).',
                'Revisão de Documentos' => 'A equipe revisa os documentos em "Profissionais → Documentos para Revisão" e aprova ou rejeita.',
                'Profissional Ativo' => 'Com todos os documentos aprovados, o profissional está apto a receber demandas.'
            ],
            'Gestão de Pacientes' => [
                'Cadastro do Paciente' => 'Quando uma demanda é atendida, o captador ou profissional cadastra o paciente no sistema com dados pessoais e de saúde.',
                'Vínculos' => 'O paciente é vinculado ao profissional que irá atendê-lo. Um paciente pode ter vários profissionais (ex: fisioterapeuta + nutricionista).',
                'Acompanhamento' => 'O profissional acessa "Meus Pacientes" para ver todos os pacientes sob seus cuidados.',
                'Histórico' => 'Todas as interações, sessões e documentos do paciente ficam registrados para consulta futura.'
            ],
            'Chat e Comunicação' => [
                'Chat Interno' => 'O sistema possui chat interno para comunicação entre equipe e profissionais.',
                'Mensagens WhatsApp' => 'Integrado com WhatsApp via Evolution API para envio automático de mensagens.',
                'E-mails Automáticos' => 'O sistema envia e-mails automáticos em eventos importantes (aprovação, lembretes, etc).',
                'Notificações' => 'O sininho no topo mostra notificações de pendências, novos e-mails, mensagens WhatsApp e captações atrasadas.'
            ],
            'Como Testar o Sistema' => [
                '1. Teste de Captação' => 'Crie uma demanda manualmente em "Captação → Criar Demanda". Assuma a demanda e teste o disparo para grupos WhatsApp.',
                '2. Teste de Candidatura' => 'Acesse a página pública de candidatura (/apply_professional.php) e preencha o formulário. Depois, aprove a candidatura no admin.',
                '3. Teste de Documentos' => 'Faça login como profissional e envie documentos. Depois, acesse como admin e revise os documentos.',
                '4. Teste de Paciente' => 'Cadastre um paciente de teste e vincule a um profissional. Verifique se aparece em "Meus Pacientes".',
                '5. Teste de Notificações' => 'Execute a migration de notificações e crie notificações de teste usando as funções helper.',
                '6. Teste de Integrações' => 'Configure as credenciais em "Integrações" e teste o console de cada integração (WhatsApp, OpenAI, etc).'
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
        
        echo '<label>Token (API Key)<input type="password" name="settings[evolution.api_key]" value="" placeholder="Cole o token da Evolution API aqui"><span class="helpText">Deixe vazio para manter o valor atual. Token de autenticação da API.</span></label>';
        
        echo '<label>Nome da Instância Padrão<input name="settings[evolution.instance]" value="' . h($instanceVal) . '" placeholder="multilife_whatsapp"><span class="helpText">Nome da instância WhatsApp padrão</span></label>';
        
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
    
    echo '</div>';
    $idx++;
}

echo '<div style="display:flex;justify-content:flex-end;margin-top:20px">';
echo '<button class="btn btnPrimary" type="submit">Salvar Configurações</button>';
echo '</div>';
echo '</form>';

echo '<script>';
echo 'document.querySelectorAll(".configTab").forEach(function(tab){';
echo '  tab.addEventListener("click", function(){';
echo '    const targetId = this.getAttribute("data-tab");';
echo '    document.querySelectorAll(".configTab").forEach(function(t){ t.classList.remove("isActive"); });';
echo '    document.querySelectorAll(".configPanel").forEach(function(p){ p.classList.remove("isActive"); });';
echo '    this.classList.add("isActive");';
echo '    document.getElementById(targetId).classList.add("isActive");';
echo '  });';
echo '});';
echo 'function toggleAccordion(header){';
echo '  const content = header.nextElementSibling;';
echo '  const isOpen = header.classList.contains("isOpen");';
echo '  if(isOpen){';
echo '    header.classList.remove("isOpen");';
echo '    content.classList.remove("isOpen");';
echo '  }else{';
echo '    header.classList.add("isOpen");';
echo '    content.classList.add("isOpen");';
echo '  }';
echo '}';
echo '</script>';

echo '</section>';

echo '</div>';

view_footer();
