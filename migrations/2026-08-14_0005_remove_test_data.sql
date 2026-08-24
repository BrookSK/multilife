-- ============================================
-- LIMPAR DADOS DE TESTE DO SISTEMA
-- Remove profissionais, pacientes e monitoramentos de teste
-- ATENÇÃO: Execute com cuidado! Isso é irreversível.
-- Data: 2026-08-14
-- ============================================

-- ============================================
-- 1. IDENTIFICAR PACIENTES DE TESTE
-- Paciente com "teste" no nome ou IDs específicos de teste
-- ============================================

-- Remover billing_document_requirements de pacientes de teste
DELETE bdr FROM billing_document_requirements bdr
JOIN patient_assignments pa ON pa.id = bdr.assignment_id
JOIN patients p ON p.id = pa.patient_id
WHERE LOWER(p.full_name) LIKE '%teste%' OR LOWER(p.full_name) LIKE '%testo%';

-- Remover patient_assignments de pacientes de teste
DELETE pa FROM patient_assignments pa
JOIN patients p ON p.id = pa.patient_id
WHERE LOWER(p.full_name) LIKE '%teste%' OR LOWER(p.full_name) LIKE '%testo%';

-- ============================================
-- 2. IDENTIFICAR PROFISSIONAIS/USUÁRIOS DE TESTE
-- Usuários com "teste" no nome ou email de teste
-- ============================================

-- Remover billing_document_requirements de profissionais de teste
DELETE bdr FROM billing_document_requirements bdr
JOIN patient_assignments pa ON pa.id = bdr.assignment_id
JOIN users u ON u.id = pa.professional_user_id
WHERE LOWER(u.name) LIKE '%teste%' OR LOWER(u.name) LIKE '%testo%';

-- Remover patient_assignments de profissionais de teste
DELETE pa FROM patient_assignments pa
JOIN users u ON u.id = pa.professional_user_id
WHERE LOWER(u.name) LIKE '%teste%' OR LOWER(u.name) LIKE '%testo%';

-- ============================================
-- 3. REMOVER DEMANDS DE TESTE
-- Demands com "teste" no título ou criadas por usuários de teste
-- ============================================
DELETE FROM demand_status_logs WHERE demand_id IN (
    SELECT id FROM demands WHERE LOWER(title) LIKE '%teste%' OR LOWER(title) LIKE '%testo%'
);
DELETE FROM demand_dispatch_logs WHERE demand_id IN (
    SELECT id FROM demands WHERE LOWER(title) LIKE '%teste%' OR LOWER(title) LIKE '%testo%'
);
DELETE FROM demands WHERE LOWER(title) LIKE '%teste%' OR LOWER(title) LIKE '%testo%';

-- ============================================
-- 4. REMOVER PACIENTES DE TESTE
-- ============================================

-- Remover vínculos
DELETE pp FROM patient_professionals pp
JOIN patients p ON p.id = pp.patient_id
WHERE LOWER(p.full_name) LIKE '%teste%' OR LOWER(p.full_name) LIKE '%testo%';

-- Deletar pacientes de teste (hard delete)
DELETE FROM patients WHERE LOWER(full_name) LIKE '%teste%' OR LOWER(full_name) LIKE '%testo%';

-- ============================================
-- 5. REMOVER USUÁRIOS/PROFISSIONAIS DE TESTE
-- ============================================

-- Remover roles
DELETE ur FROM user_roles ur
JOIN users u ON u.id = ur.user_id
WHERE LOWER(u.name) LIKE '%teste%' OR LOWER(u.name) LIKE '%testo%'
   OR LOWER(u.email) LIKE '%teste%' OR LOWER(u.email) LIKE '%testo%';

-- Remover audit logs de usuários de teste
UPDATE audit_logs SET user_id = NULL WHERE user_id IN (
    SELECT id FROM users WHERE LOWER(name) LIKE '%teste%' OR LOWER(name) LIKE '%testo%'
       OR LOWER(email) LIKE '%teste%' OR LOWER(email) LIKE '%testo%'
);

-- Remover usuários de teste
DELETE FROM users WHERE LOWER(name) LIKE '%teste%' OR LOWER(name) LIKE '%testo%'
   OR LOWER(email) LIKE '%teste%' OR LOWER(email) LIKE '%testo%';

-- ============================================
-- 6. LIMPAR MONITORAMENTO ÓRFÃO
-- Assignments que ficaram sem paciente ou profissional válido
-- ============================================
DELETE FROM patient_assignments WHERE patient_id NOT IN (SELECT id FROM patients WHERE deleted_at IS NULL);

-- ============================================
-- 7. VERIFICAÇÃO
-- ============================================
SELECT 'Pacientes de teste restantes:' AS verificacao,
       (SELECT COUNT(*) FROM patients WHERE LOWER(full_name) LIKE '%teste%' OR LOWER(full_name) LIKE '%testo%') AS total;

SELECT 'Usuários de teste restantes:' AS verificacao,
       (SELECT COUNT(*) FROM users WHERE LOWER(name) LIKE '%teste%' OR LOWER(name) LIKE '%testo%') AS total;

SELECT 'Limpeza de dados de teste concluída!' AS resultado;
