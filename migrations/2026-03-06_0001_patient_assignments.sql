-- Tabela para armazenar atribuições de pacientes a profissionais
CREATE TABLE IF NOT EXISTS patient_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    demand_id BIGINT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NOT NULL,
    professional_remote_jid VARCHAR(100) NOT NULL,
    professional_user_id INT UNSIGNED NULL,
    assigned_by_user_id INT UNSIGNED NOT NULL,
    
    -- Dados do atendimento
    specialty VARCHAR(120) NULL,
    service_type VARCHAR(120) NULL,
    session_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    session_frequency VARCHAR(50) NULL,
    payment_value DECIMAL(10,2) NOT NULL,
    
    -- Observações e notas
    notes TEXT NULL,
    
    -- Status da atribuição
    status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    
    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    
    PRIMARY KEY (id),
    KEY idx_patient_assignments_demand (demand_id),
    KEY idx_patient_assignments_patient (patient_id),
    KEY idx_patient_assignments_professional_jid (professional_remote_jid),
    KEY idx_patient_assignments_professional_user (professional_user_id),
    KEY idx_patient_assignments_assigned_by (assigned_by_user_id),
    KEY idx_patient_assignments_status (status),
    KEY idx_patient_assignments_created (created_at),
    
    CONSTRAINT fk_patient_assignments_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE,
    CONSTRAINT fk_patient_assignments_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_patient_assignments_professional_user FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_patient_assignments_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para configurações operacionais (mensagens padrão)
CREATE TABLE IF NOT EXISTS operational_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_operational_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir mensagem padrão para atribuição de paciente
INSERT INTO operational_settings (setting_key, setting_value, description) VALUES
('assignment_message_template', 
'Olá! 👋

Temos uma ótima notícia! Um novo paciente foi atribuído para você.

📋 *Informações do Atendimento:*
• Paciente: {patient_name}
• Especialidade: {specialty}
• Serviço: {service_type}
• Quantidade de sessões: {session_quantity}
• Frequência: {session_frequency}
• Valor por sessão: R$ {payment_value}

Por favor, entre em contato com o paciente o mais breve possível para agendar a primeira sessão.

Em caso de dúvidas, estamos à disposição!

Atenciosamente,
Equipe MultiLife',
'Mensagem padrão enviada ao profissional quando um paciente é atribuído')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Adicionar coluna para vincular chat_contacts com demands
ALTER TABLE chat_contacts 
ADD COLUMN demand_id BIGINT UNSIGNED NULL AFTER is_group,
ADD KEY idx_chat_contacts_demand (demand_id);
