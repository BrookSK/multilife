-- ============================================================================
-- DIAGNÓSTICO: localizar o atendimento do Roberto / Lucas Campagna Teste 2
-- e ver por que ele não aparece na Pré-admissão.
--
-- A Pré-admissão só lista: status = 'confirmed' E approved_at IS NULL.
-- SEGURO: só consultas (SELECT). Não altera nada.
-- ============================================================================

-- 1) Atendimentos do paciente Roberto (qualquer status)
SELECT pa.id AS assignment_id,
       pa.status,
       pa.approved_at,
       pa.admitted_at,
       pa.professional_user_id,
       u.name AS profissional,
       u.phone AS profissional_whatsapp,
       pa.demand_id,
       d.status AS demanda_status,
       p.full_name AS paciente
FROM patient_assignments pa
INNER JOIN patients p ON p.id = pa.patient_id
LEFT JOIN users u ON u.id = pa.professional_user_id
LEFT JOIN demands d ON d.id = pa.demand_id
WHERE p.full_name LIKE '%Roberto Almeida Ferreira%'
ORDER BY pa.id DESC;

-- 2) Conferir se existe QUALQUER atendimento em condição de aparecer na pré-admissão
SELECT COUNT(*) AS total_na_pre_admissao
FROM patient_assignments
WHERE status = 'confirmed' AND approved_at IS NULL;
