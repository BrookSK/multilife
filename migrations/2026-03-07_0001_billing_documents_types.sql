-- Adicionar campos para tipos de documentos de faturamento

ALTER TABLE billing_document_requirements
ADD COLUMN document_type ENUM('produtividade', 'faturamento') NOT NULL DEFAULT 'produtividade' AFTER session_date;

-- Tabela para armazenar múltiplos arquivos por requisito
CREATE TABLE IF NOT EXISTS billing_document_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    requirement_id BIGINT UNSIGNED NOT NULL,
    document_type ENUM('produtividade', 'faturamento') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY idx_billing_files_requirement (requirement_id),
    KEY idx_billing_files_type (document_type),
    
    CONSTRAINT fk_billing_files_requirement FOREIGN KEY (requirement_id) REFERENCES billing_document_requirements(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_files_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
