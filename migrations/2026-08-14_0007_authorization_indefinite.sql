-- ============================================
-- Pré-Admissão: Tempo Indefinido
-- Adiciona flag is_indefinite em authorization_requests
-- Quando 1, o atendimento não exige data final nem número de semanas.
-- Data: 2026-08-14
-- ============================================

ALTER TABLE authorization_requests
    ADD COLUMN IF NOT EXISTS is_indefinite TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Se 1, atendimento por tempo indefinido (sem data final/semanas fixas)';

-- Também refletir no patient_assignments para o monitoramento/atendimento
ALTER TABLE patient_assignments
    ADD COLUMN IF NOT EXISTS is_indefinite TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Se 1, atendimento por tempo indefinido';

SELECT 'Coluna is_indefinite adicionada!' AS resultado;
