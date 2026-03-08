-- Execute este SQL e me envie uma captura de tela dos resultados

-- Atendimento #2 - valores brutos
SELECT 
    pa.id,
    pa.agreed_value as 'RECEITA (cliente paga)',
    pa.authorized_value as 'DESPESA (profissional recebe)',
    (pa.agreed_value - pa.authorized_value) as 'LUCRO esperado',
    pa.status
FROM patient_assignments pa
WHERE pa.id = 2;

-- Lançamentos manuais relacionados
SELECT 
    fe.id,
    fe.entry_type as 'tipo',
    fe.amount as 'valor',
    fe.description,
    fe.is_active
FROM financial_entries fe
WHERE fe.description LIKE '%Lucas Narciso%'
ORDER BY fe.id DESC;
