-- FIX: Corrigir entry_date das parcelas já criadas
-- Este SQL atualiza as parcelas existentes para terem entry_date igual ao due_date
-- Isso distribui as parcelas cronologicamente nos meses corretos

-- IMPORTANTE: Execute este SQL ANTES de fazer upload do arquivo corrigido
-- Isso vai corrigir as parcelas antigas que já estão no banco

-- Atualizar entry_date das parcelas para ser igual ao due_date
UPDATE financial_entries
SET entry_date = due_date
WHERE payment_type = 'installment' 
    AND installment_number IS NOT NULL
    AND parent_entry_id IS NOT NULL
    AND entry_date != due_date;

-- Verificar resultado
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

-- Contar parcelas por mês (após correção)
SELECT 
    DATE_FORMAT(entry_date, '%Y-%m') AS mes,
    COUNT(*) AS quantidade_parcelas,
    SUM(amount) AS total
FROM financial_entries
WHERE payment_type = 'installment' 
    AND installment_number IS NOT NULL
GROUP BY DATE_FORMAT(entry_date, '%Y-%m')
ORDER BY mes DESC;
