-- ============================================================================
-- Prepara o atendimento #3308 (Roberto / Lucas Campagna Teste 2) para VOLTAR
-- à PRÉ-ADMISSÃO, permitindo testar o disparo do WhatsApp ao aprovar.
--
-- A tela de Pré-admissão lista atendimentos com: status = 'confirmed' E approved_at IS NULL.
-- Este script reverte o atendimento para esse estado.
--
-- SEGURO: age APENAS no atendimento de id = 3308 (e nas sessões dele).
--   NÃO altera login, senha, conexão, nem outros atendimentos.
--   Ajuste @aid abaixo se o ID for diferente.
-- ============================================================================

SET @aid := 3308;

-- (Opcional) Conferir o estado atual ANTES de mexer:
SELECT id, status, approved_at, admitted_at, professional_user_id, demand_id
FROM patient_assignments
WHERE id = @aid;

-- 1) Voltar o atendimento para 'confirmed' e limpar marcações de aprovação/admissão.
UPDATE patient_assignments
SET status = 'confirmed',
    approved_at = NULL,
    approved_by_user_id = NULL,
    admitted_at = NULL
WHERE id = @aid;

-- 2) Voltar o card (demanda) para o estágio anterior à admissão, para consistência visual.
UPDATE demands d
INNER JOIN patient_assignments pa ON pa.id = @aid
SET d.status = 'autorizacao_aprovada'
WHERE d.id = pa.demand_id;

-- 3) Remover as sessões/pendências criadas na aprovação anterior (evita duplicar ao reaprovar).
--    Só as sessões deste atendimento.
DELETE FROM billing_document_requirements
WHERE assignment_id = @aid;

-- 4) Limpar token de cadastro antigo do profissional (para gerar um novo ao reaprovar).
UPDATE users u
INNER JOIN patient_assignments pa ON pa.id = @aid
SET u.registration_token = NULL
WHERE u.id = pa.professional_user_id;

-- Conferência final: o atendimento deve aparecer como 'confirmed' e approved_at NULL.
SELECT id, status, approved_at, admitted_at, professional_user_id, demand_id
FROM patient_assignments
WHERE id = @aid;
