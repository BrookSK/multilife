-- ============================================================================
-- MIGRATION: Atualizar patient_assignments para novos campos financeiros
-- Data: 2026-03-07
-- Descrição: Adicionar agreed_value, authorized_value e specialty_service_id
--            Manter payment_value para compatibilidade temporária
-- ============================================================================

-- Adicionar novas colunas financeiras
ALTER TABLE patient_assignments
ADD COLUMN IF NOT EXISTS specialty_service_id INT NULL AFTER service_type,
ADD COLUMN IF NOT EXISTS agreed_value DECIMAL(10,2) NULL AFTER payment_value,
ADD COLUMN IF NOT EXISTS authorized_value DECIMAL(10,2) NULL AFTER agreed_value;

-- Criar índice para specialty_service_id
CREATE INDEX IF NOT EXISTS idx_patient_assignments_specialty_service ON patient_assignments(specialty_service_id);

-- Migrar dados existentes: copiar payment_value para agreed_value e authorized_value
UPDATE patient_assignments 
SET 
    agreed_value = payment_value,
    authorized_value = payment_value
WHERE agreed_value IS NULL OR authorized_value IS NULL;

-- Comentário: payment_value será mantido por enquanto para compatibilidade
-- Pode ser removido em migration futura após validação completa

-- ============================================================================
-- VERIFICAÇÃO
-- ============================================================================

-- Ver distribuição de valores
SELECT 
    COUNT(*) as total_assignments,
    COUNT(CASE WHEN agreed_value IS NOT NULL THEN 1 END) as with_agreed_value,
    COUNT(CASE WHEN authorized_value IS NOT NULL THEN 1 END) as with_authorized_value,
    COUNT(CASE WHEN specialty_service_id IS NOT NULL THEN 1 END) as with_service_id
FROM patient_assignments;

-- Ver exemplo de cálculo de lucro
SELECT 
    id,
    specialty,
    session_quantity,
    payment_value as old_value,
    agreed_value,
    authorized_value,
    (authorized_value - agreed_value) as lucro_por_sessao,
    (authorized_value - agreed_value) * session_quantity as lucro_total
FROM patient_assignments
WHERE agreed_value IS NOT NULL AND authorized_value IS NOT NULL
LIMIT 10;
