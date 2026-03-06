-- Sistema de Gestão Documental
-- Nota: O sistema já possui a tabela patient_prontuario_entries para prontuário de pacientes
-- Esta migration adiciona apenas o sistema de pastas e documentos

-- Tabela de pastas de documentos (uma pasta por usuário/paciente)
CREATE TABLE IF NOT EXISTS document_folders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    folder_name VARCHAR(255) NOT NULL,
    entity_type ENUM('patient', 'professional', 'employee') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    description TEXT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    UNIQUE KEY idx_document_folders_entity (entity_type, entity_id),
    KEY idx_document_folders_type (entity_type),
    KEY idx_document_folders_created_by (created_by_user_id),
    
    CONSTRAINT fk_document_folders_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de documentos
CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    folder_id BIGINT UNSIGNED NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    document_type VARCHAR(100) NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) NULL,
    
    -- Metadados do documento
    document_date DATE NULL,
    description TEXT NULL,
    category VARCHAR(100) NULL,
    tags TEXT NULL,
    
    -- Relacionamento com atendimento (opcional)
    assignment_id BIGINT UNSIGNED NULL,
    
    -- Controle de versão
    version INT UNSIGNED NOT NULL DEFAULT 1,
    is_current_version TINYINT(1) NOT NULL DEFAULT 1,
    
    -- Controle de acesso
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    
    PRIMARY KEY (id),
    KEY idx_documents_folder (folder_id),
    KEY idx_documents_assignment (assignment_id),
    KEY idx_documents_type (document_type),
    KEY idx_documents_category (category),
    KEY idx_documents_date (document_date),
    KEY idx_documents_uploaded_by (uploaded_by_user_id),
    KEY idx_documents_deleted (deleted_at),
    
    CONSTRAINT fk_documents_folder FOREIGN KEY (folder_id) REFERENCES document_folders(id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_assignment FOREIGN KEY (assignment_id) REFERENCES patient_assignments(id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de histórico de versões de documentos
CREATE TABLE IF NOT EXISTS document_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    change_description TEXT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY idx_document_versions_document (document_id),
    KEY idx_document_versions_version (version),
    
    CONSTRAINT fk_document_versions_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_versions_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
