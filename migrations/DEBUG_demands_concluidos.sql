-- DEBUG: Verificar dados de atendimentos concluídos
-- Execute este SQL para entender o que está no banco

-- 1. Verificar patient_assignments com status 'completed'
SELECT 
    pa.id AS assignment_id,
    pa.demand_id,
    pa.patient_id,
    pa.status AS pa_status,
    pa.completed_at,
    pa.created_at AS pa_created,
    p.full_name AS patient_name
FROM patient_assignments pa
LEFT JOIN patients p ON p.id = pa.patient_id
WHERE pa.status = 'completed'
ORDER BY pa.completed_at DESC
LIMIT 10;

-- 2. Verificar se essas demands existem e qual seu status
SELECT 
    d.id AS demand_id,
    d.title,
    d.status AS demand_status,
    d.created_at AS demand_created,
    d.updated_at AS demand_updated,
    pa.id AS assignment_id,
    pa.status AS assignment_status,
    pa.completed_at
FROM demands d
LEFT JOIN patient_assignments pa ON pa.demand_id = d.id
WHERE pa.status = 'completed'
ORDER BY pa.completed_at DESC
LIMIT 10;

-- 3. Verificar se demand_id está NULL em patient_assignments
SELECT 
    COUNT(*) AS total_completed,
    SUM(CASE WHEN demand_id IS NULL THEN 1 ELSE 0 END) AS sem_demand_id,
    SUM(CASE WHEN demand_id IS NOT NULL THEN 1 ELSE 0 END) AS com_demand_id
FROM patient_assignments
WHERE status = 'completed';

-- 4. Verificar todos os status possíveis em patient_assignments
SELECT 
    status,
    COUNT(*) AS quantidade
FROM patient_assignments
GROUP BY status
ORDER BY quantidade DESC;

-- 5. Verificar todos os status possíveis em demands
SELECT 
    status,
    COUNT(*) AS quantidade
FROM demands
GROUP BY status
ORDER BY quantidade DESC;
