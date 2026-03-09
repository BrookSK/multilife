-- Sistema de Eventos e Templates de E-mail
-- Permite gerenciar e-mails automáticos baseados em eventos do sistema

-- Tabela de eventos de e-mail
CREATE TABLE IF NOT EXISTS email_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nome descritivo do evento',
    system_event VARCHAR(100) NOT NULL COMMENT 'Identificador do evento do sistema',
    status ENUM('active', 'inactive') DEFAULT 'active',
    send_to_professional TINYINT(1) DEFAULT 0 COMMENT 'Enviar para profissional',
    send_to_patient TINYINT(1) DEFAULT 0 COMMENT 'Enviar para paciente',
    
    -- Templates para profissional
    subject_professional VARCHAR(255) COMMENT 'Assunto do e-mail para profissional',
    template_professional_html TEXT COMMENT 'Template HTML para profissional',
    template_professional_text TEXT COMMENT 'Template texto plano para profissional',
    
    -- Templates para paciente
    subject_patient VARCHAR(255) COMMENT 'Assunto do e-mail para paciente',
    template_patient_html TEXT COMMENT 'Template HTML para paciente',
    template_patient_text TEXT COMMENT 'Template texto plano para paciente',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_system_event (system_event),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de links adicionais dos eventos de e-mail
CREATE TABLE IF NOT EXISTS email_event_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    link_name VARCHAR(255) NOT NULL COMMENT 'Nome do link',
    link_url TEXT NOT NULL COMMENT 'URL do link',
    recipient_type ENUM('professional', 'patient', 'both') DEFAULT 'both',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES email_events(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de arquivos anexos dos eventos de e-mail
CREATE TABLE IF NOT EXISTS email_event_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL COMMENT 'Nome do arquivo',
    file_path TEXT NOT NULL COMMENT 'Caminho do arquivo no servidor',
    file_type VARCHAR(50) COMMENT 'Tipo MIME do arquivo',
    file_size INT UNSIGNED COMMENT 'Tamanho do arquivo em bytes',
    recipient_type ENUM('professional', 'patient', 'both') DEFAULT 'both',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES email_events(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de log de envios de e-mail (para auditoria)
CREATE TABLE IF NOT EXISTS email_event_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    recipient_type ENUM('professional', 'patient'),
    recipient_email VARCHAR(255),
    recipient_name VARCHAR(255),
    subject VARCHAR(255) COMMENT 'Assunto do e-mail enviado',
    message_sent TEXT COMMENT 'Mensagem final enviada (com variáveis substituídas)',
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    error_message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES email_events(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id),
    INDEX idx_sent_at (sent_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir eventos padrão do sistema
INSERT INTO email_events (name, system_event, status, send_to_professional, send_to_patient, 
    subject_professional, template_professional_html, template_professional_text,
    subject_patient, template_patient_html, template_patient_text) VALUES

('Atendimento atribuído ao profissional', 'attendance_assigned', 'active', 1, 0,
'Novo Atendimento - {{paciente_nome}}',
'<h2>Olá {{profissional_nome}},</h2>
<p>Você recebeu um novo atendimento.</p>
<p><strong>Paciente:</strong> {{paciente_nome}}<br>
<strong>Data:</strong> {{data_atendimento}}<br>
<strong>ID:</strong> {{id_atendimento}}</p>
<p><a href="{{link_atendimento}}" style="background:#00a884;color:#fff;padding:10px 20px;text-decoration:none;border-radius:5px">Acessar Atendimento</a></p>',
'Olá {{profissional_nome}},\n\nVocê recebeu um novo atendimento.\n\nPaciente: {{paciente_nome}}\nData: {{data_atendimento}}\nID: {{id_atendimento}}\n\nAcesse: {{link_atendimento}}',
NULL, NULL, NULL),

('Profissional recebeu atendimento', 'professional_received_attendance', 'active', 1, 0,
'Novo Atendimento Disponível',
'<h2>Olá {{profissional_nome}},</h2>
<p>Novo atendimento disponível.</p>
<p><strong>Paciente:</strong> {{paciente_nome}}<br>
<strong>Data:</strong> {{data_atendimento}}</p>
<p>Por favor, acesse o sistema para mais detalhes.</p>',
'Olá {{profissional_nome}},\n\nNovo atendimento disponível.\n\nPaciente: {{paciente_nome}}\nData: {{data_atendimento}}\n\nPor favor, acesse o sistema para mais detalhes.',
NULL, NULL, NULL),

('Profissional atrasou formulário', 'professional_form_delayed', 'active', 1, 0,
'Lembrete: Formulário Pendente - #{{id_atendimento}}',
'<h2>Olá {{profissional_nome}},</h2>
<p>Lembramos que o formulário do atendimento <strong>#{{id_atendimento}}</strong> está pendente.</p>
<p><strong>Paciente:</strong> {{paciente_nome}}<br>
<strong>Prazo:</strong> {{data_prazo}}</p>
<p>Por favor, envie o mais breve possível.</p>',
'Olá {{profissional_nome}},\n\nLembramos que o formulário do atendimento #{{id_atendimento}} está pendente.\n\nPaciente: {{paciente_nome}}\nPrazo: {{data_prazo}}\n\nPor favor, envie o mais breve possível.',
NULL, NULL, NULL),

('Atendimento finalizado', 'attendance_completed', 'active', 1, 1,
'Atendimento Finalizado - #{{id_atendimento}}',
'<h2>Olá {{profissional_nome}},</h2>
<p>O atendimento <strong>#{{id_atendimento}}</strong> foi finalizado.</p>
<p><strong>Paciente:</strong> {{paciente_nome}}<br>
<strong>Data:</strong> {{data_atendimento}}</p>
<p>Obrigado pelo seu trabalho!</p>',
'Olá {{profissional_nome}},\n\nO atendimento #{{id_atendimento}} foi finalizado.\n\nPaciente: {{paciente_nome}}\nData: {{data_atendimento}}\n\nObrigado pelo seu trabalho!',
'Seu Atendimento foi Finalizado',
'<h2>Olá {{paciente_nome}},</h2>
<p>Seu atendimento foi finalizado.</p>
<p><strong>Profissional:</strong> {{profissional_nome}}<br>
<strong>Data:</strong> {{data_atendimento}}</p>
<p>Agradecemos pela confiança!</p>',
'Olá {{paciente_nome}},\n\nSeu atendimento foi finalizado.\n\nProfissional: {{profissional_nome}}\nData: {{data_atendimento}}\n\nAgradecemos pela confiança!'),

('Pré-admissão aprovada', 'preadmission_approved', 'active', 0, 1,
NULL, NULL, NULL,
'Pré-admissão Aprovada - #{{id_preadmissao}}',
'<h2>Olá {{paciente_nome}},</h2>
<p>Sua pré-admissão foi <strong>aprovada</strong>!</p>
<p><strong>ID:</strong> {{id_preadmissao}}<br>
<strong>Data de aprovação:</strong> {{data_aprovacao}}</p>
<p>Em breve entraremos em contato.</p>',
'Olá {{paciente_nome}},\n\nSua pré-admissão foi aprovada!\n\nID: {{id_preadmissao}}\nData de aprovação: {{data_aprovacao}}\n\nEm breve entraremos em contato.'),

('Paciente cadastrado', 'patient_registered', 'active', 0, 1,
NULL, NULL, NULL,
'Bem-vindo ao Sistema!',
'<h2>Olá {{paciente_nome}},</h2>
<p>Seu cadastro foi realizado com <strong>sucesso</strong>!</p>
<p><strong>ID:</strong> {{id_paciente}}<br>
<strong>Data:</strong> {{data_cadastro}}</p>
<p>Bem-vindo ao nosso sistema.</p>',
'Olá {{paciente_nome}},\n\nSeu cadastro foi realizado com sucesso!\n\nID: {{id_paciente}}\nData: {{data_cadastro}}\n\nBem-vindo ao nosso sistema.'),

('Consulta agendada', 'appointment_scheduled', 'active', 1, 1,
'Nova Consulta Agendada - {{data_consulta}}',
'<h2>Olá {{profissional_nome}},</h2>
<p>Nova consulta agendada.</p>
<p><strong>Paciente:</strong> {{paciente_nome}}<br>
<strong>Data:</strong> {{data_consulta}}<br>
<strong>Horário:</strong> {{horario_consulta}}</p>',
'Olá {{profissional_nome}},\n\nNova consulta agendada.\n\nPaciente: {{paciente_nome}}\nData: {{data_consulta}}\nHorário: {{horario_consulta}}',
'Sua Consulta foi Agendada!',
'<h2>Olá {{paciente_nome}},</h2>
<p>Sua consulta foi agendada!</p>
<p><strong>Profissional:</strong> {{profissional_nome}}<br>
<strong>Data:</strong> {{data_consulta}}<br>
<strong>Horário:</strong> {{horario_consulta}}</p>
<p>Até lá!</p>',
'Olá {{paciente_nome}},\n\nSua consulta foi agendada!\n\nProfissional: {{profissional_nome}}\nData: {{data_consulta}}\nHorário: {{horario_consulta}}\n\nAté lá!'),

('Consulta cancelada', 'appointment_cancelled', 'active', 1, 1,
'Consulta Cancelada - {{data_consulta}}',
'<h2>Olá {{profissional_nome}},</h2>
<p>A consulta do paciente <strong>{{paciente_nome}}</strong> foi cancelada.</p>
<p><strong>Data:</strong> {{data_consulta}}<br>
<strong>Motivo:</strong> {{motivo_cancelamento}}</p>',
'Olá {{profissional_nome}},\n\nA consulta do paciente {{paciente_nome}} foi cancelada.\n\nData: {{data_consulta}}\nMotivo: {{motivo_cancelamento}}',
'Consulta Cancelada',
'<h2>Olá {{paciente_nome}},</h2>
<p>Sua consulta foi cancelada.</p>
<p><strong>Data:</strong> {{data_consulta}}<br>
<strong>Motivo:</strong> {{motivo_cancelamento}}</p>
<p>Entre em contato para reagendar.</p>',
'Olá {{paciente_nome}},\n\nSua consulta foi cancelada.\n\nData: {{data_consulta}}\nMotivo: {{motivo_cancelamento}}\n\nEntre em contato para reagendar.');

-- Verificar resultado
SELECT 
    COUNT(*) as total_eventos,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as eventos_ativos,
    SUM(CASE WHEN send_to_professional = 1 THEN 1 ELSE 0 END) as eventos_profissional,
    SUM(CASE WHEN send_to_patient = 1 THEN 1 ELSE 0 END) as eventos_paciente
FROM email_events;
