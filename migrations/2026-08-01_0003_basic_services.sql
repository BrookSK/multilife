-- ============================================================
-- SERVIÇOS BÁSICOS (temporários - serão substituídos pela lista final)
-- Cria 1-2 serviços genéricos por especialidade
-- ============================================================

-- Limpar serviços existentes (se houver)
DELETE FROM specialty_services;

-- Inserir serviços básicos vinculados às especialidades ativas
INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Fisioterapia Motora' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Fisioterapia Respiratória' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Fisioterapia Motora/Respiratória' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Fisioterapia' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Nutrição' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Fonoaudiologia' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Terapia Ocupacional' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Psicologia' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Assistência Social' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Medico' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Enfermagem Supervisão' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Enfermagem procedimento' AND s.status = 'active';

INSERT INTO specialty_services (specialty_id, service_name, base_value, status, display_order)
SELECT s.id, 'Atendimento Domiciliar', 0.00, 'active', 1
FROM specialties s WHERE s.name = 'Enfermagem' AND s.status = 'active';

-- Verificação
SELECT s.name as especialidade, ss.service_name as servico, ss.base_value 
FROM specialty_services ss 
JOIN specialties s ON s.id = ss.specialty_id 
ORDER BY s.name;
