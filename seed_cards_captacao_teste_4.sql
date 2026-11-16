-- ============================================================================
-- 4 cards de captação de TESTE (uma especialidade por card)
-- Use junto com o modo de teste da captação: só profissionais com
-- users.is_test_professional = 1 entram nos grupos.
--
-- Antes de rodar, confira especialidade/cidade dos profissionais de teste
-- e ajuste as variáveis abaixo para que o disparo encontre alguém:
--
--   SELECT u.id, u.name, u.phone, u.specialty, u.city, u.state
--   FROM users u
--   WHERE u.is_test_professional = 1 AND u.status = 'active';
-- ============================================================================

SET @cidade = 'São Paulo';
SET @uf     = 'SP';
SET @bairro = 'Centro';

INSERT INTO demands
    (title, patient_name, location_city, location_state, location_street, location_neighborhood, location_number,
     specialty, description, origin_email, status, procedure_value, ai_summary, urgency, frequency, has_multiple_requests, created_at)
VALUES
    ('[TESTE] Fisioterapia domiciliar - Paciente Teste 1', 'Paciente Teste 1',
     @cidade, @uf, 'Rua Teste', @bairro, '100',
     'Fisioterapia Domiciliar',
     'CARD DE TESTE. Reabilitação motora pós-cirurgia de quadril. 3 sessões/semana, ~1h por sessão.',
     'teste1@example.com', 'aguardando_captacao', 1200.00,
     'TESTE - Paciente 68 anos, pós-cirurgia de quadril, fisioterapia motora 3x/semana.',
     'normal', '3x_semana', 0, NOW()),

    ('[TESTE] Fonoaudiologia domiciliar - Paciente Teste 2', 'Paciente Teste 2',
     @cidade, @uf, 'Rua Teste', @bairro, '200',
     'Fonoaudiologia Domiciliar',
     'CARD DE TESTE. Reabilitação de deglutição e fala. 2 sessões/semana, ~45min por sessão.',
     'teste2@example.com', 'aguardando_captacao', 900.00,
     'TESTE - Paciente 75 anos com disfagia, fonoaudiologia 2x/semana.',
     'normal', '2x_semana', 0, NOW()),

    ('[TESTE] Enfermagem domiciliar - Paciente Teste 3', 'Paciente Teste 3',
     @cidade, @uf, 'Rua Teste', @bairro, '300',
     'Enfermagem Domiciliar',
     'CARD DE TESTE. Curativo diário e administração de medicação. Visitas diárias, ~1h por dia.',
     'teste3@example.com', 'aguardando_captacao', 3000.00,
     'TESTE - Paciente 80 anos, curativo de lesão por pressão e medicação, enfermagem diária.',
     'urgente', '7x_semana', 0, NOW()),

    ('[TESTE] Psicologia domiciliar - Paciente Teste 4', 'Paciente Teste 4',
     @cidade, @uf, 'Rua Teste', @bairro, '400',
     'Psicologia Domiciliar',
     'CARD DE TESTE. Acompanhamento psicológico. 1 sessão/semana, ~1h por sessão.',
     'teste4@example.com', 'aguardando_captacao', 600.00,
     'TESTE - Paciente 55 anos em recuperação de AVC, psicologia 1x/semana.',
     'normal', '1x_semana', 0, NOW());

-- Log de status inicial dos 4 cards
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
SELECT d.id, NULL, 'aguardando_captacao', NULL, 'Card de captação de teste criado via SQL.'
FROM demands d
WHERE d.title LIKE '[TESTE]%'
  AND NOT EXISTS (SELECT 1 FROM demand_status_logs l WHERE l.demand_id = d.id);

-- Conferência
-- SELECT id, title, specialty, location_city, status FROM demands WHERE title LIKE '[TESTE]%';

-- Limpeza (quando terminar os testes)
-- DELETE FROM demand_status_logs WHERE demand_id IN (SELECT id FROM (SELECT id FROM demands WHERE title LIKE '[TESTE]%') x);
-- DELETE FROM demands WHERE title LIKE '[TESTE]%';
