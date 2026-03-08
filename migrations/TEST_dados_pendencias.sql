-- ============================================
-- SQL PARA CRIAR DADOS DE TESTE - PAGINA DE PENDENCIAS
-- Execute este script para popular a pagina de pendencias com dados de exemplo
-- ============================================

-- ============================================
-- 1. CONTAS A PAGAR EM ATRASO
-- ============================================

-- Inserir lançamentos financeiros de despesas com vencimento passado
INSERT INTO financial_entries (
    entry_type, 
    category, 
    amount, 
    description, 
    entry_date, 
    due_date, 
    status, 
    created_by_user_id
) VALUES
('expense', 'Fornecedores', 2500.00, 'Fornecedor ABC - Material de escritorio', DATE_SUB(CURDATE(), INTERVAL 50 DAY), DATE_SUB(CURDATE(), INTERVAL 45 DAY), 'pending', 1),
('expense', 'Servicos', 1800.00, 'Manutencao de equipamentos', DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_SUB(CURDATE(), INTERVAL 22 DAY), 'pending', 1),
('expense', 'Aluguel', 3500.00, 'Aluguel do consultorio - Janeiro', DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'pending', 1),
('expense', 'Utilities', 450.00, 'Conta de energia eletrica', DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'pending', 1),
('expense', 'Fornecedores', 890.00, 'Material de limpeza', DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'pending', 1);

-- ============================================
-- 2. CONTAS A RECEBER EM ATRASO
-- ============================================

-- Inserir lançamentos financeiros de receitas com vencimento passado
INSERT INTO financial_entries (
    entry_type, 
    category, 
    amount, 
    description, 
    entry_date, 
    due_date, 
    status, 
    created_by_user_id
) VALUES
('income', 'Servicos', 4200.00, 'Atendimento Psicologia - Paciente Maria Silva', DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_SUB(CURDATE(), INTERVAL 38 DAY), 'pending', 1),
('income', 'Consultas', 1500.00, 'Consulta Fisioterapia - Paciente Joao Santos', DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'pending', 1),
('income', 'Servicos', 2800.00, 'Sessoes de Terapia - Paciente Ana Costa', DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'pending', 1),
('income', 'Consultas', 950.00, 'Atendimento Nutricional - Paciente Carlos Oliveira', DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'pending', 1);

-- ============================================
-- 3. ATENDIMENTOS PARADOS >24h
-- ============================================

-- Primeiro, vamos criar alguns pacientes de teste se nao existirem
INSERT IGNORE INTO patients (full_name, cpf, phone_primary, email, birth_date, created_at)
VALUES
('Maria Santos Silva', '111.222.333-44', '(11) 98765-4321', 'maria.santos@email.com', '1985-05-15', NOW()),
('Joao Pedro Oliveira', '222.333.444-55', '(11) 97654-3210', 'joao.oliveira@email.com', '1990-08-22', NOW()),
('Ana Carolina Costa', '333.444.555-66', '(11) 96543-2109', 'ana.costa@email.com', '1988-12-10', NOW()),
('Carlos Eduardo Lima', '444.555.666-77', '(11) 95432-1098', 'carlos.lima@email.com', '1992-03-18', NOW());

-- Buscar IDs dos pacientes criados
SET @patient1 = (SELECT id FROM patients WHERE cpf = '111.222.333-44' LIMIT 1);
SET @patient2 = (SELECT id FROM patients WHERE cpf = '222.333.444-55' LIMIT 1);
SET @patient3 = (SELECT id FROM patients WHERE cpf = '333.444.555-66' LIMIT 1);
SET @patient4 = (SELECT id FROM patients WHERE cpf = '444.555.666-77' LIMIT 1);

-- Buscar um profissional existente (assumindo que existe pelo menos um)
SET @professional = (SELECT id FROM users WHERE status = 'active' LIMIT 1);

-- Criar atendimentos parados há mais de 24h
INSERT INTO appointments (
    patient_id,
    professional_user_id,
    first_at,
    value_per_session,
    status,
    created_at,
    updated_at
) VALUES
-- Atendimento parado há 3 dias (72h) - URGENTE
(@patient1, @professional, DATE_ADD(NOW(), INTERVAL 7 DAY), 150.00, 'agendado', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),

-- Atendimento parado há 2 dias e 12 horas (60h)
(@patient2, @professional, DATE_ADD(NOW(), INTERVAL 10 DAY), 200.00, 'pendente_formulario', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 60 HOUR)),

-- Atendimento parado há 2 dias (48h)
(@patient3, @professional, DATE_ADD(NOW(), INTERVAL 5 DAY), 180.00, 'revisao_admin', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),

-- Atendimento parado há 1 dia e 12 horas (36h)
(@patient4, @professional, DATE_ADD(NOW(), INTERVAL 3 DAY), 120.00, 'agendado', DATE_SUB(NOW(), INTERVAL 36 HOUR), DATE_SUB(NOW(), INTERVAL 36 HOUR));

-- ============================================
-- 4. PRÉ-ADMISSÃO AGUARDANDO APROVAÇÃO
-- ============================================

-- Criar demandas de teste
INSERT INTO demands (
    title,
    description,
    location_city,
    location_state,
    origin_email,
    status,
    created_at
) VALUES
('Atendimento Psicologia - Sao Paulo', 'Paciente necessita acompanhamento psicologico semanal', 'Sao Paulo', 'SP', 'contato@clinica.com', 'em_captacao', NOW()),
('Fisioterapia Domiciliar - Rio de Janeiro', 'Sessoes de fisioterapia em domicilio 3x por semana', 'Rio de Janeiro', 'RJ', 'admin@saude.com', 'em_captacao', NOW()),
('Terapia Ocupacional - Belo Horizonte', 'Acompanhamento de terapia ocupacional infantil', 'Belo Horizonte', 'MG', 'secretaria@hospital.com', 'em_captacao', NOW());

-- Buscar IDs das demandas criadas
SET @demand1 = (SELECT id FROM demands WHERE title = 'Atendimento Psicologia - Sao Paulo' ORDER BY id DESC LIMIT 1);
SET @demand2 = (SELECT id FROM demands WHERE title = 'Fisioterapia Domiciliar - Rio de Janeiro' ORDER BY id DESC LIMIT 1);
SET @demand3 = (SELECT id FROM demands WHERE title = 'Terapia Ocupacional - Belo Horizonte' ORDER BY id DESC LIMIT 1);

-- Criar atribuições confirmadas aguardando aprovação
INSERT INTO patient_assignments (
    demand_id,
    patient_id,
    professional_user_id,
    specialty,
    service_type,
    session_quantity,
    session_frequency,
    payment_value,
    status,
    assigned_by_user_id,
    created_at,
    approved_at
) VALUES
-- Aguardando há 5 dias - URGENTE
(@demand1, @patient1, @professional, 'Psicologia', 'Terapia Individual', 12, 'Semanal', 180.00, 'confirmed', 1, DATE_SUB(NOW(), INTERVAL 5 DAY), NULL),

-- Aguardando há 3 dias
(@demand2, @patient2, @professional, 'Fisioterapia', 'Domiciliar', 24, '3x por semana', 150.00, 'confirmed', 1, DATE_SUB(NOW(), INTERVAL 3 DAY), NULL),

-- Aguardando há 1 dia
(@demand3, @patient3, @professional, 'Terapia Ocupacional', 'Infantil', 16, 'Semanal', 200.00, 'confirmed', 1, DATE_SUB(NOW(), INTERVAL 1 DAY), NULL);

-- ============================================
-- RESUMO DOS DADOS CRIADOS
-- ============================================

SELECT '✅ DADOS DE TESTE CRIADOS COM SUCESSO!' as status;

SELECT 
    'Pendências Financeiras' as categoria,
    COUNT(*) as total
FROM financial_entries 
WHERE status = 'pending' 
AND due_date < CURDATE()
UNION ALL
SELECT 
    'Atendimentos Parados >24h' as categoria,
    COUNT(*) as total
FROM appointments 
WHERE status IN ('agendado', 'pendente_formulario', 'revisao_admin')
AND TIMESTAMPDIFF(HOUR, updated_at, NOW()) > 24
UNION ALL
SELECT 
    'Pré-admissão Aguardando' as categoria,
    COUNT(*) as total
FROM patient_assignments 
WHERE status = 'confirmed' 
AND approved_at IS NULL;
