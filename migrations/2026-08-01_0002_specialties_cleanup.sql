-- ============================================================
-- LIMPEZA DE ESPECIALIDADES
-- Manter APENAS as 13 especialidades aprovadas
-- Executar diretamente no banco de dados
-- ============================================================

-- PASSO 1: Desativar TODAS as especialidades
UPDATE specialties SET status = 'inactive';

-- PASSO 2: Inserir as que não existem ainda (INSERT IGNORE ignora duplicatas no campo name)
INSERT IGNORE INTO specialties (name, minimum_value, internal_cost, status) VALUES
('Fisioterapia Motora', 0.00, 0.00, 'active'),
('Fisioterapia Respiratória', 0.00, 0.00, 'active'),
('Fisioterapia Motora/Respiratória', 0.00, 0.00, 'active'),
('Fisioterapia', 0.00, 0.00, 'active'),
('Nutrição', 0.00, 0.00, 'active'),
('Fonoaudiologia', 0.00, 0.00, 'active'),
('Terapia Ocupacional', 0.00, 0.00, 'active'),
('Psicologia', 0.00, 0.00, 'active'),
('Assistência Social', 0.00, 0.00, 'active'),
('Medico', 0.00, 0.00, 'active'),
('Enfermagem Supervisão', 0.00, 0.00, 'active'),
('Enfermagem procedimento', 0.00, 0.00, 'active'),
('Enfermagem', 0.00, 0.00, 'active');

-- PASSO 3: Ativar APENAS as 13 da lista (garante que fiquem ativas mesmo que já existiam)
UPDATE specialties SET status = 'active' WHERE name IN (
    'Fisioterapia Motora',
    'Fisioterapia Respiratória',
    'Fisioterapia Motora/Respiratória',
    'Fisioterapia',
    'Nutrição',
    'Fonoaudiologia',
    'Terapia Ocupacional',
    'Psicologia',
    'Assistência Social',
    'Medico',
    'Enfermagem Supervisão',
    'Enfermagem procedimento',
    'Enfermagem'
);

-- VERIFICAÇÃO: Listar resultado final
SELECT id, name, status FROM specialties WHERE status = 'active' ORDER BY name;
