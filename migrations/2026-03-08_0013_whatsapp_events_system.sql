-- Sistema de Eventos e Templates WhatsApp
-- Permite gerenciar mensagens automáticas baseadas em eventos do sistema

-- Tabela de eventos WhatsApp
CREATE TABLE IF NOT EXISTS whatsapp_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nome descritivo do evento',
    system_event VARCHAR(100) NOT NULL COMMENT 'Identificador do evento do sistema',
    status ENUM('active', 'inactive') DEFAULT 'active',
    send_to_professional TINYINT(1) DEFAULT 0 COMMENT 'Enviar para profissional',
    send_to_patient TINYINT(1) DEFAULT 0 COMMENT 'Enviar para paciente',
    template_professional TEXT COMMENT 'Template de mensagem para profissional',
    template_patient TEXT COMMENT 'Template de mensagem para paciente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_system_event (system_event),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de links adicionais dos eventos
CREATE TABLE IF NOT EXISTS whatsapp_event_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    link_name VARCHAR(255) NOT NULL COMMENT 'Nome do link',
    link_url TEXT NOT NULL COMMENT 'URL do link',
    recipient_type ENUM('professional', 'patient', 'both') DEFAULT 'both',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES whatsapp_events(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de arquivos anexos dos eventos
CREATE TABLE IF NOT EXISTS whatsapp_event_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL COMMENT 'Nome do arquivo',
    file_path TEXT NOT NULL COMMENT 'Caminho do arquivo no servidor',
    file_type VARCHAR(50) COMMENT 'Tipo MIME do arquivo',
    file_size INT UNSIGNED COMMENT 'Tamanho do arquivo em bytes',
    recipient_type ENUM('professional', 'patient', 'both') DEFAULT 'both',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES whatsapp_events(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de log de envios (para auditoria)
CREATE TABLE IF NOT EXISTS whatsapp_event_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    recipient_type ENUM('professional', 'patient'),
    recipient_phone VARCHAR(20),
    recipient_name VARCHAR(255),
    message_sent TEXT COMMENT 'Mensagem final enviada (com variáveis substituídas)',
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    error_message TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES whatsapp_events(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id),
    INDEX idx_sent_at (sent_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir eventos padrão do sistema
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient) VALUES
('Atendimento atribuído ao profissional', 'attendance_assigned', 'active', 1, 0, 
'Olá {{profissional_nome}},\n\nVocê recebeu um novo atendimento.\n\nPaciente: {{paciente_nome}}\nData: {{data_atendimento}}\nID: {{id_atendimento}}\n\nAcesse: {{link_atendimento}}', 
NULL),

('Profissional recebeu atendimento', 'professional_received_attendance', 'active', 1, 0,
'Olá {{profissional_nome}},\n\nNovo atendimento disponível.\n\nPaciente: {{paciente_nome}}\nData: {{data_atendimento}}\n\nPor favor, acesse o sistema para mais detalhes.',
NULL),

('Profissional atrasou formulário', 'professional_form_delayed', 'active', 1, 0,
'Olá {{profissional_nome}},\n\nLembramos que o formulário do atendimento #{{id_atendimento}} está pendente.\n\nPaciente: {{paciente_nome}}\nPrazo: {{data_prazo}}\n\nPor favor, envie o mais breve possível.',
NULL),

('Profissional enviou formulário', 'professional_form_submitted', 'active', 0, 1,
NULL,
'Olá {{paciente_nome}},\n\nSeu formulário foi enviado pelo profissional {{profissional_nome}}.\n\nAtendimento: #{{id_atendimento}}\nData: {{data_atendimento}}\n\nEm breve você receberá mais informações.'),

('Atendimento finalizado', 'attendance_completed', 'active', 1, 1,
'Olá {{profissional_nome}},\n\nO atendimento #{{id_atendimento}} foi finalizado.\n\nPaciente: {{paciente_nome}}\nData: {{data_atendimento}}\n\nObrigado pelo seu trabalho!',
'Olá {{paciente_nome}},\n\nSeu atendimento foi finalizado.\n\nProfissional: {{profissional_nome}}\nData: {{data_atendimento}}\n\nAgradecemos pela confiança!'),

('Pré-admissão iniciada', 'preadmission_started', 'active', 0, 1,
NULL,
'Olá {{paciente_nome}},\n\nSua pré-admissão foi iniciada.\n\nID: {{id_preadmissao}}\nData: {{data_inicio}}\n\nAcompanhe o processo pelo sistema.'),

('Pré-admissão aprovada', 'preadmission_approved', 'active', 0, 1,
NULL,
'Olá {{paciente_nome}},\n\nSua pré-admissão foi aprovada!\n\nID: {{id_preadmissao}}\nData de aprovação: {{data_aprovacao}}\n\nEm breve entraremos em contato.'),

('Pré-admissão pendente', 'preadmission_pending', 'active', 0, 1,
NULL,
'Olá {{paciente_nome}},\n\nSua pré-admissão está pendente de documentação.\n\nID: {{id_preadmissao}}\n\nPor favor, envie os documentos solicitados.'),

('Paciente cadastrado', 'patient_registered', 'active', 0, 1,
NULL,
'Olá {{paciente_nome}},\n\nSeu cadastro foi realizado com sucesso!\n\nID: {{id_paciente}}\nData: {{data_cadastro}}\n\nBem-vindo ao nosso sistema.'),

('Consulta agendada', 'appointment_scheduled', 'active', 1, 1,
'Olá {{profissional_nome}},\n\nNova consulta agendada.\n\nPaciente: {{paciente_nome}}\nData: {{data_consulta}}\nHorário: {{horario_consulta}}',
'Olá {{paciente_nome}},\n\nSua consulta foi agendada!\n\nProfissional: {{profissional_nome}}\nData: {{data_consulta}}\nHorário: {{horario_consulta}}\n\nAté lá!'),

('Consulta cancelada', 'appointment_cancelled', 'active', 1, 1,
'Olá {{profissional_nome}},\n\nA consulta do paciente {{paciente_nome}} foi cancelada.\n\nData: {{data_consulta}}\nMotivo: {{motivo_cancelamento}}',
'Olá {{paciente_nome}},\n\nSua consulta foi cancelada.\n\nData: {{data_consulta}}\nMotivo: {{motivo_cancelamento}}\n\nEntre em contato para reagendar.');

-- Verificar resultado
SELECT 
    COUNT(*) as total_eventos,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as eventos_ativos,
    SUM(CASE WHEN send_to_professional = 1 THEN 1 ELSE 0 END) as eventos_profissional,
    SUM(CASE WHEN send_to_patient = 1 THEN 1 ELSE 0 END) as eventos_paciente
FROM whatsapp_events;
