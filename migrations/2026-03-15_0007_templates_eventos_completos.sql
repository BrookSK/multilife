-- Migration: Templates Completos de Eventos WhatsApp e E-mail
-- Data: 2026-03-15
-- Descrição: Criar todos os templates de eventos para profissionais e pacientes

-- ============================================================================
-- EVENTOS WHATSAPP
-- ============================================================================

-- 1. Candidatura - Aprovada
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, created_at) VALUES
(
    'Candidatura - Aprovada',
    'professional_application_approved',
    'active',
    1,
    0,
    'Olá {name}! 👋

🎉 *Parabéns! Sua candidatura foi aprovada!*

Você agora faz parte da equipe MultiLife Care.

🔐 *Defina sua senha de acesso:*
{password_setup_link}

📧 *Seu e-mail de acesso:* {email}

Após definir sua senha, você poderá acessar o sistema e começar a trabalhar conosco.

Bem-vindo(a) à equipe! 💙',
    NOW()
);

-- 2. Onboarding de Profissional
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, created_at) VALUES
(
    'Onboarding - Profissional Aprovado',
    'professional_onboarding',
    'active',
    1,
    0,
    'Olá {name}! 👋

🎉 Parabéns! Sua candidatura foi aprovada!

Você agora faz parte da equipe MultiLife Care.

📧 *Dados de Acesso:*
• E-mail: {email}
• Senha: {password}

🔗 *Acesse o sistema:*
{login_url}

Por favor, altere sua senha no primeiro acesso.

Bem-vindo(a) à equipe! 💙',
    NOW()
);

-- 3. Candidatura - Precisa de Mais Informações
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, created_at) VALUES
(
    'Candidatura - Complemento de Informações',
    'professional_application_need_more_info',
    'active',
    1,
    0,
    'Olá {name}! 👋

Recebemos sua candidatura #{application_id} e precisamos de algumas informações adicionais:

📝 *Solicitação:*
{message}

Por favor, acesse o sistema e complemente as informações solicitadas.

Qualquer dúvida, estamos à disposição!',
    NOW()
);

-- 3. Candidatura - Reprovada
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, created_at) VALUES
(
    'Candidatura - Reprovada',
    'professional_application_rejected',
    'active',
    1,
    0,
    'Olá {name}! 👋

Agradecemos seu interesse em fazer parte da MultiLife Care.

Infelizmente, sua candidatura #{application_id} não foi aprovada neste momento.

📝 *Motivo:*
{message}

Você pode se candidatar novamente no futuro.

Desejamos sucesso em sua carreira! 💙',
    NOW()
);

-- 5. Documentos - Lembrete de Vencimento
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, created_at) VALUES
(
    'Documentos - Lembrete de Vencimento',
    'professional_docs_reminder',
    'active',
    1,
    0,
    'Olá {name}! 👋

⚠️ *Lembrete Importante*

O documento #{doc_id} referente ao paciente {patient_ref} está próximo do vencimento.

📅 *Prazo:* {due_at}

Por favor, envie o documento atualizado o quanto antes para evitar bloqueios.

Acesse o sistema para fazer o upload.',
    NOW()
);

-- 5. Documentos - Cobrança de Atraso
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, created_at) VALUES
(
    'Documentos - Cobrança de Atraso',
    'professional_docs_overdue',
    'active',
    1,
    0,
    'Olá {name}! 👋

🚨 *URGENTE - Documento Atrasado*

O documento #{doc_id} referente ao paciente {patient_ref} está atrasado há {days_overdue} dias.

📅 *Prazo era:* {due_at}

⚠️ Seu acesso pode ser bloqueado até a regularização.

Envie o documento HOJE através do sistema.',
    NOW()
);

-- 6. Documentos - Aprovação
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, created_at) VALUES
(
    'Documentos - Aprovação Confirmada',
    'professional_docs_approved',
    'active',
    1,
    0,
    'Olá {name}! 👋

✅ *Documento Aprovado*

O documento #{doc_id} referente ao paciente {patient_ref} foi aprovado!

📊 *Sessões autorizadas:* {sessions_count}

Você já pode iniciar os atendimentos.

Sucesso! 💙',
    NOW()
);

-- 7. Agendamento - Notificação ao Paciente
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_patient, created_at) VALUES
(
    'Agendamento - Confirmação ao Paciente',
    'appointment_patient_notification',
    'active',
    0,
    1,
    'Olá {patient_name}! 👋

✅ *Agendamento Confirmado*

Seu atendimento foi agendado com sucesso!

👨‍⚕️ *Profissional:* {professional_name}
📅 *Data/Hora:* {first_at}
📍 *Local:* Atendimento domiciliar

Em caso de dúvidas ou necessidade de reagendar, entre em contato conosco.

Até breve! 💙',
    NOW()
);

-- 9. Captação - Notificação de Nova Demanda
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, created_at) VALUES
(
    'Captação - Nova Demanda Disponível',
    'demand_dispatch',
    'active',
    1,
    0,
    '🔔 *Nova Demanda Disponível*

#{id} - {title}

📍 *Local:* {city}/{state}
🏥 *Especialidade:* {specialty}
📝 *Descrição:* {description}

📧 *Origem:* {origin}

Acesse o sistema para mais detalhes e assumir a demanda.',
    NOW()
);

-- ============================================================================
-- EVENTOS E-MAIL
-- ============================================================================

-- 1. Candidatura - Aprovada
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, subject_professional, template_professional_html, created_at) VALUES
(
    'Candidatura - Aprovada',
    'professional_application_approved',
    'active',
    1,
    0,
    'Parabéns! Sua candidatura foi aprovada - MultiLife Care',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #059669; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .success-box { background: #d1fae5; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #059669; }
        .button { display: inline-block; padding: 14px 28px; background: #059669; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: 600; font-size: 16px; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Parabéns, {name}!</h1>
            <p>Sua candidatura foi aprovada</p>
        </div>
        
        <div class="content">
            <p>Você agora faz parte da equipe <strong>MultiLife Care</strong>!</p>
            
            <div class="success-box">
                <h3>🔐 Próximo Passo: Defina sua Senha</h3>
                <p><strong>E-mail de acesso:</strong> {email}</p>
                <p>Clique no botão abaixo para criar sua senha de acesso ao sistema:</p>
            </div>
            
            <p style="text-align: center;">
                <a href="{password_setup_link}" class="button">Definir Minha Senha</a>
            </p>
            
            <p><strong>⚠️ Importante:</strong> Este link é válido por 48 horas. Após definir sua senha, você terá acesso completo ao sistema.</p>
            
            <p>Bem-vindo(a) à equipe! 💙</p>
        </div>
        
        <div class="footer">
            <p>© 2026 MultiLife Care - Sistema de Gestão de Atendimentos</p>
        </div>
    </div>
</body>
</html>',
    NOW()
);

-- 2. Onboarding de Profissional
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, subject_professional, template_professional_html, created_at) VALUES
(
    'Onboarding - Profissional Aprovado',
    'professional_onboarding',
    'active',
    1,
    0,
    'Bem-vindo(a) à MultiLife Care - Acesso Liberado',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .credentials { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #2563eb; }
        .button { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Parabéns, {name}!</h1>
            <p>Sua candidatura foi aprovada</p>
        </div>
        
        <div class="content">
            <p>Você agora faz parte da equipe MultiLife Care!</p>
            
            <div class="credentials">
                <h3>📧 Dados de Acesso ao Sistema</h3>
                <p><strong>E-mail:</strong> {email}</p>
                <p><strong>Senha Temporária:</strong> {password}</p>
            </div>
            
            <p style="text-align: center;">
                <a href="{login_url}" class="button">Acessar Sistema</a>
            </p>
            
            <p><strong>⚠️ Importante:</strong> Por favor, altere sua senha no primeiro acesso.</p>
            
            <p>Bem-vindo(a) à equipe! 💙</p>
        </div>
        
        <div class="footer">
            <p>© 2026 MultiLife Care - Sistema de Gestão de Atendimentos</p>
        </div>
    </div>
</body>
</html>',
    NOW()
);

-- 2. Candidatura - Precisa de Mais Informações
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, subject_professional, template_professional_html, created_at) VALUES
(
    'Candidatura - Complemento de Informações',
    'professional_application_need_more_info',
    'active',
    1,
    0,
    'Candidatura #{application_id} - Informações Adicionais Necessárias',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f59e0b; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .alert { background: #fef3c7; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #f59e0b; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📝 Complemento de Informações</h1>
        </div>
        
        <div class="content">
            <p>Olá {name},</p>
            
            <p>Recebemos sua candidatura <strong>#{application_id}</strong> e precisamos de algumas informações adicionais:</p>
            
            <div class="alert">
                <h3>Solicitação:</h3>
                <p>{message}</p>
            </div>
            
            <p>Por favor, acesse o sistema e complemente as informações solicitadas.</p>
            
            <p>Qualquer dúvida, estamos à disposição!</p>
        </div>
        
        <div class="footer">
            <p>© 2026 MultiLife Care</p>
        </div>
    </div>
</body>
</html>',
    NOW()
);

-- 3. Candidatura - Reprovada
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, subject_professional, template_professional_html, created_at) VALUES
(
    'Candidatura - Reprovada',
    'professional_application_rejected',
    'active',
    1,
    0,
    'Candidatura #{application_id} - Resultado da Avaliação',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #6b7280; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Resultado da Candidatura</h1>
        </div>
        
        <div class="content">
            <p>Olá {name},</p>
            
            <p>Agradecemos seu interesse em fazer parte da MultiLife Care.</p>
            
            <p>Infelizmente, sua candidatura <strong>#{application_id}</strong> não foi aprovada neste momento.</p>
            
            <p><strong>Motivo:</strong> {message}</p>
            
            <p>Você pode se candidatar novamente no futuro.</p>
            
            <p>Desejamos sucesso em sua carreira! 💙</p>
        </div>
        
        <div class="footer">
            <p>© 2026 MultiLife Care</p>
        </div>
    </div>
</body>
</html>',
    NOW()
);

-- 5. Documentos - Lembrete
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, subject_professional, template_professional_html, created_at) VALUES
(
    'Documentos - Lembrete de Vencimento',
    'professional_docs_reminder',
    'active',
    1,
    0,
    'Lembrete: Documento #{doc_id} - Vencimento Próximo',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f59e0b; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .warning { background: #fef3c7; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #f59e0b; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ Lembrete Importante</h1>
        </div>
        
        <div class="content">
            <p>Olá {name},</p>
            
            <div class="warning">
                <h3>Documento Próximo do Vencimento</h3>
                <p><strong>Documento:</strong> #{doc_id}</p>
                <p><strong>Paciente:</strong> {patient_ref}</p>
                <p><strong>Prazo:</strong> {due_at}</p>
            </div>
            
            <p>Por favor, envie o documento atualizado o quanto antes para evitar bloqueios.</p>
            
            <p>Acesse o sistema para fazer o upload.</p>
        </div>
        
        <div class="footer">
            <p>© 2026 MultiLife Care</p>
        </div>
    </div>
</body>
</html>',
    NOW()
);

-- 5. Documentos - Cobrança de Atraso
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, subject_professional, template_professional_html, created_at) VALUES
(
    'Documentos - Cobrança de Atraso',
    'professional_docs_overdue',
    'active',
    1,
    0,
    'URGENTE: Documento #{doc_id} - {days_overdue} Dias de Atraso',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc2626; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .alert { background: #fee2e2; padding: 20px; margin: 20px 0; border-radius: 8px; border: 2px solid #dc2626; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 URGENTE - Documento Atrasado</h1>
        </div>
        
        <div class="content">
            <p>Olá {name},</p>
            
            <div class="alert">
                <h3>Documento em Atraso</h3>
                <p><strong>Documento:</strong> #{doc_id}</p>
                <p><strong>Paciente:</strong> {patient_ref}</p>
                <p><strong>Prazo era:</strong> {due_at}</p>
                <p><strong>Atraso:</strong> {days_overdue} dias</p>
            </div>
            
            <p><strong>⚠️ Seu acesso pode ser bloqueado até a regularização.</strong></p>
            
            <p>Envie o documento HOJE através do sistema.</p>
        </div>
        
        <div class="footer">
            <p>© 2026 MultiLife Care</p>
        </div>
    </div>
</body>
</html>',
    NOW()
);

-- 6. Documentos - Aprovação
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, subject_professional, template_professional_html, created_at) VALUES
(
    'Documentos - Aprovação Confirmada',
    'professional_docs_approved',
    'active',
    1,
    0,
    'Documento #{doc_id} Aprovado - {sessions_count} Sessões Autorizadas',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #059669; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .success { background: #d1fae5; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #059669; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Documento Aprovado</h1>
        </div>
        
        <div class="content">
            <p>Olá {name},</p>
            
            <div class="success">
                <h3>Aprovação Confirmada</h3>
                <p><strong>Documento:</strong> #{doc_id}</p>
                <p><strong>Paciente:</strong> {patient_ref}</p>
                <p><strong>Sessões Autorizadas:</strong> {sessions_count}</p>
            </div>
            
            <p>Você já pode iniciar os atendimentos.</p>
            
            <p>Sucesso! 💙</p>
        </div>
        
        <div class="footer">
            <p>© 2026 MultiLife Care</p>
        </div>
    </div>
</body>
</html>',
    NOW()
);

-- 7. Agendamento - Notificação ao Paciente
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, subject_patient, template_patient_html, created_at) VALUES
(
    'Agendamento - Confirmação ao Paciente',
    'appointment_patient_notification',
    'active',
    0,
    1,
    'Agendamento Confirmado - {professional_name}',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .appointment { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #2563eb; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Agendamento Confirmado</h1>
        </div>
        
        <div class="content">
            <p>Olá {patient_name},</p>
            
            <p>Seu atendimento foi agendado com sucesso!</p>
            
            <div class="appointment">
                <h3>Detalhes do Agendamento</h3>
                <p><strong>👨‍⚕️ Profissional:</strong> {professional_name}</p>
                <p><strong>📅 Data/Hora:</strong> {first_at}</p>
                <p><strong>📍 Local:</strong> Atendimento domiciliar</p>
            </div>
            
            <p>Em caso de dúvidas ou necessidade de reagendar, entre em contato conosco.</p>
            
            <p>Até breve! 💙</p>
        </div>
        
        <div class="footer">
            <p>© 2026 MultiLife Care</p>
        </div>
    </div>
</body>
</html>',
    NOW()
);

-- Verificar templates criados
SELECT 
    'WhatsApp' as tipo,
    COUNT(*) as total_eventos
FROM whatsapp_events
UNION ALL
SELECT 
    'E-mail' as tipo,
    COUNT(*) as total_eventos
FROM email_events;

-- Listar todos os eventos criados
SELECT 
    'WhatsApp' as tipo,
    name,
    system_event,
    CASE 
        WHEN send_to_professional = 1 AND send_to_patient = 1 THEN 'Ambos'
        WHEN send_to_professional = 1 THEN 'Profissional'
        WHEN send_to_patient = 1 THEN 'Paciente'
        ELSE 'Nenhum'
    END as destinatario,
    status
FROM whatsapp_events
UNION ALL
SELECT 
    'E-mail' as tipo,
    name,
    system_event,
    CASE 
        WHEN send_to_professional = 1 AND send_to_patient = 1 THEN 'Ambos'
        WHEN send_to_professional = 1 THEN 'Profissional'
        WHEN send_to_patient = 1 THEN 'Paciente'
        ELSE 'Nenhum'
    END as destinatario,
    status
FROM email_events
ORDER BY tipo, system_event;
