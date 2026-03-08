-- DEBUG: Verificar centros de custo e lançamentos
-- Execute este SQL para entender se há dados

-- 1. Ver todos os centros de custo cadastrados
SELECT 
    id,
    name,
    description,
    color,
    is_active,
    created_at
FROM cost_centers
ORDER BY name ASC;

-- 2. Ver lançamentos financeiros com centro de custo
SELECT 
    id,
    entry_type,
    category,
    amount,
    cost_center,
    entry_date,
    status,
    created_at
FROM financial_entries
WHERE is_active = 1
ORDER BY created_at DESC
LIMIT 20;

-- 3. Contar lançamentos por centro de custo
SELECT 
    COALESCE(cost_center, 'Não informado') as centro_custo,
    COUNT(*) as quantidade,
    SUM(CASE WHEN entry_type = 'income' THEN amount ELSE 0 END) as total_receitas,
    SUM(CASE WHEN entry_type = 'expense' THEN amount ELSE 0 END) as total_despesas,
    (SUM(CASE WHEN entry_type = 'income' THEN amount ELSE 0 END) + SUM(CASE WHEN entry_type = 'expense' THEN amount ELSE 0 END)) as total_geral
FROM financial_entries
WHERE is_active = 1
GROUP BY cost_center
ORDER BY total_geral DESC;

-- 4. Verificar se coluna color existe
SHOW COLUMNS FROM cost_centers LIKE 'color';
