-- ============================================
-- MODO DE TESTE DA CAPTAÇÃO
-- - Marca profissionais como "de teste" (users.is_test_professional)
-- - Config feature.captacao_test_mode controla se a captação adiciona
--   apenas profissionais de teste (para validar o fluxo sem disparar de verdade).
-- Data: 2026-08-14
-- ============================================

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_test_professional TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Se 1, é profissional de teste (usado no modo de teste da captação)';

SELECT 'Coluna is_test_professional adicionada!' AS resultado;
