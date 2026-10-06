-- Migration: Orientações por operadora (com variações).
-- Data: 2026-10-03
--
-- Cada operadora pode ter VÁRIAS variações de orientação (ex.: ANERY "com tablet"
-- e "sem tablet"; GLOBAL por região; "avaliação de enfermagem"). A captação
-- escolhe a variação na PRÉ-ADMISSÃO; o texto vai ao profissional junto com os
-- documentos, o link e a tabela de dias.
--
-- requires_credentials: quando 1, a variação pede login/senha de sistema externo
-- (tablet / SALVUS / Outcare), que são preenchidos na pré-admissão e injetados no
-- texto via {{login_externo}} / {{senha_externa}}.

CREATE TABLE IF NOT EXISTS health_insurer_orientations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    health_insurer_id INT UNSIGNED NOT NULL,
    label VARCHAR(160) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    requires_credentials TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hio_insurer (health_insurer_id),
    KEY idx_hio_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo da orientação escolhida no atendimento.
ALTER TABLE patient_assignments
    ADD COLUMN IF NOT EXISTS orientation_id BIGINT UNSIGNED NULL AFTER health_insurer_id;
