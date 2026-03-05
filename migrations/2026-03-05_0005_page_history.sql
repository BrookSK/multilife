-- Tabela para histórico de ações por página
CREATE TABLE IF NOT EXISTS page_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_url VARCHAR(255) NOT NULL,
    page_title VARCHAR(255) NOT NULL,
    action_type VARCHAR(100) NOT NULL,
    action_description TEXT NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id INT NULL,
    user_id INT NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_url (page_url),
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
