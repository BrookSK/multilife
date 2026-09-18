-- ============================================================================
-- Prepara o atendimento id=211 (Roberto / Lucas Campagna Teste 2) para VOLTAR
-- à PRÉ-ADMISSÃO, permitindo testar o disparo do WhatsApp ao aprovar.
--
-- Descoberto no diagnóstico: o ID real do atendimento é 211 (status='admitted').
-- A Pré-admissão lista: status = 'confirmed' E approved_at IS NULL.
--
-- SEGURO: age APENAS no atendimento id=211 (e nas sessões dele).
--   NÃO altera login, senha, conexão nem outros atendimentos.
-- ============================================================================

SET @aid := 211;

-- 1) Voltar o atendimento para 'confirmed' e limpar marcações de aprovação/admissão.
UPDATE patient_assignments
SET status = 'confirmed',
    approved_at = NULL,
    approved_by_user_id = NULL,
    admitted_at = NULL
WHERE id = @aid;

-- 2) Voltar o card (demanda) para o estágio anterior à admissão.
UPDATE demands d
INNER JOIN patient_assignments pa ON pa.id = @aid
SET d.status = 'autorizacao_aprovada'
WHERE d.id = pa.demand_id;

-- 3) Remover as sessões criadas na aprovação anterior (evita duplicar ao reaprovar).
DELETE FROM billing_document_requirements
WHERE assignment_id = @aid;

-- 4) Limpar token de cadastro antigo do profissional (para gerar um novo ao reaprovar).
UPDATE users u
INNER JOIN patient_assignments pa ON pa.id = @aid
SET u.registration_token = NULL
WHERE u.id = pa.professional_user_id;

-- Conferência: deve mostrar status='confirmed' e approved_at NULL,
-- e total_na_pre_admissao >= 1.
SELECT id, status, approved_at, admitted_at, professional_user_id, demand_id
FROM patient_assignments WHERE id = @aid;

SELECT COUNT(*) AS total_na_pre_admissao
FROM patient_assignments WHERE status = 'confirmed' AND approved_at IS NULL;
