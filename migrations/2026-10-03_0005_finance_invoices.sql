-- Migration: Notas Fiscais do Financeiro.
-- Data: 2026-10-03
--
-- Objetivo (Ajuste 9): tela para subir notas fiscais correlacionadas ao que foi
-- pago/recebido, mantendo controle do arquivo e dos valores.

CREATE TABLE IF NOT EXISTS finance_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Direção: income (recebido) ou expense (pago).
    direction ENUM('income','expense') NOT NULL,
    -- Vínculos opcionais.
    financial_entry_id BIGINT UNSIGNED NULL,
    patient_id BIGINT UNSIGNED NULL,
    professional_user_id INT UNSIGNED NULL,
    client_id INT UNSIGNED NULL,
    health_insurer_id INT UNSIGNED NULL,
    -- Dados da nota.
    invoice_number VARCHAR(100) NULL,
    supplier_name VARCHAR(200) NULL,
    issue_date DATE NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    -- Arquivo.
    file_path VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NULL,
    file_type VARCHAR(100) NULL,
    file_size INT UNSIGNED NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fi_direction (direction),
    KEY idx_fi_entry (financial_entry_id),
    KEY idx_fi_patient (patient_id),
    KEY idx_fi_professional (professional_user_id),
    KEY idx_fi_issue_date (issue_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
