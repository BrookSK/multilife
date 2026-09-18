-- =====================================================================
-- TESTE: colocar o atendimento #211 de volta na PRÉ-ADMISSÃO
-- =====================================================================
-- Atendimento de teste (dados informados):
--   assignment_id = 211  | demand_id = 6847
--   paciente      = Roberto Almeida Ferreira
--   profissional  = Lucas Campagna Teste 2 (user_id 257, tipo 'new')
--   telefone      = 17991429485 | email = lucas.campagna+teste@lrvweb.com.br
--   operadora     = Amil (health_insurer_id 21)
--   status atual  = admitted (por isso NÃO está na pré-admissão)
--
-- A tela /pre_admissao.php lista apenas: status='confirmed' AND approved_at IS NULL.
-- Este script reverte o #211 para 'confirmed' para você aprovar de novo e
-- testar o fluxo real (gera o link público de documentos + notificações).
--
-- Rode em AMBIENTE DE TESTE. Basta executar os blocos em ordem.
-- =====================================================================

SET @ASSIGNMENT_ID := 211;
SET @DEMAND_ID     := 6847;
SET @INSURER_ID    := 21;   -- Amil (operadora do atendimento)


-- ---------------------------------------------------------------------
-- 1) Reverter o atendimento para a pré-admissão
-- ---------------------------------------------------------------------
UPDATE patient_assignments
SET status = 'confirmed',
    approved_at = NULL,
    admitted_at = NULL,
    approved_by_user_id = NULL
WHERE id = @ASSIGNMENT_ID;

-- Card de captação volta ao estado que antecede a admissão
UPDATE demands
SET status = 'autorizacao_aprovada', updated_at = NOW()
WHERE id = @DEMAND_ID;

-- Remover as pendências de documentos de faturamento geradas na aprovação
-- (evita sessões duplicadas quando você reaprovar)
DELETE FROM billing_document_requirements
WHERE assignment_id = @ASSIGNMENT_ID;


-- ---------------------------------------------------------------------
-- 2) Ligar a flag que inclui o link nas notificações
-- ---------------------------------------------------------------------
INSERT INTO admin_settings (setting_key, setting_value)
VALUES ('feature.enviar_link_portal_notificacoes', '1')
ON DUPLICATE KEY UPDATE setting_value = '1';


-- ---------------------------------------------------------------------
-- 3) Garantir documentos na operadora Amil (senão a página abre vazia)
-- ---------------------------------------------------------------------
-- Veja se a Amil já tem documentos cadastrados:
SELECT id, file_name, specialty, doc_type, is_extra, professional_type
FROM health_insurer_documents
WHERE health_insurer_id = @INSURER_ID;
--
-- OPÇÃO A (recomendada): cadastrar os documentos na Amil pela tela
--   Configurações -> Operadoras -> editar "Amil" -> adicionar documentos
--   (com especialidade/tipo/extra). É o teste mais fiel.
--
-- OPÇÃO B (rápida): se você já cadastrou documentos em OUTRA operadora
--   (ex.: "ON Solutions Brasil") e quer reusá-los, copie-os para a Amil.
--   Descubra o id da operadora de origem:
--     SELECT id, name FROM health_insurers ORDER BY name;
--   Depois copie (troque @FROM_INSURER pelo id de origem):
-- SET @FROM_INSURER := 0;  -- id da operadora que JÁ tem os documentos de teste
-- INSERT INTO health_insurer_documents
--   (health_insurer_id, file_name, file_path, file_size, mime_type,
--    uploaded_by_user_id, professional_type, specialty, doc_type, is_extra)
-- SELECT @INSURER_ID, file_name, file_path, file_size, mime_type,
--        uploaded_by_user_id, professional_type, specialty, doc_type, is_extra
-- FROM health_insurer_documents
-- WHERE health_insurer_id = @FROM_INSURER;


-- ---------------------------------------------------------------------
-- 4) CONFERÊNCIA: o card deve aparecer aqui (mesma consulta da tela).
--    Se aparecer, recarregue /pre_admissao.php e aprove o atendimento.
-- ---------------------------------------------------------------------
SELECT pa.id, pa.status, pa.approved_at, p.full_name AS paciente, u.name AS profissional
FROM patient_assignments pa
INNER JOIN patients p ON p.id = pa.patient_id
LEFT JOIN users u ON u.id = pa.professional_user_id
WHERE pa.status = 'confirmed' AND pa.approved_at IS NULL
ORDER BY pa.id DESC;
