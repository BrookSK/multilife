-- DEBUG: Verificar como as parcelas estão sendo criadas
-- Execute este SQL para entender o problema

-- 1. Ver todas as parcelas criadas recentemente
SELECT 
    id,
    entry_type,
    category,
    amount,
    description,
    entry_date,
    due_date,
    payment_type,
    installment_number,
    total_installments,
    parent_entry_id,
    created_at
FROM financial_entries
WHERE payment_type = 'installment' 
    AND installment_number IS NOT NULL
ORDER BY parent_entry_id DESC, installment_number ASC
LIMIT 20;

-- 2. Verificar se entry_date está diferente para cada parcela
SELECT 
    parent_entry_id,
    installment_number,
    DATE_FORMAT(entry_date, '%Y-%m') AS mes_entry,
    DATE_FORMAT(due_date, '%Y-%m') AS mes_due,
    amount,
    description
FROM financial_entries
WHERE payment_type = 'installment' 
    AND installment_number IS NOT NULL
    AND parent_entry_id IS NOT NULL
ORDER BY parent_entry_id DESC, installment_number ASC
LIMIT 20;

-- 3. Contar parcelas por mês
SELECT 
    DATE_FORMAT(entry_date, '%Y-%m') AS mes,
    COUNT(*) AS quantidade_parcelas,
    SUM(amount) AS total
FROM financial_entries
WHERE payment_type = 'installment' 
    AND installment_number IS NOT NULL
GROUP BY DATE_FORMAT(entry_date, '%Y-%m')
ORDER BY mes DESC;
