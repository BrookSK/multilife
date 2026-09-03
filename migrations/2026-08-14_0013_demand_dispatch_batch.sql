-- ============================================
-- PERFORMANCE DA CAPTAÇÃO - processamento em lotes/background (item 17)
-- Rastreabilidade + idempotência da adição de profissionais aos grupos.
-- Data: 2026-08-14
-- ============================================

-- Rastreia cada profissional-alvo de uma captação por grupo (idempotência + progresso)
CREATE TABLE IF NOT EXISTS demand_dispatch_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    demand_id BIGINT UNSIGNED NOT NULL,
    group_jid VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL COMMENT 'Número normalizado (só dígitos, com 55)',
    status ENUM('pending','added','error','skipped') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(255) NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_dispatch_target (demand_id, group_jid, phone),
    KEY idx_ddt_status (status),
    KEY idx_ddt_demand (demand_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cabeçalho do processamento da captação (para acompanhar progresso)
CREATE TABLE IF NOT EXISTS demand_dispatch_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    demand_id BIGINT UNSIGNED NOT NULL,
    group_jid VARCHAR(100) NOT NULL,
    group_db_id BIGINT UNSIGNED NULL,
    total_targets INT UNSIGNED NOT NULL DEFAULT 0,
    processed_targets INT UNSIGNED NOT NULL DEFAULT 0,
    added_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('queued','processing','completed','completed_with_errors') NOT NULL DEFAULT 'queued',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ddr_demand (demand_id),
    KEY idx_ddr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Tabelas de dispatch em lote criadas!' AS resultado;
