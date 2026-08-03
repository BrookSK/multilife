-- Limpeza de especialidades: manter apenas as listadas
-- Desativar todas as especialidades que NÃO estão na lista aprovada

UPDATE specialties SET status = 'inactive' WHERE name NOT IN (
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

-- Inserir as especialidades que ainda não existem
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

-- Garantir que as da lista estejam ativas (caso já existam mas estejam inativas)
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

-- Verificação final
SELECT id, name, status FROM specialties ORDER BY status DESC, name ASC;
