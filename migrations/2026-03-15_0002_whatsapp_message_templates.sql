-- Migration: Sistema de Templates de Mensagem WhatsApp
-- Data: 2026-03-15
-- Descrição: Criar tabela para templates de mensagem personalizados por operadora e evento

-- Tabela principal de templates
CREATE TABLE IF NOT EXISTS whatsapp_message_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nome identificador do template',
    event_trigger VARCHAR(100) NOT NULL COMMENT 'Evento que dispara o template',
    health_insurer_id INT NULL COMMENT 'Operadora (obrigatório para pre_admission_confirmation)',
    message_body TEXT NOT NULL COMMENT 'Corpo da mensagem com variáveis',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Template ativo/inativo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    
    INDEX idx_event_trigger (event_trigger),
    INDEX idx_health_insurer (health_insurer_id),
    INDEX idx_is_active (is_active),
    
    FOREIGN KEY (health_insurer_id) REFERENCES health_insurers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    UNIQUE KEY uk_event_insurer (event_trigger, health_insurer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Templates de mensagem WhatsApp por evento e operadora';

-- Inserir alguns templates de exemplo
INSERT INTO whatsapp_message_templates (name, event_trigger, health_insurer_id, message_body, created_by_user_id) VALUES
(
    'Confirmação Pré-Admissão - Unimed',
    'pre_admission_confirmation',
    (SELECT id FROM health_insurers WHERE name = 'Unimed' LIMIT 1),
    'Olá {patient_name}! 👋

Sua pré-admissão foi confirmada com {professional_name}.

📋 *Documentos necessários para Unimed:*
• RG e CPF
• Carteirinha Unimed válida
• Guia SADT preenchida e assinada

📅 *Primeira sessão:* {date} às {time}
📍 *Local:* {location}

📎 Anexei os documentos que você precisa preencher.

Por favor, envie de volta preenchidos e assinados.

Qualquer dúvida, estamos à disposição! 😊',
    1
),
(
    'Confirmação Pré-Admissão - Particular',
    'pre_admission_confirmation',
    (SELECT id FROM health_insurers WHERE name = 'Particular' LIMIT 1),
    'Olá {patient_name}! 👋

Sua pré-admissão foi confirmada com {professional_name}.

📋 *Documentos necessários:*
• RG e CPF
• Comprovante de residência

📅 *Primeira sessão:* {date} às {time}
📍 *Local:* {location}
💰 *Valor:* R$ {value}

Qualquer dúvida, estamos à disposição! 😊',
    1
),
(
    'Agendamento Criado',
    'appointment_created',
    NULL,
    'Olá {patient_name}! 👋

Seu agendamento foi criado com sucesso!

👨‍⚕️ *Profissional:* {professional_name}
🏥 *Especialidade:* {specialty}
📅 *Data:* {date} às {time}
📍 *Local:* {location}

Aguardamos você! 😊',
    1
),
(
    'Lembrete de Agendamento',
    'appointment_reminder',
    NULL,
    '⏰ *Lembrete de Consulta*

Olá {patient_name}!

Lembramos que você tem consulta amanhã:

👨‍⚕️ *Profissional:* {professional_name}
📅 *Data:* {date} às {time}
📍 *Local:* {location}

Nos vemos em breve! 😊',
    1
),
(
    'Autorização Aprovada',
    'authorization_approved',
    NULL,
    '✅ *Autorização Aprovada!*

Olá {patient_name}!

Sua autorização foi aprovada pela operadora {health_insurer}.

👨‍⚕️ *Profissional:* {professional_name}
🏥 *Especialidade:* {specialty}
📅 *Início:* {date}

Em breve entraremos em contato para agendar! 😊',
    1
),
(
    'Autorização Negada',
    'authorization_denied',
    NULL,
    '❌ *Autorização Negada*

Olá {patient_name},

Infelizmente sua autorização foi negada pela operadora {health_insurer}.

Entre em contato conosco para verificar alternativas.

Estamos à disposição! 📞',
    1
),
(
    'Solicitação de Documentos',
    'document_request',
    NULL,
    '📄 *Solicitação de Documentos*

Olá {patient_name}!

Para darmos continuidade ao seu atendimento, precisamos dos seguintes documentos:

{documents_list}

Por favor, envie assim que possível.

Obrigado! 😊',
    1
);

-- Verificar templates criados
SELECT 
    t.id,
    t.name,
    t.event_trigger,
    COALESCE(h.name, 'Todas') as operadora,
    t.is_active
FROM whatsapp_message_templates t
LEFT JOIN health_insurers h ON h.id = t.health_insurer_id
ORDER BY t.event_trigger, h.name;
