-- Migration: Cobertura (substituição por tempo determinado).
-- Data: 2026-10-03
--
-- Objetivo (Ajuste 7): permitir substituir um profissional TEMPORARIAMENTE
-- (cobertura). Durante o período, o atendimento fica com o profissional de
-- cobertura; ao fim (end_date), o profissional ORIGINAL volta automaticamente
-- (via cron/restore_coverages.php).

CREATE TABLE IF NOT EXISTS assignment_coverages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NULL,
    original_professional_user_id INT UNSIGNED NULL,
    cover_professional_user_id INT UNSIGNED NOT NULL,
    original_professional_jid VARCHAR(100) NULL,
    cover_professional_jid VARCHAR(100) NULL,
    -- Dados do atendimento durante a cobertura (para restaurar o original depois).
    original_agreed_value DECIMAL(10,2) NULL,
    cover_agreed_value DECIMAL(10,2) NULL,
    original_frequency VARCHAR(120) NULL,
    original_admitted_at DATETIME NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason VARCHAR(255) NULL,
    -- active = cobertura em vigor; finished = original já restaurado; cancelled = cancelada manualmente.
    status ENUM('active','finished','cancelled') NOT NULL DEFAULT 'active',
    restored_at DATETIME NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cov_assignment (assignment_id),
    KEY idx_cov_status (status),
    KEY idx_cov_end_date (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
