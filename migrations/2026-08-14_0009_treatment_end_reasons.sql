-- ============================================
-- FINALIZAÇÃO DE ATENDIMENTO COM MOTIVO (itens 5 e 11)
-- Motivos configuráveis + campos de encerramento no assignment
-- Data: 2026-08-14
-- ============================================

-- Tabela de motivos de encerramento (configuráveis)
CREATE TABLE IF NOT EXISTS treatment_end_reasons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = motivo padrão do sistema (não excluível)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_end_reason_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Motivos padrão
INSERT INTO treatment_end_reasons (name, slug, is_system) VALUES
  ('Atendimento finalizado normalmente', 'finalizado_normal', 1),
  ('Óbito', 'obito', 1),
  ('Alta', 'alta', 1),
  ('Transferência', 'transferencia', 1),
  ('Cancelamento', 'cancelamento', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Campos de encerramento no patient_assignments
ALTER TABLE patient_assignments
    ADD COLUMN IF NOT EXISTS ended_at DATETIME NULL COMMENT 'Data/hora do encerramento do atendimento',
    ADD COLUMN IF NOT EXISTS end_reason_id INT UNSIGNED NULL COMMENT 'FK treatment_end_reasons',
    ADD COLUMN IF NOT EXISTS end_notes TEXT NULL COMMENT 'Observações do encerramento',
    ADD COLUMN IF NOT EXISTS ended_by_user_id INT UNSIGNED NULL;

SELECT 'Motivos de encerramento criados!' AS resultado;
