-- Adicionar colunas de status e captação aos contatos
ALTER TABLE chat_contacts 
ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'aguardando' COMMENT 'Status: atendendo, aguardando, resolvido',
ADD COLUMN IF NOT EXISTS assigned_to_user_id INT UNSIGNED DEFAULT NULL COMMENT 'ID do usuário responsável',
ADD COLUMN IF NOT EXISTS capture_type VARCHAR(50) DEFAULT NULL COMMENT 'Tipo de captação',
ADD COLUMN IF NOT EXISTS capture_notes TEXT DEFAULT NULL COMMENT 'Observações da captação',
ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Data de resolução',
ADD INDEX idx_status (status),
ADD INDEX idx_assigned_user (assigned_to_user_id);
