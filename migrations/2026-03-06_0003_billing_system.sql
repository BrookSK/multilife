-- Sistema de Faturamento e Pendências de Documentos

-- Adicionar novos status ao patient_assignments
ALTER TABLE patient_assignments 
MODIFY COLUMN status ENUM('pending','confirmed','approved','admitted','awaiting_documents','awaiting_financial_approval','completed','cancelled') NOT NULL DEFAULT 'pending';

-- Tabela de pendências de documentos
CREATE TABLE IF NOT EXISTS billing_document_requirements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    professional_user_id INT UNSIGNED NOT NULL,
    
    -- Informações do atendimento
    session_number INT UNSIGNED NOT NULL DEFAULT 1,
    session_date DATE NULL,
    
    -- Status da pendência
    status ENUM('pending','uploaded','approved','rejected') NOT NULL DEFAULT 'pending',
    
    -- Documentos
    document_id BIGINT UNSIGNED NULL,
    uploaded_at DATETIME NULL,
    
    -- Aprovação/Rejeição
    reviewed_by_user_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    rejection_reason TEXT NULL,
    
    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY idx_billing_requirements_assignment (assignment_id),
    KEY idx_billing_requirements_patient (patient_id),
    KEY idx_billing_requirements_professional (professional_user_id),
    KEY idx_billing_requirements_status (status),
    KEY idx_billing_requirements_document (document_id),
    
    CONSTRAINT fk_billing_requirements_assignment FOREIGN KEY (assignment_id) REFERENCES patient_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_requirements_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_requirements_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_requirements_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_requirements_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de faturamento consolidado
CREATE TABLE IF NOT EXISTS billing_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    professional_user_id INT UNSIGNED NOT NULL,
    
    -- Valores
    total_sessions INT UNSIGNED NOT NULL,
    value_per_session DECIMAL(10,2) NOT NULL,
    total_value DECIMAL(10,2) NOT NULL,
    adjusted_value DECIMAL(10,2) NULL,
    final_value DECIMAL(10,2) NOT NULL,
    
    -- Ajustes
    adjustment_reason TEXT NULL,
    adjusted_by_user_id INT UNSIGNED NULL,
    adjusted_at DATETIME NULL,
    
    -- Status
    status ENUM('draft','pending_approval','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
    
    -- Aprovação
    approved_by_user_id INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    
    -- Pagamento
    paid_at DATETIME NULL,
    payment_reference VARCHAR(100) NULL,
    
    -- Cancelamento
    cancelled_by_user_id INT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason TEXT NULL,
    
    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY idx_billing_invoices_assignment (assignment_id),
    KEY idx_billing_invoices_patient (patient_id),
    KEY idx_billing_invoices_professional (professional_user_id),
    KEY idx_billing_invoices_status (status),
    
    CONSTRAINT fk_billing_invoices_assignment FOREIGN KEY (assignment_id) REFERENCES patient_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_invoices_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_invoices_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_invoices_adjusted_by FOREIGN KEY (adjusted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_invoices_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_invoices_cancelled_by FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de lançamentos financeiros
CREATE TABLE IF NOT EXISTS financial_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- Tipo de lançamento
    entry_type ENUM('income','expense') NOT NULL,
    category VARCHAR(100) NOT NULL,
    
    -- Referências
    invoice_id BIGINT UNSIGNED NULL,
    assignment_id BIGINT UNSIGNED NULL,
    patient_id BIGINT UNSIGNED NULL,
    professional_user_id INT UNSIGNED NULL,
    
    -- Valores
    amount DECIMAL(10,2) NOT NULL,
    description TEXT NULL,
    
    -- Data
    entry_date DATE NOT NULL,
    due_date DATE NULL,
    paid_date DATE NULL,
    
    -- Status
    status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
    
    -- Criação
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY idx_financial_entries_type (entry_type),
    KEY idx_financial_entries_category (category),
    KEY idx_financial_entries_invoice (invoice_id),
    KEY idx_financial_entries_assignment (assignment_id),
    KEY idx_financial_entries_patient (patient_id),
    KEY idx_financial_entries_professional (professional_user_id),
    KEY idx_financial_entries_date (entry_date),
    KEY idx_financial_entries_status (status),
    
    CONSTRAINT fk_financial_entries_invoice FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_financial_entries_assignment FOREIGN KEY (assignment_id) REFERENCES patient_assignments(id) ON DELETE SET NULL,
    CONSTRAINT fk_financial_entries_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
    CONSTRAINT fk_financial_entries_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_financial_entries_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar colunas ao patient_assignments se não existirem
ALTER TABLE patient_assignments 
ADD COLUMN IF NOT EXISTS admitted_at DATETIME NULL AFTER approved_at,
ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER admitted_at,
ADD COLUMN IF NOT EXISTS completed_by_user_id INT UNSIGNED NULL AFTER completed_at;

-- Adicionar índice para completed_by
ALTER TABLE patient_assignments 
ADD KEY IF NOT EXISTS idx_patient_assignments_completed_by (completed_by_user_id);
