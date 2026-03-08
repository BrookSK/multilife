-- DIAGNÓSTICO: POR QUE OS VALORES ESTÃO INVERTIDOS?

-- 1. Ver TODOS os atendimentos com seus valores
SELECT 
    'TODOS OS ATENDIMENTOS' as tipo,
    pa.id,
    p.full_name as paciente,
    u.name as profissional,
    pa.specialty,
    pa.status,
    pa.agreed_value as 'Receita (Cliente paga)',
    pa.authorized_value as 'Despesa (Profissional recebe)',
    (pa.agreed_value - pa.authorized_value) as 'Lucro Real',
    pa.payment_value as 'payment_value (IGNORAR)'
FROM patient_assignments pa
LEFT JOIN patients p ON p.id = pa.patient_id
LEFT JOIN users u ON u.id = pa.professional_user_id
ORDER BY pa.id DESC;

-- 2. Ver TODOS os lançamentos manuais
SELECT 
    'LANÇAMENTOS MANUAIS' as tipo,
    fe.id,
    fe.entry_type as 'Tipo (income=RECEITA / expense=DESPESA)',
    fe.category,
    fe.amount as 'Valor',
    fe.description,
    fe.status,
    fe.is_active
FROM financial_entries fe
ORDER BY fe.id DESC;

-- 3. SOMA TOTAL - RECEITAS
SELECT 
    'TOTAL RECEITAS' as calculo,
    COALESCE(SUM(pa.agreed_value), 0) as 'Receitas de Atendimentos',
    (SELECT COALESCE(SUM(amount), 0) FROM financial_entries WHERE entry_type = 'income' AND is_active = 1) as 'Receitas Manuais',
    COALESCE(SUM(pa.agreed_value), 0) + (SELECT COALESCE(SUM(amount), 0) FROM financial_entries WHERE entry_type = 'income' AND is_active = 1) as 'TOTAL RECEITAS'
FROM patient_assignments pa
WHERE pa.agreed_value IS NOT NULL AND pa.agreed_value > 0;

-- 4. SOMA TOTAL - DESPESAS
SELECT 
    'TOTAL DESPESAS' as calculo,
    COALESCE(SUM(pa.authorized_value), 0) as 'Despesas de Atendimentos',
    (SELECT COALESCE(SUM(amount), 0) FROM financial_entries WHERE entry_type = 'expense' AND is_active = 1) as 'Despesas Manuais',
    COALESCE(SUM(pa.authorized_value), 0) + (SELECT COALESCE(SUM(amount), 0) FROM financial_entries WHERE entry_type = 'expense' AND is_active = 1) as 'TOTAL DESPESAS'
FROM patient_assignments pa
WHERE pa.authorized_value IS NOT NULL AND pa.authorized_value > 0;

-- 5. LUCRO CALCULADO
SELECT 
    'LUCRO FINAL' as calculo,
    (SELECT COALESCE(SUM(pa.agreed_value), 0) FROM patient_assignments pa WHERE pa.agreed_value IS NOT NULL AND pa.agreed_value > 0) as receitas,
    (SELECT COALESCE(SUM(pa.authorized_value), 0) FROM patient_assignments pa WHERE pa.authorized_value IS NOT NULL AND pa.authorized_value > 0) as despesas,
    (SELECT COALESCE(SUM(pa.agreed_value), 0) FROM patient_assignments pa WHERE pa.agreed_value IS NOT NULL AND pa.agreed_value > 0) - 
    (SELECT COALESCE(SUM(pa.authorized_value), 0) FROM patient_assignments pa WHERE pa.authorized_value IS NOT NULL AND pa.authorized_value > 0) as 'LUCRO (deve ser POSITIVO se receita > despesa)';
