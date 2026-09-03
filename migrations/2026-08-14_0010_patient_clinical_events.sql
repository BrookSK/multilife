-- ============================================
-- EVENTOS CLÍNICOS DATADOS DO PACIENTE (item 7)
-- Internação, óbito, alta e outros eventos com data e hora.
-- Registro de óbito encerra atendimentos relacionados.
-- Data: 2026-08-14
-- ============================================

CREATE TABLE IF NOT EXISTS patient_clinical_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('internacao','obito','alta','retorno','transferencia','outro') NOT NULL,
    event_date DATE NOT NULL,
    event_time TIME NULL,
    notes TEXT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pce_patient (patient_id),
    KEY idx_pce_type (event_type),
    CONSTRAINT fk_pce_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marcador de encerramento do paciente (óbito)
ALTER TABLE patients
    ADD COLUMN IF NOT EXISTS is_closed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Paciente encerrado (ex: óbito)',
    ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS closed_reason VARCHAR(60) NULL;

SELECT 'Tabela patient_clinical_events criada!' AS resultado;
