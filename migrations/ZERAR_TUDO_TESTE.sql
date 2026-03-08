-- ============================================
-- ZERAR TUDO PARA TESTE LIMPO
-- ============================================
-- Este SQL remove todos os dados financeiros para começar do zero

-- 1. BACKUP DOS DADOS ATUAIS (segurança)
CREATE TABLE IF NOT EXISTS patient_assignments_backup_antes_zerar AS 
SELECT * FROM patient_assignments;

CREATE TABLE IF NOT EXISTS financial_entries_backup_antes_zerar AS 
SELECT * FROM financial_entries;

CREATE TABLE IF NOT EXISTS billing_invoices_backup_antes_zerar AS 
SELECT * FROM billing_invoices;

-- 2. REMOVER TODOS OS LANÇAMENTOS FINANCEIROS
DELETE FROM financial_entries;

-- 3. REMOVER TODAS AS FATURAS
DELETE FROM billing_invoices;

-- 4. ZERAR VALORES FINANCEIROS DOS ATENDIMENTOS (manter os atendimentos, só zerar valores)
UPDATE patient_assignments
SET 
    agreed_value = 0,
    authorized_value = 0,
    payment_value = 0;

-- OU, se preferir REMOVER TODOS OS ATENDIMENTOS:
-- DELETE FROM patient_assignments;

-- 5. VERIFICAR QUE ESTÁ TUDO ZERADO
SELECT 'LANÇAMENTOS FINANCEIROS' as tabela, COUNT(*) as total FROM financial_entries
UNION ALL
SELECT 'FATURAS' as tabela, COUNT(*) as total FROM billing_invoices
UNION ALL
SELECT 'ATENDIMENTOS COM VALORES' as tabela, COUNT(*) as total 
FROM patient_assignments 
WHERE agreed_value > 0 OR authorized_value > 0;

-- 6. MOSTRAR ATENDIMENTOS (se manteve)
SELECT 
    'ATENDIMENTOS APÓS ZERAR' as info,
    id,
    patient_id,
    specialty,
    status,
    agreed_value,
    authorized_value,
    created_at
FROM patient_assignments
ORDER BY id DESC;

-- ============================================
-- AGORA VOCÊ PODE CRIAR UM NOVO ATENDIMENTO PARA TESTAR
-- ============================================
-- Exemplo de teste:
-- Valor Acordado: R$ 20,00 (DESPESA/custo)
-- Valor Autorizado: R$ 100,00 (RECEITA)
-- Lucro esperado: R$ 80,00 (100 - 20)
