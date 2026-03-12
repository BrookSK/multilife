-- Sistema de Autorização de Propostas
-- Gerencia o fluxo de envio de propostas para operadoras e análise de respostas

-- Tabela de solicitações de autorização
CREATE TABLE IF NOT EXISTS authorization_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    demand_id INT UNSIGNED NOT NULL COMMENT 'Demanda original',
    professional_user_id INT UNSIGNED NOT NULL COMMENT 'Profissional selecionado',
    
    -- Dados da proposta
    proposal_value DECIMAL(10,2) NOT NULL COMMENT 'Valor oferecido à operadora',
    agreed_value DECIMAL(10,2) NOT NULL COMMENT 'Valor acordado com profissional',
    
    -- Dados do agendamento proposto
    start_date DATE NOT NULL COMMENT 'Data de início do atendimento',
    start_time TIME NOT NULL COMMENT 'Hora de início',
    end_time TIME NOT NULL COMMENT 'Hora de término',
    frequency ENUM('single', 'daily', 'weekly', 'biweekly', 'monthly', 'custom') DEFAULT 'single' COMMENT 'Frequência do atendimento',
    frequency_details TEXT COMMENT 'Detalhes da frequência (JSON com dias da semana, quantidade de sessões, etc)',
    total_sessions INT UNSIGNED COMMENT 'Total de sessões calculadas',
    duration_weeks INT UNSIGNED COMMENT 'Duração em semanas',
    
    -- Dados de contato
    operator_email VARCHAR(255) NOT NULL COMMENT 'E-mail da operadora',
    operator_name VARCHAR(255) COMMENT 'Nome da operadora/convênio',
    
    -- Controle de envio e resposta
    sent_at TIMESTAMP NULL COMMENT 'Quando a proposta foi enviada',
    email_thread_id VARCHAR(255) COMMENT 'Thread ID do e-mail para identificar respostas',
    email_message_id VARCHAR(255) COMMENT 'Message ID do e-mail enviado',
    response_received_at TIMESTAMP NULL COMMENT 'Quando a resposta foi recebida',
    response_deadline TIMESTAMP NULL COMMENT 'Prazo para resposta (sent_at + 5 minutos)',
    
    -- Status e análise
    status ENUM('aguardando_autorizacao', 'autorizacao_aprovada', 'autorizacao_negada', 'cancelada') DEFAULT 'aguardando_autorizacao',
    ai_analysis TEXT COMMENT 'Análise da IA sobre a resposta recebida',
    denial_reason TEXT COMMENT 'Motivo da negação extraído pela IA',
    
    -- Relacionamentos
    inbound_email_id INT UNSIGNED COMMENT 'ID do e-mail de resposta recebido',
    patient_assignment_id INT UNSIGNED COMMENT 'ID do atendimento criado (se aprovado)',
    
    -- Reenvios
    resend_count INT UNSIGNED DEFAULT 0 COMMENT 'Quantidade de vezes que a proposta foi reenviada',
    previous_request_id INT UNSIGNED COMMENT 'ID da solicitação anterior (se for reenvio)',
    
    -- Auditoria
    created_by_user_id INT UNSIGNED COMMENT 'Usuário que criou a solicitação',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Índices
    INDEX idx_demand_id (demand_id),
    INDEX idx_professional_user_id (professional_user_id),
    INDEX idx_status (status),
    INDEX idx_email_thread_id (email_thread_id),
    INDEX idx_sent_at (sent_at),
    INDEX idx_response_deadline (response_deadline),
    INDEX idx_previous_request_id (previous_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar novos status à tabela demands
ALTER TABLE demands 
MODIFY COLUMN status ENUM(
    'aguardando_captacao',
    'tratamento_manual',
    'em_captacao',
    'aguardando_autorizacao',
    'autorizacao_aprovada',
    'autorizacao_negada',
    'admitido',
    'concluido',
    'cancelado'
) NOT NULL DEFAULT 'aguardando_captacao';

-- Tabela de histórico de propostas (para rastreabilidade)
CREATE TABLE IF NOT EXISTS authorization_request_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    authorization_request_id INT UNSIGNED NOT NULL,
    action ENUM('created', 'sent', 'response_received', 'approved', 'denied', 'resent', 'cancelled') NOT NULL,
    proposal_value DECIMAL(10,2) COMMENT 'Valor da proposta neste momento',
    notes TEXT COMMENT 'Observações sobre a ação',
    user_id INT UNSIGNED COMMENT 'Usuário que executou a ação',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_authorization_request_id (authorization_request_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar campo para armazenar thread_id em inbound_emails (se não existir)
ALTER TABLE inbound_emails 
ADD COLUMN IF NOT EXISTS thread_id VARCHAR(255) COMMENT 'Thread ID para identificar conversas' AFTER message_id,
ADD INDEX IF NOT EXISTS idx_thread_id (thread_id);

-- Verificar estrutura criada
SELECT 
    'authorization_requests' as tabela,
    COUNT(*) as total_registros
FROM authorization_requests
UNION ALL
SELECT 
    'authorization_request_history' as tabela,
    COUNT(*) as total_registros
FROM authorization_request_history;
