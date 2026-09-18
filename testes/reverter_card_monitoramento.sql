-- =====================================================================
-- TESTE: colocar o atendimento #211 de volta no MONITORAMENTO
-- =====================================================================
-- Objetivo: testar as mensagens de FINALIZAÇÃO ao profissional
--   (Hospitalização / Término do período autorizado), que disparam quando
--   o atendimento é finalizado pela tela de Monitoramento.
--
-- O Monitoramento (monitoramento.php) lista atendimentos que atendem TODAS estas condições:
--   1) patient_assignments.status IN ('admitted','awaiting_documents','awaiting_financial_approval')
--   2) patient_assignments.admitted_at IS NOT NULL
--   3) EXISTE pelo menos uma sessão em billing_document_requirements (INNER JOIN)
--
-- Se você finalizou o #211 num teste, ele virou 'completed' e sumiu do Monitoramento.
-- Este script reverte para 'admitted' e garante que há sessões para ele aparecer.
--
-- Rode em AMBIENTE DE TESTE. Basta executar em ordem.
-- =====================================================================

SET @ASSIGNMENT_ID := 211;


-- ---------------------------------------------------------------------
-- 1) Reverter o atendimento para 'admitted' e limpar as marcas de finalização
-- ---------------------------------------------------------------------
UPDATE patient_assignments
SET status = 'admitted',
    admitted_at = COALESCE(admitted_at, NOW()),
    approved_at = COALESCE(approved_at, NOW()),
    ended_at = NULL,
    end_reason_id = NULL,
    end_notes = NULL,
    ended_by_user_id = NULL,
    completed_at = NULL
WHERE id = @ASSIGNMENT_ID;


-- ---------------------------------------------------------------------
-- 2) Garantir que existe ao menos uma sessão (senão o card NÃO aparece,
--    por causa do INNER JOIN com billing_document_requirements).
-- ---------------------------------------------------------------------
INSERT INTO billing_document_requirements (assignment_id, patient_id, professional_user_id, session_number, session_date, status)
SELECT pa.id, pa.patient_id, pa.professional_user_id, 1, CURDATE(), 'pending'
FROM patient_assignments pa
WHERE pa.id = @ASSIGNMENT_ID
  AND NOT EXISTS (
      SELECT 1 FROM billing_document_requirements bdr WHERE bdr.assignment_id = @ASSIGNMENT_ID
  );


-- ---------------------------------------------------------------------
-- 3) PRÉ-REQUISITOS para as mensagens de finalização funcionarem:
-- ---------------------------------------------------------------------
-- 3.1) Os motivos de encerramento precisam existir (criados pelo SQL de templates oficiais):
SELECT id, name, slug, is_active
FROM treatment_end_reasons
WHERE slug IN ('hospitalizacao', 'termino_periodo_autorizado');
--   Se não retornar as 2 linhas, rode antes:
--   migrations/2026-09-18_0001_official_professional_templates.sql

-- 3.2) Os eventos WhatsApp precisam estar ativos:
SELECT system_event, status, send_to_professional
FROM whatsapp_events
WHERE system_event IN ('attendance_hospitalization', 'attendance_authorized_period_ended');

-- 3.3) O profissional precisa ter telefone (para receber no WhatsApp):
SELECT u.id, u.name, u.phone
FROM patient_assignments pa
INNER JOIN users u ON u.id = pa.professional_user_id
WHERE pa.id = @ASSIGNMENT_ID;


-- ---------------------------------------------------------------------
-- 4) CONFERÊNCIA: o card deve aparecer nesta consulta (base da tela de Monitoramento).
--    Se aparecer, recarregue /monitoramento.php, abra o card e clique em
--    "Finalizar Atendimento" escolhendo o motivo (Hospitalização OU
--    Término do período autorizado) para disparar a mensagem ao profissional.
-- ---------------------------------------------------------------------
SELECT DISTINCT pa.id AS assignment_id, pa.status, p.full_name AS paciente, u.name AS profissional
FROM billing_document_requirements bdr
INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
INNER JOIN patients p ON p.id = bdr.patient_id
INNER JOIN users u ON u.id = bdr.professional_user_id
WHERE pa.status IN ('admitted', 'awaiting_documents', 'awaiting_financial_approval')
  AND pa.admitted_at IS NOT NULL
ORDER BY pa.id DESC;
