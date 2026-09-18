-- =====================================================================
-- TESTE: reverter um atendimento para a PRÉ-ADMISSÃO
-- =====================================================================
-- Objetivo: fazer um card que já foi aprovado (status 'admitted') voltar
-- para a fila de pré-admissão (status 'confirmed', approved_at NULL), para
-- testar o fluxo real de aprovação (que gera o link público de documentos
-- e dispara as notificações ao profissional).
--
-- A tela /pre_admissao.php lista apenas atendimentos com:
--     patient_assignments.status = 'confirmed' AND approved_at IS NULL
--
-- COMO USAR:
--   1) Rode o PASSO 1 para localizar o atendimento de teste e anote o ID.
--   2) Ajuste o @ASSIGNMENT_ID no PASSO 2 e rode os UPDATEs/DELETE.
--   3) Recarregue /pre_admissao.php: o card deve reaparecer para aprovação.
--
-- OBS: rode em ambiente de teste. As operações são reversíveis apenas
--      re-aprovando o card pela tela.
-- =====================================================================


-- ---------------------------------------------------------------------
-- PASSO 1 — Localizar o atendimento de teste (ajuste o filtro pelo nome
-- do paciente/profissional que você usa como teste).
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
WHERE p.full_name LIKE '%Teste%'    -- ajuste conforme seu card de teste
   OR u.name LIKE '%Teste%'
ORDER BY pa.id DESC
LIMIT 20;


-- ---------------------------------------------------------------------
-- PASSO 2 — Reverter o atendimento escolhido para a pré-admissão.
-- Troque o valor de @ASSIGNMENT_ID pelo assignment_id do PASSO 1.
-- ---------------------------------------------------------------------
SET @ASSIGNMENT_ID := 0;   -- <<< COLOQUE AQUI O ID DO ATENDIMENTO

-- Descobrir a demanda vinculada (para reverter o status do card de captação).
SET @DEMAND_ID := (SELECT demand_id FROM patient_assignments WHERE id = @ASSIGNMENT_ID);

-- 2.1) Voltar o atendimento para 'confirmed' e limpar as marcas de aprovação.
UPDATE patient_assignments
SET status = 'confirmed',
    approved_at = NULL,
    admitted_at = NULL,
    approved_by_user_id = NULL
WHERE id = @ASSIGNMENT_ID;

-- 2.2) Voltar o card de captação (demands) para o estado anterior à admissão.
--      'autorizacao_aprovada' é o estado que precede a pré-admissão.
UPDATE demands
SET status = 'autorizacao_aprovada', updated_at = NOW()
WHERE id = @DEMAND_ID;

-- 2.3) Remover as pendências de documentos de faturamento geradas na aprovação
--      (opcional, mas deixa o teste limpo e evita sessões duplicadas ao reaprovar).
DELETE FROM billing_document_requirements
WHERE assignment_id = @ASSIGNMENT_ID;


-- ---------------------------------------------------------------------
-- PASSO 3 — (Opcional) Garantir que o link será gerável e enviado:
-- ---------------------------------------------------------------------
-- 3.1) O profissional precisa ter telefone e/ou e-mail para receber a notificação.
--      Confira no resultado do PASSO 1 (prof_phone / prof_email).
--
-- 3.2) A operadora do atendimento precisa ter documentos cadastrados
--      (com especialidade/tipo/extra) para aparecerem na página pública.
SELECT id, file_name, specialty, doc_type, is_extra, professional_type
FROM health_insurer_documents
WHERE health_insurer_id = (
    SELECT health_insurer_id FROM patient_assignments WHERE id = @ASSIGNMENT_ID
);
--
-- 3.3) A flag "Enviar link de acesso ao portal nas notificações" precisa estar ligada:
SELECT setting_value
FROM admin_settings
WHERE setting_key = 'feature.enviar_link_portal_notificacoes';
-- Se vier vazio/0, ligue em /admin_feature_flags.php (ou rode o UPDATE abaixo):
-- UPDATE admin_settings SET setting_value = '1'
-- WHERE setting_key = 'feature.enviar_link_portal_notificacoes';


-- ---------------------------------------------------------------------
-- CONFERÊNCIA — o card deve voltar a aparecer nesta consulta (a mesma
-- que a tela /pre_admissao.php usa):
-- ---------------------------------------------------------------------
SELECT pa.id, pa.status, pa.approved_at, p.full_name AS paciente, u.name AS profissional
FROM patient_assignments pa
INNER JOIN patients p ON p.id = pa.patient_id
LEFT JOIN users u ON u.id = pa.professional_user_id
WHERE pa.status = 'confirmed' AND pa.approved_at IS NULL
ORDER BY pa.id DESC;
