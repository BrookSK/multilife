-- ============================================
-- Campo consolidado de Histórico / Informações Clínicas (item 8)
-- Permite consolidar histórico médico e saúde num único campo,
-- reduzindo a quantidade de campos separados. Os campos antigos
-- permanecem para preservar dados existentes.
-- Data: 2026-08-14
-- ============================================

ALTER TABLE patients
    ADD COLUMN IF NOT EXISTS clinical_summary TEXT NULL
    COMMENT 'Histórico e informações clínicas consolidadas (item 8)';

SELECT 'Coluna clinical_summary adicionada!' AS resultado;
