-- Adicionar especialidades genéricas para todas as categorias
-- Valor mínimo: R$ 50,00 | Custo interno: R$ 25,00

INSERT INTO specialties (name, minimum_value, internal_cost, status) VALUES
-- Especialidades Médicas Gerais
('Medicina Geral', 50.00, 25.00, 'active'),
('Clínico Geral', 50.00, 25.00, 'active'),
('Cirurgia Geral', 50.00, 25.00, 'active'),

-- Especialidades de Enfermagem Gerais
('Enfermagem Geral', 50.00, 25.00, 'active'),
('Técnico de Enfermagem', 50.00, 25.00, 'active'),
('Auxiliar de Enfermagem', 50.00, 25.00, 'active'),

-- Especialidades de Fisioterapia Gerais
('Fisioterapia Geral', 50.00, 25.00, 'active'),
('Fisioterapeuta', 50.00, 25.00, 'active'),

-- Especialidades de Psicologia Gerais
('Psicologia Geral', 50.00, 25.00, 'active'),
('Psicólogo', 50.00, 25.00, 'active'),

-- Especialidades de Nutrição Gerais
('Nutrição Geral', 50.00, 25.00, 'active'),
('Nutricionista', 50.00, 25.00, 'active'),

-- Especialidades de Fonoaudiologia Gerais
('Fonoaudiologia Geral', 50.00, 25.00, 'active'),
('Fonoaudiólogo', 50.00, 25.00, 'active'),

-- Especialidades de Terapia Ocupacional Gerais
('Terapia Ocupacional Geral', 50.00, 25.00, 'active'),
('Terapeuta Ocupacional', 50.00, 25.00, 'active'),

-- Especialidades de Odontologia Gerais
('Odontologia Geral', 50.00, 25.00, 'active'),
('Dentista', 50.00, 25.00, 'active'),

-- Especialidades de Farmácia Gerais
('Farmácia Geral', 50.00, 25.00, 'active'),
('Farmacêutico', 50.00, 25.00, 'active'),

-- Outras Especialidades Gerais
('Biomédico', 50.00, 25.00, 'active'),
('Educador Físico', 50.00, 25.00, 'active'),
('Profissional de Educação Física', 50.00, 25.00, 'active'),
('Cuidador de Idosos', 50.00, 25.00, 'active'),
('Cuidador', 50.00, 25.00, 'active'),
('Acompanhante Terapêutico', 50.00, 25.00, 'active'),
('Massagista', 50.00, 25.00, 'active'),
('Massoterapeuta', 50.00, 25.00, 'active'),
('Personal Trainer', 50.00, 25.00, 'active'),
('Instrutor de Pilates', 50.00, 25.00, 'active'),
('Instrutor de Yoga', 50.00, 25.00, 'active'),
('Acupunturista', 50.00, 25.00, 'active'),
('Quiropraxista', 50.00, 25.00, 'active'),
('Osteopata', 50.00, 25.00, 'active'),
('Podólogo', 50.00, 25.00, 'active'),
('Musicoterapeuta', 50.00, 25.00, 'active'),
('Arteterapeuta', 50.00, 25.00, 'active')

ON DUPLICATE KEY UPDATE 
    minimum_value = VALUES(minimum_value),
    internal_cost = VALUES(internal_cost);
