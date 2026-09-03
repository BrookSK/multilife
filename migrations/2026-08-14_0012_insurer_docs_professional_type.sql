-- ============================================
-- Documentação de operadora/cliente separada por tipo de profissional (item 13)
-- professional_type: 'novo', 'antigo' ou 'ambos'
-- Data: 2026-08-14
-- ============================================

ALTER TABLE health_insurer_documents
    ADD COLUMN IF NOT EXISTS professional_type ENUM('novo','antigo','ambos') NOT NULL DEFAULT 'ambos'
    COMMENT 'Documentação exigida para profissional novo, antigo ou ambos';

SELECT 'Coluna professional_type adicionada em health_insurer_documents!' AS resultado;
