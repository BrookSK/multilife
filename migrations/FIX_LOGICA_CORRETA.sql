-- ============================================
-- CORREÇÃO COM LÓGICA CORRETA
-- ============================================
-- LÓGICA: Lucro = Autorizado - Acordado
-- authorized_value = RECEITA (valor que entra)
-- agreed_value = DESPESA (custo)

-- 1. VERIFICAR DADOS ATUAIS
SELECT 
    'DADOS ATUAIS' as info,
    id,
    specialty,
    agreed_value as 'DESPESA (custo)',
    authorized_value as 'RECEITA (valor autorizado)',
    (authorized_value - agreed_value) as 'LUCRO (autorizado - acordado)'
FROM patient_assignments
WHERE id IN (1, 2)
ORDER BY id;

-- 2. VERIFICAR SE OS DADOS ESTÃO CORRETOS
-- Atendimento #2 deveria ter:
-- agreed_value = 20 (DESPESA/custo)
-- authorized_value = 100 (RECEITA)
-- Lucro = 100 - 20 = 80

-- Se os valores estiverem invertidos (agreed_value=100, authorized_value=20), execute:
-- UPDATE patient_assignments
-- SET 
--     agreed_value = 20,
--     authorized_value = 100
-- WHERE id = 2;

-- 3. REMOVER LANÇAMENTOS ZERADOS
DELETE FROM financial_entries WHERE amount = 0.00;

-- 4. VERIFICAR RESULTADO FINAL
SELECT 
    'RESULTADO FINAL' as info,
    id,
    agreed_value as 'DESPESA',
    authorized_value as 'RECEITA',
    (authorized_value - agreed_value) as 'LUCRO',
    CASE 
        WHEN (authorized_value - agreed_value) > 0 THEN '✅ LUCRO'
        WHEN (authorized_value - agreed_value) < 0 THEN '❌ PREJUÍZO'
        ELSE '⚠️ ZERO'
    END as status_financeiro
FROM patient_assignments
WHERE id IN (1, 2)
ORDER BY id;
