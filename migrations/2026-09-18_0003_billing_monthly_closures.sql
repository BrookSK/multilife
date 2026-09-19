-- Migration: Fechamento mensal de faturamento por paciente
-- Data: 2026-09-18
--
-- Contexto (reunião 15/09): nova etapa de conferência e fechamento do faturamento
-- por COMPETÊNCIA mensal. Ao "Fechar mês", o sistema consolida as sessões realizadas
-- e aprovadas no período e gera:
--   - Contas a Receber (financial_entries income): 1 por paciente (soma authorized_value)
--   - Contas a Pagar   (financial_entries expense): 1 por profissional (soma agreed_value)
--
-- Proteção contra dupla contagem: cada sessão (billing_document_requirements) faturada
-- num fechamento recebe monthly_closure_id e não pode entrar em outro fechamento.
-- O estorno limpa essa marca e cancela os lançamentos gerados.

-- 1) Cabeçalho do fechamento por competência (YYYY-MM), única por mês.
CREATE TABLE IF NOT EXISTS billing_monthly_closures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_month VARCHAR(7) NOT NULL COMMENT 'Competência no formato YYYY-MM',
    status ENUM('closed','reversed') NOT NULL DEFAULT 'closed',

    -- Totais consolidados (snapshot no momento do fechamento)
    total_sessions INT UNSIGNED NOT NULL DEFAULT 0,
    total_receivable DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Total a receber das operadoras',
    total_payable DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Total a pagar aos profissionais',
    receivable_entries INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Qtd de lançamentos de receita gerados',
    payable_entries INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Qtd de lançamentos de despesa gerados',

    closed_by_user_id INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    reversed_by_user_id INT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    notes TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_bmc_reference_month (reference_month),
    KEY idx_bmc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Itens do fechamento: cada sessão (billing_document_requirements) que entrou no fechamento.
--    Permite rastrear/estornar exatamente o que foi consolidado.
CREATE TABLE IF NOT EXISTS billing_monthly_closure_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    closure_id BIGINT UNSIGNED NOT NULL,
    requirement_id BIGINT UNSIGNED NOT NULL COMMENT 'billing_document_requirements.id',
    assignment_id BIGINT UNSIGNED NULL,
    patient_id BIGINT UNSIGNED NULL,
    professional_user_id INT UNSIGNED NULL,
    health_insurer_id INT UNSIGNED NULL,
    session_date DATE NULL,
    receivable_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'authorized_value da sessão',
    payable_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'agreed_value da sessão',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_bmci_closure (closure_id),
    KEY idx_bmci_requirement (requirement_id),
    KEY idx_bmci_patient (patient_id),
    KEY idx_bmci_professional (professional_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Marca na sessão indicando em qual fechamento ela foi consolidada (NULL = ainda não faturada).
ALTER TABLE billing_document_requirements ADD COLUMN monthly_closure_id BIGINT UNSIGNED NULL;
CREATE INDEX idx_bdr_monthly_closure ON billing_document_requirements(monthly_closure_id);

-- 4) Vincular os lançamentos financeiros gerados ao fechamento (para estorno e rastreio).
ALTER TABLE financial_entries ADD COLUMN monthly_closure_id BIGINT UNSIGNED NULL;
CREATE INDEX idx_fe_monthly_closure ON financial_entries(monthly_closure_id);
