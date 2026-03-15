-- Migration: Sistema de Anexos para Templates WhatsApp
-- Data: 2026-03-15
-- Descrição: Criar tabela para armazenar até 5 arquivos por template
-- IMPORTANTE: Execute a migration 2026-03-15_0002_whatsapp_message_templates.sql ANTES desta

-- Verificar se tabela pai existe
SET @table_exists = (SELECT COUNT(*) FROM information_schema.tables 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'whatsapp_message_templates');

-- Se tabela pai não existe, abortar com mensagem clara
SELECT IF(@table_exists = 0, 
    'ERRO: Execute primeiro a migration 2026-03-15_0002_whatsapp_message_templates.sql', 
    'OK: Tabela whatsapp_message_templates encontrada') AS status;

-- Tabela de anexos dos templates
CREATE TABLE IF NOT EXISTS whatsapp_template_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL COMMENT 'ID do template',
    file_name VARCHAR(255) NOT NULL COMMENT 'Nome original do arquivo',
    file_path VARCHAR(500) NOT NULL COMMENT 'Caminho no servidor',
    file_size INT NOT NULL COMMENT 'Tamanho em bytes',
    mime_type VARCHAR(100) NOT NULL COMMENT 'Tipo MIME do arquivo',
    display_order TINYINT DEFAULT 1 COMMENT 'Ordem de exibição (1-5)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_template_id (template_id),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Anexos (até 5 arquivos) para templates de mensagem WhatsApp';

-- Adicionar foreign key apenas se tabela pai existe
SET @add_fk = IF(@table_exists > 0,
    'ALTER TABLE whatsapp_template_attachments 
     ADD CONSTRAINT fk_template_attachments_template 
     FOREIGN KEY (template_id) REFERENCES whatsapp_message_templates(id) ON DELETE CASCADE',
    'SELECT "AVISO: Foreign key não criada - tabela pai não existe" AS warning');

PREPARE stmt FROM @add_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Criar diretório de uploads (via PHP será criado se não existir)
-- Caminho: /uploads/whatsapp_templates/{template_id}/

-- Verificar estrutura
SELECT 
    'TABELA CRIADA' as status,
    COUNT(*) as total_attachments
FROM whatsapp_template_attachments;
