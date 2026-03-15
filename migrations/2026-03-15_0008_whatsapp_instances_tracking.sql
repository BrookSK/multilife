-- Migration: Rastreamento de instâncias WhatsApp criadas pelo sistema
-- Data: 2026-03-15
-- Descrição: Criar tabela para rastrear instâncias WhatsApp criadas pelo software

CREATE TABLE IF NOT EXISTS whatsapp_instances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instance_name VARCHAR(100) NOT NULL UNIQUE COMMENT 'Nome da instância na Evolution API',
    token VARCHAR(255) NULL COMMENT 'Token da instância',
    owner_number VARCHAR(20) NULL COMMENT 'Número do dono (com DDI)',
    webhook_url VARCHAR(500) NULL COMMENT 'URL do webhook',
    status ENUM('active', 'inactive') DEFAULT 'active' COMMENT 'Status da instância',
    created_by INT UNSIGNED NULL COMMENT 'ID do usuário que criou',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_instance_name (instance_name),
    INDEX idx_status (status),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
