-- ============================================
-- LIMPAR DADOS DE TESTE DO SISTEMA
-- Remove profissionais, pacientes e monitoramentos de teste
-- ATENÇÃO: Execute com cuidado! Isso é irreversível.
-- Data: 2026-08-14
-- ============================================

-- ============================================
-- 1. REMOVER MONITORAMENTOS (patient_assignments) DE TESTE
-- Remove atribuições vinculadas a profissionais importados (@import) 
-- ou demands de importação
-- ============================================

-- Remover billing_document_requirements vinculados a assignments de teste
DELETE bdr FROM billing_document_requirements bdr
JOIN patient_assignments pa ON pa.id = bdr.assignment_id
WHERE pa.professional_remote_jid LIKE '%@import.local%';

-- Remover patient_assignments de importação/teste
DELETE FROM patient_assignments
WHERE professional_remote_jid LIKE '%@import.local%';

-- Remover assignments de demands de importação
DELETE pa FROM patient_assignments pa
JOIN demands d ON d.id = pa.demand_id
WHERE d.description = 'Importado via planilha';

-- ============================================
-- 2. REMOVER DEMANDS DE TESTE/IMPORTAÇÃO
-- ============================================
DELETE FROM demand_status_logs WHERE demand_id IN (
    SELECT id FROM demands WHERE description = 'Importado via planilha'
);

DELETE FROM demand_dispatch_logs WHERE demand_id IN (
    SELECT id FROM demands WHERE description = 'Importado via planilha'
);

DELETE FROM demands WHERE description = 'Importado via planilha';

-- ============================================
-- 3. REMOVER PROFISSIONAIS DE TESTE (importados)
-- São os que têm email @import.multilife.local ou @importacao.multilife.local
-- ============================================

-- Remover roles primeiro
DELETE ur FROM user_roles ur
JOIN users u ON u.id = ur.user_id
WHERE u.email LIKE '%@import%multilife%';

-- Remover os usuários de teste
DELETE FROM users WHERE email LIKE '%@import%multilife%';

-- ============================================
-- 4. REMOVER PACIENTES DE TESTE (importados)
-- Remove pacientes que foram criados na data da importação
-- e que não têm CPF (foram importados só com nome)
-- ============================================

-- Remover vínculos patient_professionals de pacientes de teste
DELETE pp FROM patient_professionals pp
JOIN patients p ON p.id = pp.patient_id
WHERE p.cpf IS NULL
  AND p.email IS NULL
  AND p.whatsapp IS NULL
  AND DATE(p.created_at) = '2026-08-14';

-- Soft-delete dos pacientes de teste (marca como excluído)
UPDATE patients
SET deleted_at = NOW()
WHERE cpf IS NULL
  AND email IS NULL
  AND whatsapp IS NULL
  AND DATE(created_at) = '2026-08-14'
  AND deleted_at IS NULL;

-- ============================================
-- 5. VERIFICAÇÃO
-- ============================================
SELECT 'Profissionais de teste restantes:' AS verificacao,
       (SELECT COUNT(*) FROM users WHERE email LIKE '%@import%multilife%') AS total;

SELECT 'Pacientes de teste (soft-deleted):' AS verificacao,
       (SELECT COUNT(*) FROM patients WHERE DATE(created_at) = '2026-08-14' AND deleted_at IS NOT NULL) AS total;

SELECT 'Assignments de teste restantes:' AS verificacao,
       (SELECT COUNT(*) FROM patient_assignments WHERE professional_remote_jid LIKE '%@import.local%') AS total;

SELECT 'Demands de importação restantes:' AS verificacao,
       (SELECT COUNT(*) FROM demands WHERE description = 'Importado via planilha') AS total;

SELECT 'Limpeza concluída!' AS resultado;
