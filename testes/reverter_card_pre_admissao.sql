-- =====================================================================
-- TESTE: fazer um atendimento aparecer na PRÉ-ADMISSÃO
-- =====================================================================
-- A tela /pre_admissao.php lista apenas atendimentos com:
--     patient_assignments.status = 'confirmed' AND approved_at IS NULL
--
-- Como sua pré-admissão está VAZIA, não há nenhum atendimento em 'confirmed'.
-- Este script te ajuda a reverter um atendimento que JÁ FOI aprovado
-- (ex.: um 'admitted' que aparece no Monitoramento) de volta para
-- 'confirmed', para você testar o fluxo real de aprovação — que gera o
-- link público de documentos e dispara as notificações ao profissional.
--
-- Rode em AMBIENTE DE TESTE. Passos: 1) diagnosticar, 2) escolher o ID,
-- 3) reverter, 4) conferir.
-- =====================================================================


-- ---------------------------------------------------------------------
-- PASSO 1 — DIAGNÓSTICO: ver TODOS os atendimentos e seus status.
-- Escolha um candidato (de preferência status 'admitted', que é o que a
-- aprovação da pré-admissão produz). Anote o assignment_id.
-- ---------------------------------------------------------------------
SELECT
    pa.id            AS assignment_id,
    pa.demand_id,
    pa.status        AS assignment_status,
    pa.approved_at,
    pa.admitted_at,
    pa.health_insurer_id,
    hi.name          AS operadora,
    p.full_name      AS paciente,
    u.id             AS professional_user_id,
    u.name           AS profissional,
    u.professional_type,
    u.phone          AS prof_phone,
    u.email          AS prof_email,
    d.status         AS demand_status
FROM patient_assignments pa
INNER JOIN patients p ON p.id = pa.patient_id
LEFT JOIN users u ON u.id = pa.professional_user_id
LEFT JOIN demands d ON d.id = pa.demand_id
LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
ORDER BY pa.id DESC
LIMIT 50;


-- ---------------------------------------------------------------------
-- PASSO 2 — Escolha o atendimento e informe o ID abaixo.
-- ---------------------------------------------------------------------
SET @ASSIGNMENT_ID := 0;   -- <<< COLOQUE AQUI O assignment_id do PASSO 1
SET @DEMAND_ID := (SELECT demand_id FROM patient_assignments WHERE id = @ASSIGNMENT_ID);


-- ---------------------------------------------------------------------
-- PASSO 3 — Reverter para a pré-admissão.
-- ---------------------------------------------------------------------
-- 3.1) Atendimento volta para 'confirmed' e limpa marcas de aprovação.
UPDATE patient_assignments
SET status = 'confirmed',
    approved_at = NULL,
    admitted_at = NULL,
    approved_by_user_id = NULL
WHERE id = @ASSIGNMENT_ID;

-- 3.2) Card de captação (demands) volta ao estado que antecede a admissão.
UPDATE demands
SET status = 'autorizacao_aprovada', updated_at = NOW()
WHERE id = @DEMAND_ID;

-- 3.3) Remover as pendências de documentos de faturamento geradas na aprovação
--      (evita sessões duplicadas ao reaprovar; opcional).
DELETE FROM billing_document_requirements
WHERE assignment_id = @ASSIGNMENT_ID;


-- ---------------------------------------------------------------------
-- PASSO 4 — PRÉ-REQUISITOS para o teste do link funcionar de ponta a ponta.
-- ---------------------------------------------------------------------
-- 4.1) O profissional precisa ter telefone e/ou e-mail (veja no PASSO 1).
--      Se não tiver, defina um para receber a notificação (troque o ID e valores):
-- UPDATE users SET phone = '5511999999999', email = 'seu-teste@exemplo.com'
-- WHERE id = <professional_user_id>;

-- 4.2) O atendimento precisa ter uma OPERADORA vinculada, e a operadora
--      precisa ter documentos cadastrados. Confira os documentos:
SELECT id, file_name, specialty, doc_type, is_extra, professional_type
FROM health_insurer_documents
WHERE health_insurer_id = (
    SELECT health_insurer_id FROM patient_assignments WHERE id = @ASSIGNMENT_ID
);
--   Se o atendimento estiver SEM operadora (health_insurer_id NULL), vincule uma
--   que tenha documentos (troque o 1 pelo id da operadora desejada):
-- UPDATE patient_assignments SET health_insurer_id = 1 WHERE id = @ASSIGNMENT_ID;
--   (na tela de aprovação da pré-admissão você também seleciona a operadora)

-- 4.3) A flag "Enviar link de acesso ao portal nas notificações" precisa estar ligada:
SELECT setting_value FROM admin_settings
WHERE setting_key = 'feature.enviar_link_portal_notificacoes';
-- Se vier vazio/0, ligue em /admin_feature_flags.php OU rode:
-- INSERT INTO admin_settings (setting_key, setting_value)
-- VALUES ('feature.enviar_link_portal_notificacoes', '1')
-- ON DUPLICATE KEY UPDATE setting_value = '1';


-- ---------------------------------------------------------------------
-- PASSO 5 — CONFERÊNCIA: o card deve aparecer aqui (mesma consulta da
-- tela /pre_admissao.php). Se aparecer, recarregue a tela e aprove.
-- ---------------------------------------------------------------------
SELECT pa.id, pa.status, pa.approved_at, p.full_name AS paciente, u.name AS profissional
FROM patient_assignments pa
INNER JOIN patients p ON p.id = pa.patient_id
LEFT JOIN users u ON u.id = pa.professional_user_id
WHERE pa.status = 'confirmed' AND pa.approved_at IS NULL
ORDER BY pa.id DESC;
