-- ============================================
-- Seed: Operadoras de Saúde padrão com dados básicos
-- ============================================
-- Limpar e reinserir todas as operadoras

DELETE FROM health_insurers;

INSERT INTO health_insurers (name, cnpj, contact_phone, contact_email, billing_email, email_domain, notes, is_active) VALUES
('Amil', '29.309.127/0001-79', '0800 021 1581', 'atendimento@amil.com.br', 'faturamento@amil.com.br', 'amil.com.br', 'Amil Assistência Médica Internacional - Grupo UnitedHealth', 1),
('Bradesco Saúde', '92.693.118/0001-60', '0800 727 9966', 'atendimento@bradescosaude.com.br', 'faturamento@bradescosaude.com.br', 'bradescosaude.com.br', 'Bradesco Saúde S.A. - Grupo Bradesco Seguros', 1),
('SulAmérica Saúde', '01.685.053/0001-56', '0800 970 0500', 'atendimento@sulamerica.com.br', 'faturamento@sulamerica.com.br', 'sulamerica.com.br', 'SulAmérica Companhia de Seguro Saúde', 1),
('Unimed', '04.866.519/0001-08', '0800 740 3001', 'atendimento@unimed.com.br', 'faturamento@unimed.com.br', 'unimed.com.br', 'Unimed - Cooperativa de Trabalho Médico (Central Nacional)', 1),
('NotreDame Intermédica', '44.649.812/0001-38', '0800 015 3855', 'atendimento@intermedica.com.br', 'faturamento@intermedica.com.br', 'intermedica.com.br', 'NotreDame Intermédica - Grupo GNDI (agora Hapvida NotreDame)', 1),
('Hapvida', '63.554.067/0001-98', '0800 280 9130', 'atendimento@hapvida.com.br', 'faturamento@hapvida.com.br', 'hapvida.com.br', 'Hapvida Assistência Médica - Grupo Hapvida NotreDame Intermédica', 1),
('Porto Seguro Saúde', '92.106.356/0001-18', '0800 727 8118', 'atendimento@portoseguro.com.br', 'faturamento@portoseguro.com.br', 'portoseguro.com.br', 'Porto Seguro - Seguro Saúde S.A.', 1),
('Golden Cross', '01.518.211/0001-83', '0800 728 2001', 'atendimento@goldencross.com.br', 'faturamento@goldencross.com.br', 'goldencross.com.br', 'Golden Cross Assistência Internacional de Saúde', 1),
('Prevent Senior', '01.644.457/0001-67', '0800 770 4004', 'atendimento@preventsenior.com.br', 'faturamento@preventsenior.com.br', 'preventsenior.com.br', 'Prevent Senior - Operadora focada em idosos', 1),
('Cassi', '33.719.485/0001-27', '0800 729 0022', 'atendimento@cassi.com.br', 'faturamento@cassi.com.br', 'cassi.com.br', 'Caixa de Assistência dos Funcionários do Banco do Brasil', 1),
('Particular', NULL, NULL, NULL, NULL, NULL, 'Atendimento particular (sem convênio)', 1);
