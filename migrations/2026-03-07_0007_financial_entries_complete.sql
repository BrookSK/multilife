-- ============================================
-- SISTEMA COMPLETO DE GERENCIAMENTO FINANCEIRO
-- Adiciona campos para parcelamento, recorrência e controle avançado
-- ============================================

-- Adicionar campos à tabela financial_entries
ALTER TABLE financial_entries
ADD COLUMN IF NOT EXISTS payment_type ENUM('single', 'installment', 'recurring', 'continuous') NOT NULL DEFAULT 'single' AFTER status,
ADD COLUMN IF NOT EXISTS installment_number INT NULL AFTER payment_type,
ADD COLUMN IF NOT EXISTS total_installments INT NULL AFTER installment_number,
ADD COLUMN IF NOT EXISTS parent_entry_id BIGINT UNSIGNED NULL AFTER total_installments,
ADD COLUMN IF NOT EXISTS recurrence_frequency ENUM('daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'annual') NULL AFTER parent_entry_id,
ADD COLUMN IF NOT EXISTS recurrence_end_date DATE NULL AFTER recurrence_frequency,
ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER recurrence_end_date,
ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER is_active,
ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) NULL AFTER notes,
ADD COLUMN IF NOT EXISTS document_number VARCHAR(100) NULL AFTER payment_method,
ADD COLUMN IF NOT EXISTS supplier_name VARCHAR(200) NULL AFTER document_number,
ADD COLUMN IF NOT EXISTS cost_center VARCHAR(100) NULL AFTER supplier_name;

-- Criar índices para os novos campos
CREATE INDEX IF NOT EXISTS idx_financial_entries_payment_type ON financial_entries(payment_type);
CREATE INDEX IF NOT EXISTS idx_financial_entries_parent ON financial_entries(parent_entry_id);
CREATE INDEX IF NOT EXISTS idx_financial_entries_recurrence ON financial_entries(recurrence_frequency);
CREATE INDEX IF NOT EXISTS idx_financial_entries_active ON financial_entries(is_active);
CREATE INDEX IF NOT EXISTS idx_financial_entries_due_date ON financial_entries(due_date);

-- Adicionar constraint para parent_entry_id (se não existir)
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'financial_entries' 
    AND CONSTRAINT_NAME = 'fk_financial_entries_parent'
);

SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE financial_entries ADD CONSTRAINT fk_financial_entries_parent FOREIGN KEY (parent_entry_id) REFERENCES financial_entries(id) ON DELETE CASCADE',
    'SELECT "Constraint já existe" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Criar tabela de categorias financeiras
CREATE TABLE IF NOT EXISTS financial_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('income', 'expense', 'both') NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_financial_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir categorias padrão
INSERT INTO financial_categories (name, type, description) VALUES
('Atendimento Médico', 'income', 'Receitas de atendimentos médicos'),
('Consulta', 'income', 'Receitas de consultas'),
('Procedimento', 'income', 'Receitas de procedimentos'),
('Exame', 'income', 'Receitas de exames'),
('Outras Receitas', 'income', 'Outras receitas diversas'),
('Repasse Profissional', 'expense', 'Pagamento de repasses para profissionais'),
('Aluguel', 'expense', 'Despesas com aluguel'),
('Energia Elétrica', 'expense', 'Despesas com energia elétrica'),
('Água', 'expense', 'Despesas com água'),
('Internet', 'expense', 'Despesas com internet'),
('Telefone', 'expense', 'Despesas com telefone'),
('Material de Escritório', 'expense', 'Despesas com material de escritório'),
('Material Médico', 'expense', 'Despesas com material médico'),
('Limpeza', 'expense', 'Despesas com limpeza'),
('Manutenção', 'expense', 'Despesas com manutenção'),
('Marketing', 'expense', 'Despesas com marketing'),
('Salários', 'expense', 'Despesas com salários'),
('Impostos', 'expense', 'Despesas com impostos'),
('Outras Despesas', 'expense', 'Outras despesas diversas')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Criar tabela de centros de custo
CREATE TABLE IF NOT EXISTS cost_centers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cost_centers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir centros de custo padrão
INSERT INTO cost_centers (name, description) VALUES
('Administrativo', 'Despesas administrativas'),
('Clínico', 'Despesas clínicas'),
('Marketing', 'Despesas com marketing'),
('Infraestrutura', 'Despesas com infraestrutura'),
('Recursos Humanos', 'Despesas com RH')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Criar view para facilitar consultas de lançamentos
CREATE OR REPLACE VIEW vw_financial_entries_complete AS
SELECT 
    fe.id,
    fe.entry_type,
    fe.category,
    fe.amount,
    fe.description,
    fe.entry_date,
    fe.due_date,
    fe.paid_date,
    fe.status,
    fe.payment_type,
    fe.installment_number,
    fe.total_installments,
    fe.parent_entry_id,
    fe.recurrence_frequency,
    fe.recurrence_end_date,
    fe.is_active,
    fe.notes,
    fe.payment_method,
    fe.document_number,
    fe.supplier_name,
    fe.cost_center,
    fe.invoice_id,
    fe.assignment_id,
    fe.patient_id,
    fe.professional_user_id,
    p.full_name as patient_name,
    u.name as professional_name,
    bi.id as invoice_id_ref,
    bi.status as invoice_status,
    pa.specialty,
    fe.created_at,
    fe.updated_at,
    CASE 
        WHEN fe.payment_type = 'installment' AND fe.total_installments > 0 
        THEN CONCAT(fe.installment_number, '/', fe.total_installments)
        ELSE NULL
    END as installment_info,
    CASE 
        WHEN fe.status = 'paid' THEN 'Pago'
        WHEN fe.status = 'pending' AND fe.due_date < CURDATE() THEN 'Vencido'
        WHEN fe.status = 'pending' THEN 'Pendente'
        WHEN fe.status = 'cancelled' THEN 'Cancelado'
    END as status_label
FROM financial_entries fe
LEFT JOIN patients p ON p.id = fe.patient_id
LEFT JOIN users u ON u.id = fe.professional_user_id
LEFT JOIN billing_invoices bi ON bi.id = fe.invoice_id
LEFT JOIN patient_assignments pa ON pa.id = fe.assignment_id;
