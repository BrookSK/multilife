-- DIAGNÓSTICO COMPLETO DO SISTEMA FINANCEIRO
-- Execute este SQL e envie os resultados

-- 1. DADOS DO ATENDIMENTO #2
SELECT 
    'ATENDIMENTO #2 - DADOS BRUTOS' as info,
    pa.id,
    pa.patient_id,
    pa.professional_user_id,
    pa.specialty,
    pa.status,
    pa.payment_value,
    pa.agreed_value,
    pa.authorized_value,
    p.full_name as patient_name,
    u.name as professional_name
FROM patient_assignments pa
LEFT JOIN patients p ON p.id = pa.patient_id
LEFT JOIN users u ON u.id = pa.professional_user_id
WHERE pa.id = 2;

-- 2. LANÇAMENTOS FINANCEIROS RELACIONADOS AO ATENDIMENTO #2
SELECT 
    'FINANCIAL_ENTRIES - ATENDIMENTO #2' as info,
    fe.id,
    fe.entry_type,
    fe.category,
    fe.amount,
    fe.description,
    fe.status,
    fe.assignment_id,
    fe.is_active
FROM financial_entries fe
WHERE fe.assignment_id = 2 OR fe.description LIKE '%#2%';

-- 3. TODOS OS LANÇAMENTOS FINANCEIROS ATIVOS
SELECT 
    'TODOS FINANCIAL_ENTRIES ATIVOS' as info,
    fe.id,
    fe.entry_type,
    fe.category,
    fe.amount,
    fe.description,
    fe.status,
    fe.is_active,
    fe.created_at
FROM financial_entries fe
WHERE fe.is_active = 1
ORDER BY fe.id DESC;

-- 4. CÁLCULO DE RECEITAS (como o dashboard faz)
SELECT 
    'RECEITAS - ATENDIMENTOS' as info,
    SUM(pa.agreed_value) as total_agreed_value,
    COUNT(*) as num_atendimentos
FROM patient_assignments pa
INNER JOIN patients p ON p.id = pa.patient_id
WHERE pa.status IN ('approved', 'completed', 'paid') 
  AND pa.agreed_value IS NOT NULL 
  AND pa.agreed_value > 0;

SELECT 
    'RECEITAS - LANÇAMENTOS MANUAIS' as info,
    SUM(fe.amount) as total_lancamentos,
    COUNT(*) as num_lancamentos
FROM financial_entries fe
WHERE fe.entry_type = 'income' 
  AND fe.status IN ('pending', 'paid')
  AND fe.is_active = 1;

-- 5. CÁLCULO DE DESPESAS (como o dashboard faz)
SELECT 
    'DESPESAS - ATENDIMENTOS' as info,
    SUM(pa.authorized_value) as total_authorized_value,
    COUNT(*) as num_atendimentos
FROM patient_assignments pa
INNER JOIN patients p ON p.id = pa.patient_id
WHERE pa.status IN ('approved', 'completed', 'paid') 
  AND pa.authorized_value IS NOT NULL 
  AND pa.authorized_value > 0;

SELECT 
    'DESPESAS - LANÇAMENTOS MANUAIS' as info,
    SUM(fe.amount) as total_lancamentos,
    COUNT(*) as num_lancamentos
FROM financial_entries fe
WHERE fe.entry_type = 'expense' 
  AND fe.status IN ('pending', 'paid')
  AND fe.is_active = 1;

-- 6. VERIFICAR SE HÁ LANÇAMENTOS DUPLICADOS
SELECT 
    'POSSÍVEIS DUPLICAÇÕES' as info,
    fe.description,
    fe.entry_type,
    fe.amount,
    COUNT(*) as quantidade
FROM financial_entries fe
WHERE fe.is_active = 1
GROUP BY fe.description, fe.entry_type, fe.amount
HAVING COUNT(*) > 1;
