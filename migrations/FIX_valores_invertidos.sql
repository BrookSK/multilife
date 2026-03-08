-- ============================================
-- CORREÇÃO DE VALORES INVERTIDOS NO BANCO
-- ============================================
-- Este SQL corrige os atendimentos que foram salvos com agreed_value e authorized_value invertidos
-- EXECUTE ESTE SQL ANTES DE FAZER UPLOAD DOS ARQUIVOS PHP CORRIGIDOS

-- 1. BACKUP DOS DADOS ATUAIS (para segurança)
CREATE TABLE IF NOT EXISTS patient_assignments_backup_20260307 AS 
SELECT * FROM patient_assignments;

-- 2. INVERTER OS VALORES (agreed_value <-> authorized_value)
-- ATENÇÃO: Isso assume que TODOS os registros estão invertidos
-- Se houver registros corretos, NÃO execute este comando!

UPDATE patient_assignments
SET 
    agreed_value = authorized_value,
    authorized_value = agreed_value
WHERE agreed_value IS NOT NULL 
  AND authorized_value IS NOT NULL
  AND agreed_value > 0 
  AND authorized_value > 0;

-- 3. VERIFICAR RESULTADO
SELECT 
    id,
    patient_id,
    specialty,
    agreed_value as 'RECEITA (cliente paga)',
    authorized_value as 'DESPESA (profissional recebe)',
    (agreed_value - authorized_value) as 'LUCRO (deve ser positivo)',
    status
FROM patient_assignments
WHERE agreed_value IS NOT NULL AND authorized_value IS NOT NULL
ORDER BY id DESC;

-- 4. REMOVER LANÇAMENTOS MANUAIS ZERADOS E DUPLICADOS
-- Esses lançamentos foram criados incorretamente com valor zero
DELETE FROM financial_entries
WHERE amount = 0 
  AND is_active = 1
  AND (description LIKE '%Lucas Narciso%' OR description LIKE '%atendimento%');

-- 5. VERIFICAR LANÇAMENTOS RESTANTES
SELECT 
    id,
    entry_type,
    category,
    amount,
    description,
    status
FROM financial_entries
WHERE is_active = 1
ORDER BY id DESC;
