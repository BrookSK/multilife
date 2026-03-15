-- Migration: Tabela para informações de captação do chat
-- Data: 2026-03-15
-- Descrição: Armazenar status, tipo e observações de captação por chat

CREATE TABLE IF NOT EXISTS chat_capture_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id VARCHAR(255) NOT NULL COMMENT 'ID do chat (JID do WhatsApp)',
    status VARCHAR(50) NULL COMMENT 'Status da captação (interessado, não_interessado, etc)',
    type VARCHAR(50) NULL COMMENT 'Tipo de contato (paciente, profissional, empresa, parceiro)',
    notes TEXT NULL COMMENT 'Observações sobre a captação',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    
    UNIQUE KEY uk_chat_id (chat_id),
    INDEX idx_status (status),
    INDEX idx_type (type),
    
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Informações de captação por chat do WhatsApp';

-- Verificar estrutura
SELECT 
    'TABELA CRIADA' as status,
    COUNT(*) as total_records
FROM chat_capture_info;
