-- =============================================================================
-- MAIS 40 CARDS DE TESTE: [TESTE 41] a [TESTE 80] (Recebimento de E-mail, Rio de Janeiro/RJ)
-- Gerado em 2026-11-22. Rodar de uma vez no phpMyAdmin (aba SQL) do banco certo.
--
-- - Só INSERE cards novos. Não apaga nem altera nada que já existe.
-- - 20 cards com uma especialidade ([TESTE 41] a [TESTE 60]) e 20 multidisciplinares
--   ([TESTE 61] a [TESTE 80], 2 a 4 especialidades cada), nas 4 zonas do Rio.
-- - Especialidades com profissional de teste no Rio: Fisioterapia, Fonoaudiologia,
--   Enfermagem, Técnico de Enfermagem, Nutrição e Psicologia.
-- - Nenhum card com Enfermagem / Técnico de Enfermagem na Zona Sul: lá existe o grupo
--   "Enfermagem - Rio de Janeiro/RJ - Zona Sul - 1" do WhatsApp da Grace, com todo mundo.
-- - TRAVA: se já existir algum card [TESTE 41] a [TESTE 80], PARA sem inserir nada.
-- - NÃO rode de novo o mover_cards_teste_para_rio.sql nem o reset_cards_e_seed_testes_captacao.sql.
-- =============================================================================

DROP PROCEDURE IF EXISTS zz_cards80_guard;
DELIMITER $$
CREATE PROCEDURE zz_cards80_guard()
BEGIN
  IF EXISTS (SELECT 1 FROM demands WHERE title LIKE '[TESTE __]%' AND CAST(SUBSTRING(title, 8, 2) AS UNSIGNED) BETWEEN 41 AND 80) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PARADO: ja existem cards [TESTE 41] a [TESTE 80]. Nada foi inserido.';
  END IF;
END$$
DELIMITER ;
CALL zz_cards80_guard();
DROP PROCEDURE IF EXISTS zz_cards80_guard;

-- [TESTE 41] Adalberto Correia Lins - Fisioterapia - Lapa (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 41] Fisioterapia - Adalberto Correia Lins',
  'Adalberto Correia Lins',
  'Fisioterapia',
  '3x_semana',
  'normal',
  1800.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua do Lavradio',
  '40',
  'Apto 302',
  'Lapa',
  '20230070',
  'Centro',
  'Rua do Lavradio, 40 - Apto 302 - Lapa - Rio de Janeiro/RJ - CEP 20230-070',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 74 anos, pós-AVC isquêmico há 30 dias, hemiparesia à esquerda, disfagia leve para líquidos, marcha com apoio de terceiros.

Serviços: Fisioterapia 3x por semana.

Dispositivos: nenhum. Convênio Unimed.',
  'Filha acompanha nos atendimentos. Apartamento com elevador.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 41]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 24/11/2026
HORA: 08h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Adalberto Correia Lins

DATA DE NASCIMENTO: 01/01/1952

IDADE: 74 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua do Lavradio, 40 - Apto 302 - Lapa - Rio de Janeiro/RJ - CEP 20230-070

TELEFONE: (21) 90000-2000 - Marcos (filha)

HD: AVC isquêmico com hemiparesia à esquerda.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Filha acompanha nos atendimentos. Apartamento com elevador.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação motora: marcha, equilíbrio e fortalecimento do hemicorpo esquerdo.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 42] Benedita Souza Ramos - Fonoaudiologia - Tijuca (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 42] Fonoaudiologia - Benedita Souza Ramos',
  'Benedita Souza Ramos',
  'Fonoaudiologia',
  '2x_semana',
  'normal',
  1120.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Barão de Mesquita',
  '77',
  'Casa 1',
  'Tijuca',
  '20540004',
  'Zona Norte',
  'Rua Barão de Mesquita, 77 - Casa 1 - Tijuca - Rio de Janeiro/RJ - CEP 20540-004',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 75 anos, pós-AVC isquêmico há 30 dias, hemiparesia à esquerda, disfagia leve para líquidos, marcha com apoio de terceiros.

Serviços: Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Bradesco Saúde.',
  'Filha acompanha nos atendimentos. Apartamento com elevador.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 42]

PAD: Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 25/11/2026
HORA: 09h30

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Benedita Souza Ramos

DATA DE NASCIMENTO: 08/06/1951

IDADE: 75 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Barão de Mesquita, 77 - Casa 1 - Tijuca - Rio de Janeiro/RJ - CEP 20540-004

TELEFONE: (21) 90000-2001 - Larissa (filho)

HD: AVC isquêmico com hemiparesia à esquerda.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Filha acompanha nos atendimentos. Apartamento com elevador.

SERVIÇOS SOLICITADOS:
- Fonoaudiologia (2x por semana): Reabilitação da deglutição e adaptação de consistências.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 43] Carlos Alberto Nunes - Enfermagem - Recreio dos Bandeirantes (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 43] Enfermagem - Carlos Alberto Nunes',
  'Carlos Alberto Nunes',
  'Enfermagem',
  '1x_semana',
  'urgente',
  360.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Avenida Lúcio Costa',
  '114',
  NULL,
  'Recreio dos Bandeirantes',
  '22795008',
  'Zona Oeste',
  'Avenida Lúcio Costa, 114 - Recreio dos Bandeirantes - Rio de Janeiro/RJ - CEP 22795-008',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 81 anos, pós-operatório de osteossíntese de fêmur há 20 dias, carga parcial com andador, dor controlada.

Serviços: Enfermagem 1x por semana.

Dispositivos: nenhum. Convênio SulAmérica Saúde.',
  'Mora com o marido idoso. Precisa de horário pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 43]

PAD: Enfermagem 1x por semana.

POSSÍVEL DATA: 26/11/2026
HORA: 10h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Carlos Alberto Nunes

DATA DE NASCIMENTO: 15/11/1945

IDADE: 81 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Avenida Lúcio Costa, 114 - Recreio dos Bandeirantes - Rio de Janeiro/RJ - CEP 22795-008

TELEFONE: (21) 90000-2002 - Adriana (esposa)

HD: Pós-operatório de fratura de fêmur (osteossíntese).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Mora com o marido idoso. Precisa de horário pela manhã.

SERVIÇOS SOLICITADOS:
- Enfermagem (1x por semana): Curativo da ferida operatória e retirada de pontos.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 44] Dalva Pereira Matos - Técnico de Enfermagem - Vila Isabel (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 44] Técnico de Enfermagem - Dalva Pereira Matos',
  'Dalva Pereira Matos',
  'Técnico de Enfermagem',
  '7x_semana',
  'normal',
  5040.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Teodoro da Silva',
  '151',
  'Apto 1104, Bloco 2',
  'Vila Isabel',
  '20560005',
  'Zona Norte',
  'Rua Teodoro da Silva, 151 - Apto 1104, Bloco 2 - Vila Isabel - Rio de Janeiro/RJ - CEP 20560-005',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 85 anos, Alzheimer moderado, desorientação, episódios de agitação ao entardecer, deambula com supervisão.

Serviços: Técnico de Enfermagem diariamente.

Dispositivos: nenhum. Convênio Amil.',
  'Família pede profissional com experiência em demência.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 44]

PAD: Técnico de Enfermagem diariamente.

POSSÍVEL DATA: 27/11/2026
HORA: 13h30

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Dalva Pereira Matos

DATA DE NASCIMENTO: 22/04/1941

IDADE: 85 anos

CONVÊNIO: Amil

ENDEREÇO: Rua Teodoro da Silva, 151 - Apto 1104, Bloco 2 - Vila Isabel - Rio de Janeiro/RJ - CEP 20560-005

TELEFONE: (21) 90000-2003 - Renata (marido)

HD: Doença de Alzheimer em fase moderada.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família pede profissional com experiência em demência.

SERVIÇOS SOLICITADOS:
- Técnico de Enfermagem (diariamente): Acompanhamento diurno 12h: higiene, alimentação e administração de medicação.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 45] Edson Luiz Farias - Nutrição - Estácio (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 45] Nutrição - Edson Luiz Farias',
  'Edson Luiz Farias',
  'Nutrição',
  'quinzenal',
  'baixa',
  200.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Haddock Lobo',
  '188',
  'Fundos',
  'Estácio',
  '20260131',
  'Centro',
  'Rua Haddock Lobo, 188 - Fundos - Estácio - Rio de Janeiro/RJ - CEP 20260-131',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 78 anos, pós-AVC isquêmico há 30 dias, hemiparesia à esquerda, disfagia leve para líquidos, marcha com apoio de terceiros.

Serviços: Nutrição quinzenal.

Dispositivos: nenhum. Convênio Porto Seguro Saúde.',
  'Filha acompanha nos atendimentos. Apartamento com elevador.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 45]

PAD: Nutrição quinzenal.

POSSÍVEL DATA: 28/11/2026
HORA: 14h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Edson Luiz Farias

DATA DE NASCIMENTO: 01/09/1948

IDADE: 78 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua Haddock Lobo, 188 - Fundos - Estácio - Rio de Janeiro/RJ - CEP 20260-131

TELEFONE: (21) 90000-2004 - Sílvia (neta)

HD: AVC isquêmico com hemiparesia à esquerda.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Filha acompanha nos atendimentos. Apartamento com elevador.

SERVIÇOS SOLICITADOS:
- Nutrição (quinzenal): Ajuste da dieta para disfagia e controle de peso.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 46] Fátima Regina Coelho - Psicologia - Méier (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 46] Psicologia - Fátima Regina Coelho',
  'Fátima Regina Coelho',
  'Psicologia',
  '1x_semana',
  'normal',
  520.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Silva Rabelo',
  '225',
  'Apto 21',
  'Méier',
  '20735080',
  'Zona Norte',
  'Rua Silva Rabelo, 225 - Apto 21 - Méier - Rio de Janeiro/RJ - CEP 20735-080',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 74 anos, pós-AVC isquêmico há 30 dias, hemiparesia à esquerda, disfagia leve para líquidos, marcha com apoio de terceiros.

Serviços: Psicologia 1x por semana.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Filha acompanha nos atendimentos. Apartamento com elevador.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 46]

PAD: Psicologia 1x por semana.

POSSÍVEL DATA: 29/11/2026
HORA: 15h30

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Fátima Regina Coelho

DATA DE NASCIMENTO: 08/02/1952

IDADE: 74 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Silva Rabelo, 225 - Apto 21 - Méier - Rio de Janeiro/RJ - CEP 20735-080

TELEFONE: (21) 90000-2005 - Cláudia (mãe)

HD: AVC isquêmico com hemiparesia à esquerda.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Filha acompanha nos atendimentos. Apartamento com elevador.

SERVIÇOS SOLICITADOS:
- Psicologia (1x por semana): Suporte emocional ao paciente e à filha cuidadora.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 47] Gilberto Mendes Rocha - Fisioterapia - Freguesia de Jacarepaguá (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 47] Fisioterapia - Gilberto Mendes Rocha',
  'Gilberto Mendes Rocha',
  'Fisioterapia',
  '5x_semana',
  'normal',
  3000.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Araguaia',
  '262',
  NULL,
  'Freguesia de Jacarepaguá',
  '22745270',
  'Zona Oeste',
  'Rua Araguaia, 262 - Freguesia de Jacarepaguá - Rio de Janeiro/RJ - CEP 22745-270',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 80 anos, pós-operatório de osteossíntese de fêmur há 20 dias, carga parcial com andador, dor controlada.

Serviços: Fisioterapia 5x por semana.

Dispositivos: nenhum. Convênio NotreDame Intermédica.',
  'Mora com o marido idoso. Precisa de horário pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 47]

PAD: Fisioterapia 5x por semana.

POSSÍVEL DATA: 30/11/2026
HORA: 16h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Gilberto Mendes Rocha

DATA DE NASCIMENTO: 15/07/1946

IDADE: 80 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Rua Araguaia, 262 - Freguesia de Jacarepaguá - Rio de Janeiro/RJ - CEP 22745-270

TELEFONE: (21) 90000-2006 - Roberto (sobrinha)

HD: Pós-operatório de fratura de fêmur (osteossíntese).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Mora com o marido idoso. Precisa de horário pela manhã.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia motora pós-operatória: ganho de amplitude, fortalecimento e treino de marcha.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Notre%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Notre%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 48] Helena Cristina Vaz - Fonoaudiologia - Botafogo (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 48] Fonoaudiologia - Helena Cristina Vaz',
  'Helena Cristina Vaz',
  'Fonoaudiologia',
  '1x_semana',
  'urgente',
  560.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua São Clemente',
  '299',
  'Casa 3',
  'Botafogo',
  '22260001',
  'Zona Sul',
  'Rua São Clemente, 299 - Casa 3 - Botafogo - Rio de Janeiro/RJ - CEP 22260-001',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 84 anos, Alzheimer moderado, desorientação, episódios de agitação ao entardecer, deambula com supervisão.

Serviços: Fonoaudiologia 1x por semana.

Dispositivos: nenhum. Convênio Hapvida.',
  'Família pede profissional com experiência em demência.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 48]

PAD: Fonoaudiologia 1x por semana.

POSSÍVEL DATA: 01/12/2026
HORA: 08h00

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Helena Cristina Vaz

DATA DE NASCIMENTO: 22/12/1942

IDADE: 84 anos

CONVÊNIO: Hapvida

ENDEREÇO: Rua São Clemente, 299 - Casa 3 - Botafogo - Rio de Janeiro/RJ - CEP 22260-001

TELEFONE: (21) 90000-2007 - Simone (irmã)

HD: Doença de Alzheimer em fase moderada.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família pede profissional com experiência em demência.

SERVIÇOS SOLICITADOS:
- Fonoaudiologia (1x por semana): Estimulação da linguagem e da memória.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 49] Ivone Barros Lima - Enfermagem - Centro (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 49] Enfermagem - Ivone Barros Lima',
  'Ivone Barros Lima',
  'Enfermagem',
  '1x_semana',
  'normal',
  360.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua General Caldwell',
  '336',
  'Apto 302',
  'Centro',
  '20211020',
  'Centro',
  'Rua General Caldwell, 336 - Apto 302 - Centro - Rio de Janeiro/RJ - CEP 20211-020',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 71 anos, DPOC grave, O2 domiciliar 2 L/min, dispneia aos pequenos esforços, internação recente por exacerbação.

Serviços: Enfermagem 1x por semana.

Dispositivos: Concentrador de O2. Convênio Unimed.',
  'Paciente ex-tabagista. Casa térrea.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 49]

PAD: Enfermagem 1x por semana.

POSSÍVEL DATA: 02/12/2026
HORA: 09h30

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Ivone Barros Lima

DATA DE NASCIMENTO: 01/05/1955

IDADE: 71 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua General Caldwell, 336 - Apto 302 - Centro - Rio de Janeiro/RJ - CEP 20211-020

TELEFONE: (21) 90000-2008 - Marcos (filha)

HD: DPOC com dependência de oxigênio domiciliar.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente ex-tabagista. Casa térrea.

SERVIÇOS SOLICITADOS:
- Enfermagem (1x por semana): Avaliação clínica, controle de saturação e orientação do uso do O2.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 50] Jorge Henrique Prado - Técnico de Enfermagem - Madureira (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 50] Técnico de Enfermagem - Jorge Henrique Prado',
  'Jorge Henrique Prado',
  'Técnico de Enfermagem',
  '7x_semana',
  'baixa',
  5040.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Carolina Machado',
  '373',
  'Casa 1',
  'Madureira',
  '21350005',
  'Zona Norte',
  'Rua Carolina Machado, 373 - Casa 1 - Madureira - Rio de Janeiro/RJ - CEP 21350-005',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 61 anos, ELA com 2 anos de evolução, fraqueza nos quatro membros, disfagia progressiva, usa BiPAP noturno.

Serviços: Técnico de Enfermagem diariamente.

Dispositivos: BiPAP noturno. Convênio Bradesco Saúde.',
  'Troca de prestador. Família muito presente.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 50]

PAD: Técnico de Enfermagem diariamente.

POSSÍVEL DATA: 03/12/2026
HORA: 10h00

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Jorge Henrique Prado

DATA DE NASCIMENTO: 08/10/1965

IDADE: 61 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Carolina Machado, 373 - Casa 1 - Madureira - Rio de Janeiro/RJ - CEP 21350-005

TELEFONE: (21) 90000-2009 - Larissa (filho)

HD: Esclerose lateral amiotrófica (ELA).

DISPOSITIVO: (X) SIM = QUAL? BiPAP noturno  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Troca de prestador. Família muito presente.

SERVIÇOS SOLICITADOS:
- Técnico de Enfermagem (diariamente): Plantão diurno 12h: cuidados de higiene, mudança de decúbito e alimentação.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 51] Lúcia Helena Brandão - Nutrição - Campo Grande (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 51] Nutrição - Lúcia Helena Brandão',
  'Lúcia Helena Brandão',
  'Nutrição',
  'quinzenal',
  'normal',
  200.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Viúva Dantas',
  '410',
  NULL,
  'Campo Grande',
  '23052090',
  'Zona Oeste',
  'Rua Viúva Dantas, 410 - Campo Grande - Rio de Janeiro/RJ - CEP 23052-090',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 79 anos, pós-operatório de osteossíntese de fêmur há 20 dias, carga parcial com andador, dor controlada.

Serviços: Nutrição quinzenal.

Dispositivos: nenhum. Convênio SulAmérica Saúde.',
  'Mora com o marido idoso. Precisa de horário pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 51]

PAD: Nutrição quinzenal.

POSSÍVEL DATA: 24/11/2026
HORA: 13h30

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Lúcia Helena Brandão

DATA DE NASCIMENTO: 15/03/1947

IDADE: 79 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Rua Viúva Dantas, 410 - Campo Grande - Rio de Janeiro/RJ - CEP 23052-090

TELEFONE: (21) 90000-2010 - Adriana (esposa)

HD: Pós-operatório de fratura de fêmur (osteossíntese).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Mora com o marido idoso. Precisa de horário pela manhã.

SERVIÇOS SOLICITADOS:
- Nutrição (quinzenal): Dieta para consolidação óssea e prevenção de sarcopenia.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 52] Manoel Antunes Reis - Psicologia - Copacabana (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 52] Psicologia - Manoel Antunes Reis',
  'Manoel Antunes Reis',
  'Psicologia',
  '1x_semana',
  'normal',
  520.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Barata Ribeiro',
  '447',
  'Apto 1104, Bloco 2',
  'Copacabana',
  '22040002',
  'Zona Sul',
  'Rua Barata Ribeiro, 447 - Apto 1104, Bloco 2 - Copacabana - Rio de Janeiro/RJ - CEP 22040-002',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 83 anos, Alzheimer moderado, desorientação, episódios de agitação ao entardecer, deambula com supervisão.

Serviços: Psicologia 1x por semana.

Dispositivos: nenhum. Convênio Amil.',
  'Família pede profissional com experiência em demência.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 52]

PAD: Psicologia 1x por semana.

POSSÍVEL DATA: 25/11/2026
HORA: 14h00

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Manoel Antunes Reis

DATA DE NASCIMENTO: 22/08/1943

IDADE: 83 anos

CONVÊNIO: Amil

ENDEREÇO: Rua Barata Ribeiro, 447 - Apto 1104, Bloco 2 - Copacabana - Rio de Janeiro/RJ - CEP 22040-002

TELEFONE: (21) 90000-2011 - Renata (marido)

HD: Doença de Alzheimer em fase moderada.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família pede profissional com experiência em demência.

SERVIÇOS SOLICITADOS:
- Psicologia (1x por semana): Orientação familiar e estimulação cognitiva.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 53] Nair Gonçalves Pinto - Fisioterapia - Lapa (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 53] Fisioterapia - Nair Gonçalves Pinto',
  'Nair Gonçalves Pinto',
  'Fisioterapia',
  '2x_semana',
  'urgente',
  1200.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Mem de Sá',
  '484',
  'Fundos',
  'Lapa',
  '20230152',
  'Centro',
  'Rua Mem de Sá, 484 - Fundos - Lapa - Rio de Janeiro/RJ - CEP 20230-152',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 84 anos, Alzheimer moderado, desorientação, episódios de agitação ao entardecer, deambula com supervisão.

Serviços: Fisioterapia 2x por semana.

Dispositivos: nenhum. Convênio Porto Seguro Saúde.',
  'Família pede profissional com experiência em demência.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 53]

PAD: Fisioterapia 2x por semana.

POSSÍVEL DATA: 26/11/2026
HORA: 15h30

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Nair Gonçalves Pinto

DATA DE NASCIMENTO: 01/01/1942

IDADE: 84 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua Mem de Sá, 484 - Fundos - Lapa - Rio de Janeiro/RJ - CEP 20230-152

TELEFONE: (21) 90000-2012 - Sílvia (neta)

HD: Doença de Alzheimer em fase moderada.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família pede profissional com experiência em demência.

SERVIÇOS SOLICITADOS:
- Fisioterapia (2x por semana): Prevenção de quedas e manutenção da mobilidade.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 54] Otávio Ribeiro Sá - Fonoaudiologia - Olaria (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 54] Fonoaudiologia - Otávio Ribeiro Sá',
  'Otávio Ribeiro Sá',
  'Fonoaudiologia',
  '2x_semana',
  'normal',
  1120.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Paranhos',
  '521',
  'Apto 21',
  'Olaria',
  '21021450',
  'Zona Norte',
  'Rua Paranhos, 521 - Apto 21 - Olaria - Rio de Janeiro/RJ - CEP 21021-450',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 7 anos, criança com paralisia cerebral, sem marcha independente, alimentação por via oral com dificuldade.

Serviços: Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Atendimentos à tarde (escola pela manhã). Mãe é a cuidadora.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 54]

PAD: Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 27/11/2026
HORA: 16h00

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Otávio Ribeiro Sá

DATA DE NASCIMENTO: 08/06/2019

IDADE: 7 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Paranhos, 521 - Apto 21 - Olaria - Rio de Janeiro/RJ - CEP 21021-450

TELEFONE: (21) 90000-2013 - Cláudia (mãe)

HD: Paralisia cerebral com atraso global do desenvolvimento.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Atendimentos à tarde (escola pela manhã). Mãe é a cuidadora.

SERVIÇOS SOLICITADOS:
- Fonoaudiologia (2x por semana): Terapia de alimentação e estimulação da fala.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 55] Paulo Roberto Salles - Enfermagem - Bangu (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 55] Enfermagem - Paulo Roberto Salles',
  'Paulo Roberto Salles',
  'Enfermagem',
  '3x_semana',
  'baixa',
  1080.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Silva Cardoso',
  '558',
  NULL,
  'Bangu',
  '21810031',
  'Zona Oeste',
  'Rua Silva Cardoso, 558 - Bangu - Rio de Janeiro/RJ - CEP 21810-031',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 69 anos, diabético insulinodependente, úlcera plantar em pé direito há 2 meses, glicemias descontroladas.

Serviços: Enfermagem 3x por semana.

Dispositivos: nenhum. Convênio NotreDame Intermédica.',
  'Paciente mora sozinho. Vizinha tem a chave.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 55]

PAD: Enfermagem 3x por semana.

POSSÍVEL DATA: 28/11/2026
HORA: 08h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Paulo Roberto Salles

DATA DE NASCIMENTO: 15/11/1957

IDADE: 69 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Rua Silva Cardoso, 558 - Bangu - Rio de Janeiro/RJ - CEP 21810-031

TELEFONE: (21) 90000-2014 - Roberto (sobrinha)

HD: Diabetes tipo 2 com úlcera em pé direito.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente mora sozinho. Vizinha tem a chave.

SERVIÇOS SOLICITADOS:
- Enfermagem (3x por semana): Curativo da úlcera e controle glicêmico.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Notre%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Notre%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 56] Quitéria Dias Moura - Técnico de Enfermagem - Ramos (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 56] Técnico de Enfermagem - Quitéria Dias Moura',
  'Quitéria Dias Moura',
  'Técnico de Enfermagem',
  '7x_semana',
  'normal',
  5040.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Avenida Teixeira de Castro',
  '595',
  'Casa 3',
  'Ramos',
  '21040112',
  'Zona Norte',
  'Avenida Teixeira de Castro, 595 - Casa 3 - Ramos - Rio de Janeiro/RJ - CEP 21040-112',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 62 anos, alta de UTI após 40 dias, traqueostomizado em ar ambiente, alimentação por gastrostomia, acamado.

Serviços: Técnico de Enfermagem diariamente.

Dispositivos: TQT + GTT. Convênio Hapvida.',
  'Cama hospitalar já instalada no quarto.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 56]

PAD: Técnico de Enfermagem diariamente.

POSSÍVEL DATA: 29/11/2026
HORA: 09h30

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Quitéria Dias Moura

DATA DE NASCIMENTO: 22/04/1964

IDADE: 62 anos

CONVÊNIO: Hapvida

ENDEREÇO: Avenida Teixeira de Castro, 595 - Casa 3 - Ramos - Rio de Janeiro/RJ - CEP 21040-112

TELEFONE: (21) 90000-2015 - Simone (irmã)

HD: Traqueostomia após internação prolongada em UTI.

DISPOSITIVO: (X) SIM = QUAL? TQT + GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Cama hospitalar já instalada no quarto.

SERVIÇOS SOLICITADOS:
- Técnico de Enfermagem (diariamente): Plantão 12h: aspiração, dieta por GTT e mudança de decúbito.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 57] Raimundo Nonato Silva - Nutrição - Cidade Nova (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 57] Nutrição - Raimundo Nonato Silva',
  'Raimundo Nonato Silva',
  'Nutrição',
  'quinzenal',
  'normal',
  200.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Pinto de Azevedo',
  '632',
  'Apto 302',
  'Cidade Nova',
  '20211070',
  'Centro',
  'Rua Pinto de Azevedo, 632 - Apto 302 - Cidade Nova - Rio de Janeiro/RJ - CEP 20211-070',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 69 anos, DPOC grave, O2 domiciliar 2 L/min, dispneia aos pequenos esforços, internação recente por exacerbação.

Serviços: Nutrição quinzenal.

Dispositivos: Concentrador de O2. Convênio Unimed.',
  'Paciente ex-tabagista. Casa térrea.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 57]

PAD: Nutrição quinzenal.

POSSÍVEL DATA: 30/11/2026
HORA: 10h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Raimundo Nonato Silva

DATA DE NASCIMENTO: 01/09/1957

IDADE: 69 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua Pinto de Azevedo, 632 - Apto 302 - Cidade Nova - Rio de Janeiro/RJ - CEP 20211-070

TELEFONE: (21) 90000-2016 - Marcos (filha)

HD: DPOC com dependência de oxigênio domiciliar.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente ex-tabagista. Casa térrea.

SERVIÇOS SOLICITADOS:
- Nutrição (quinzenal): Dieta hipercalórica para recuperação de peso.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 58] Sônia Maria Peixoto - Psicologia - Irajá (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 58] Psicologia - Sônia Maria Peixoto',
  'Sônia Maria Peixoto',
  'Psicologia',
  '1x_semana',
  'urgente',
  520.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Ministro Edgard Romero',
  '669',
  'Casa 1',
  'Irajá',
  '21360200',
  'Zona Norte',
  'Rua Ministro Edgard Romero, 669 - Casa 1 - Irajá - Rio de Janeiro/RJ - CEP 21360-200',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 6 anos, criança com paralisia cerebral, sem marcha independente, alimentação por via oral com dificuldade.

Serviços: Psicologia 1x por semana.

Dispositivos: nenhum. Convênio Bradesco Saúde.',
  'Atendimentos à tarde (escola pela manhã). Mãe é a cuidadora.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 58]

PAD: Psicologia 1x por semana.

POSSÍVEL DATA: 01/12/2026
HORA: 13h30

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Sônia Maria Peixoto

DATA DE NASCIMENTO: 08/02/2020

IDADE: 6 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Ministro Edgard Romero, 669 - Casa 1 - Irajá - Rio de Janeiro/RJ - CEP 21360-200

TELEFONE: (21) 90000-2017 - Larissa (filho)

HD: Paralisia cerebral com atraso global do desenvolvimento.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Atendimentos à tarde (escola pela manhã). Mãe é a cuidadora.

SERVIÇOS SOLICITADOS:
- Psicologia (1x por semana): Acompanhamento do desenvolvimento e orientação à família.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 59] Tereza Cristina Leite - Fisioterapia - Realengo (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 59] Fisioterapia - Tereza Cristina Leite',
  'Tereza Cristina Leite',
  'Fisioterapia',
  '3x_semana',
  'normal',
  1800.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Marechal Joaquim Inácio',
  '706',
  NULL,
  'Realengo',
  '21715090',
  'Zona Oeste',
  'Rua Marechal Joaquim Inácio, 706 - Realengo - Rio de Janeiro/RJ - CEP 21715-090',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 71 anos, DPOC grave, O2 domiciliar 2 L/min, dispneia aos pequenos esforços, internação recente por exacerbação.

Serviços: Fisioterapia 3x por semana.

Dispositivos: Concentrador de O2. Convênio SulAmérica Saúde.',
  'Paciente ex-tabagista. Casa térrea.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 59]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 02/12/2026
HORA: 14h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Tereza Cristina Leite

DATA DE NASCIMENTO: 15/07/1955

IDADE: 71 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Rua Marechal Joaquim Inácio, 706 - Realengo - Rio de Janeiro/RJ - CEP 21715-090

TELEFONE: (21) 90000-2018 - Adriana (esposa)

HD: DPOC com dependência de oxigênio domiciliar.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente ex-tabagista. Casa térrea.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia respiratória: higiene brônquica e reexpansão pulmonar.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 60] Ubiratan Souza Costa - Fonoaudiologia - Flamengo (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 60] Fonoaudiologia - Ubiratan Souza Costa',
  'Ubiratan Souza Costa',
  'Fonoaudiologia',
  '2x_semana',
  'baixa',
  1120.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Paissandu',
  '743',
  'Apto 1104, Bloco 2',
  'Flamengo',
  '22210085',
  'Zona Sul',
  'Rua Paissandu, 743 - Apto 1104, Bloco 2 - Flamengo - Rio de Janeiro/RJ - CEP 22210-085',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 61 anos, ELA com 2 anos de evolução, fraqueza nos quatro membros, disfagia progressiva, usa BiPAP noturno.

Serviços: Fonoaudiologia 2x por semana.

Dispositivos: BiPAP noturno. Convênio Amil.',
  'Troca de prestador. Família muito presente.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 60]

PAD: Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 03/12/2026
HORA: 15h30

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Ubiratan Souza Costa

DATA DE NASCIMENTO: 22/12/1965

IDADE: 61 anos

CONVÊNIO: Amil

ENDEREÇO: Rua Paissandu, 743 - Apto 1104, Bloco 2 - Flamengo - Rio de Janeiro/RJ - CEP 22210-085

TELEFONE: (21) 90000-2019 - Renata (marido)

HD: Esclerose lateral amiotrófica (ELA).

DISPOSITIVO: (X) SIM = QUAL? BiPAP noturno  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Troca de prestador. Família muito presente.

SERVIÇOS SOLICITADOS:
- Fonoaudiologia (2x por semana): Manejo da disfagia e comunicação alternativa.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 61] Vera Lúcia Andrade - 4 especialidades - Rio Comprido (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 61] Multidisciplinar - Vera Lúcia Andrade',
  'Vera Lúcia Andrade',
  'Técnico de Enfermagem',
  '7x_semana',
  'normal',
  9520.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Paulo Fernandes',
  '780',
  'Fundos',
  'Rio Comprido',
  '20250090',
  'Centro',
  'Rua Paulo Fernandes, 780 - Fundos - Rio Comprido - Rio de Janeiro/RJ - CEP 20250-090',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 62 anos, alta de UTI após 40 dias, traqueostomizado em ar ambiente, alimentação por gastrostomia, acamado.

Serviços: Técnico de Enfermagem diariamente, Enfermagem 1x por semana, Fisioterapia 5x por semana, Fonoaudiologia 2x por semana.

Dispositivos: TQT + GTT. Convênio Porto Seguro Saúde.',
  'Cama hospitalar já instalada no quarto.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 61]

PAD: Técnico de Enfermagem diariamente + Enfermagem 1x por semana + Fisioterapia 5x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 24/11/2026
HORA: 16h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Vera Lúcia Andrade

DATA DE NASCIMENTO: 01/05/1964

IDADE: 62 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua Paulo Fernandes, 780 - Fundos - Rio Comprido - Rio de Janeiro/RJ - CEP 20250-090

TELEFONE: (21) 90000-2020 - Sílvia (neta)

HD: Traqueostomia após internação prolongada em UTI.

DISPOSITIVO: (X) SIM = QUAL? TQT + GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Cama hospitalar já instalada no quarto.

SERVIÇOS SOLICITADOS:
- Técnico de Enfermagem (diariamente): Plantão 12h: aspiração, dieta por GTT e mudança de decúbito.
- Enfermagem (1x por semana): Supervisão, troca de cânula e avaliação de feridas.
- Fisioterapia (5x por semana): Fisioterapia respiratória e motora no leito.
- Fonoaudiologia (2x por semana): Avaliação para decanulação e retorno da via oral.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Técnico de Enfermagem', 'Plantão 12h: aspiração, dieta por GTT e mudança de decúbito.', 'Rio de Janeiro', 'RJ', 'Rua Paulo Fernandes', 'Rio Comprido', '780', 'Fundos', 5040.00, 'normal', '7x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Supervisão, troca de cânula e avaliação de feridas.', 'Rio de Janeiro', 'RJ', 'Rua Paulo Fernandes', 'Rio Comprido', '780', 'Fundos', 360.00, 'normal', '1x_semana', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória e motora no leito.', 'Rio de Janeiro', 'RJ', 'Rua Paulo Fernandes', 'Rio Comprido', '780', 'Fundos', 3000.00, 'normal', '5x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Avaliação para decanulação e retorno da via oral.', 'Rio de Janeiro', 'RJ', 'Rua Paulo Fernandes', 'Rio Comprido', '780', 'Fundos', 1120.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 62] Waldir Pereira Gomes - 2 especialidades - Engenho Novo (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 62] Multidisciplinar - Waldir Pereira Gomes',
  'Waldir Pereira Gomes',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2920.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Barão de Bom Retiro',
  '817',
  'Apto 21',
  'Engenho Novo',
  '20715005',
  'Zona Norte',
  'Rua Barão de Bom Retiro, 817 - Apto 21 - Engenho Novo - Rio de Janeiro/RJ - CEP 20715-005',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 72 anos, Parkinson há 10 anos, bradicinesia, congelamento da marcha, voz baixa e três quedas no último mês.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Usa bengala dentro de casa. Esposa acompanha.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 62]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 25/11/2026
HORA: 08h00

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Waldir Pereira Gomes

DATA DE NASCIMENTO: 08/10/1954

IDADE: 72 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Barão de Bom Retiro, 817 - Apto 21 - Engenho Novo - Rio de Janeiro/RJ - CEP 20715-005

TELEFONE: (21) 90000-2021 - Cláudia (mãe)

HD: Doença de Parkinson com quedas frequentes.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Usa bengala dentro de casa. Esposa acompanha.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia neurofuncional: prevenção de quedas e estratégias para congelamento.
- Fonoaudiologia (2x por semana): Terapia de voz e deglutição.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia neurofuncional: prevenção de quedas e estratégias para congelamento.', 'Rio de Janeiro', 'RJ', 'Rua Barão de Bom Retiro', 'Engenho Novo', '817', 'Apto 21', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Terapia de voz e deglutição.', 'Rio de Janeiro', 'RJ', 'Rua Barão de Bom Retiro', 'Engenho Novo', '817', 'Apto 21', 1120.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 63] Yara Fernandes Luz - 3 especialidades - Pechincha (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 63] Multidisciplinar - Yara Fernandes Luz',
  'Yara Fernandes Luz',
  'Enfermagem',
  '2x_semana',
  'urgente',
  2720.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Estrada do Tindiba',
  '854',
  NULL,
  'Pechincha',
  '22740360',
  'Zona Oeste',
  'Estrada do Tindiba, 854 - Pechincha - Rio de Janeiro/RJ - CEP 22740-360',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 80 anos, insuficiência cardíaca com alta hospitalar há 10 dias, edema de membros inferiores, cansaço aos pequenos esforços.

Serviços: Enfermagem 2x por semana, Fisioterapia 3x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio NotreDame Intermédica.',
  'Cardiologista pede controle de peso diário.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 63]

PAD: Enfermagem 2x por semana + Fisioterapia 3x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 26/11/2026
HORA: 09h30

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Yara Fernandes Luz

DATA DE NASCIMENTO: 15/03/1946

IDADE: 80 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Estrada do Tindiba, 854 - Pechincha - Rio de Janeiro/RJ - CEP 22740-360

TELEFONE: (21) 90000-2022 - Roberto (sobrinha)

HD: Insuficiência cardíaca descompensada recente.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cardiologista pede controle de peso diário.

SERVIÇOS SOLICITADOS:
- Enfermagem (2x por semana): Controle de sinais vitais, peso e adesão aos medicamentos.
- Fisioterapia (3x por semana): Reabilitação cardiovascular leve.
- Nutrição (quinzenal): Dieta hipossódica e controle hídrico.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Notre%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Notre%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Enfermagem', 'Controle de sinais vitais, peso e adesão aos medicamentos.', 'Rio de Janeiro', 'RJ', 'Estrada do Tindiba', 'Pechincha', '854', NULL, 720.00, 'urgente', '2x_semana', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Reabilitação cardiovascular leve.', 'Rio de Janeiro', 'RJ', 'Estrada do Tindiba', 'Pechincha', '854', NULL, 1800.00, 'urgente', '3x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Dieta hipossódica e controle hídrico.', 'Rio de Janeiro', 'RJ', 'Estrada do Tindiba', 'Pechincha', '854', NULL, 200.00, 'urgente', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 64] Zélia Martins Cruz - 3 especialidades - Ipanema (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 64] Multidisciplinar - Zélia Martins Cruz',
  'Zélia Martins Cruz',
  'Fonoaudiologia',
  '3x_semana',
  'normal',
  2920.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Prudente de Morais',
  '891',
  'Casa 3',
  'Ipanema',
  '22420041',
  'Zona Sul',
  'Rua Prudente de Morais, 891 - Casa 3 - Ipanema - Rio de Janeiro/RJ - CEP 22420-041',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 6 anos, criança com TEA nível 2, fala com poucas palavras, seletividade alimentar importante.

Serviços: Fonoaudiologia 3x por semana, Psicologia 2x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio Hapvida.',
  'Atendimentos de preferência no fim da tarde.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 64]

PAD: Fonoaudiologia 3x por semana + Psicologia 2x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 27/11/2026
HORA: 10h00

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Zélia Martins Cruz

DATA DE NASCIMENTO: 22/08/2020

IDADE: 6 anos

CONVÊNIO: Hapvida

ENDEREÇO: Rua Prudente de Morais, 891 - Casa 3 - Ipanema - Rio de Janeiro/RJ - CEP 22420-041

TELEFONE: (21) 90000-2023 - Simone (irmã)

HD: Autismo nível 2 com atraso de linguagem.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Atendimentos de preferência no fim da tarde.

SERVIÇOS SOLICITADOS:
- Fonoaudiologia (3x por semana): Estimulação da linguagem e comunicação.
- Psicologia (2x por semana): Intervenção comportamental e orientação parental.
- Nutrição (quinzenal): Manejo da seletividade alimentar.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fonoaudiologia', 'Estimulação da linguagem e comunicação.', 'Rio de Janeiro', 'RJ', 'Rua Prudente de Morais', 'Ipanema', '891', 'Casa 3', 1680.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Intervenção comportamental e orientação parental.', 'Rio de Janeiro', 'RJ', 'Rua Prudente de Morais', 'Ipanema', '891', 'Casa 3', 1040.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Manejo da seletividade alimentar.', 'Rio de Janeiro', 'RJ', 'Rua Prudente de Morais', 'Ipanema', '891', 'Casa 3', 200.00, 'normal', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 65] Arthur Gabriel Lopes - 2 especialidades - São Cristóvão (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 65] Multidisciplinar - Arthur Gabriel Lopes',
  'Arthur Gabriel Lopes',
  'Fisioterapia',
  '3x_semana',
  'baixa',
  2920.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Senador Alencar',
  '928',
  'Apto 302',
  'São Cristóvão',
  '20921430',
  'Centro',
  'Rua Senador Alencar, 928 - Apto 302 - São Cristóvão - Rio de Janeiro/RJ - CEP 20921-430',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 78 anos, pós-AVC isquêmico há 30 dias, hemiparesia à esquerda, disfagia leve para líquidos, marcha com apoio de terceiros.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Unimed.',
  'Filha acompanha nos atendimentos. Apartamento com elevador.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 65]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 28/11/2026
HORA: 13h30

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Arthur Gabriel Lopes

DATA DE NASCIMENTO: 01/01/1948

IDADE: 78 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua Senador Alencar, 928 - Apto 302 - São Cristóvão - Rio de Janeiro/RJ - CEP 20921-430

TELEFONE: (21) 90000-2024 - Marcos (filha)

HD: AVC isquêmico com hemiparesia à esquerda.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Filha acompanha nos atendimentos. Apartamento com elevador.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação motora: marcha, equilíbrio e fortalecimento do hemicorpo esquerdo.
- Fonoaudiologia (2x por semana): Reabilitação da deglutição e adaptação de consistências.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Reabilitação motora: marcha, equilíbrio e fortalecimento do hemicorpo esquerdo.', 'Rio de Janeiro', 'RJ', 'Rua Senador Alencar', 'São Cristóvão', '928', 'Apto 302', 1800.00, 'baixa', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Reabilitação da deglutição e adaptação de consistências.', 'Rio de Janeiro', 'RJ', 'Rua Senador Alencar', 'São Cristóvão', '928', 'Apto 302', 1120.00, 'baixa', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 66] Beatriz Helena Torres - 3 especialidades - Vila Isabel (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 66] Multidisciplinar - Beatriz Helena Torres',
  'Beatriz Helena Torres',
  'Fisioterapia',
  '5x_semana',
  'normal',
  3560.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Pereira Nunes',
  '965',
  'Casa 1',
  'Vila Isabel',
  '20541024',
  'Zona Norte',
  'Rua Pereira Nunes, 965 - Casa 1 - Vila Isabel - Rio de Janeiro/RJ - CEP 20541-024',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 79 anos, pós-operatório de osteossíntese de fêmur há 20 dias, carga parcial com andador, dor controlada.

Serviços: Fisioterapia 5x por semana, Enfermagem 1x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio Bradesco Saúde.',
  'Mora com o marido idoso. Precisa de horário pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 66]

PAD: Fisioterapia 5x por semana + Enfermagem 1x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 29/11/2026
HORA: 14h00

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Beatriz Helena Torres

DATA DE NASCIMENTO: 08/06/1947

IDADE: 79 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Pereira Nunes, 965 - Casa 1 - Vila Isabel - Rio de Janeiro/RJ - CEP 20541-024

TELEFONE: (21) 90000-2025 - Larissa (filho)

HD: Pós-operatório de fratura de fêmur (osteossíntese).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Mora com o marido idoso. Precisa de horário pela manhã.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia motora pós-operatória: ganho de amplitude, fortalecimento e treino de marcha.
- Enfermagem (1x por semana): Curativo da ferida operatória e retirada de pontos.
- Nutrição (quinzenal): Dieta para consolidação óssea e prevenção de sarcopenia.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia motora pós-operatória: ganho de amplitude, fortalecimento e treino de marcha.', 'Rio de Janeiro', 'RJ', 'Rua Pereira Nunes', 'Vila Isabel', '965', 'Casa 1', 3000.00, 'normal', '5x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Curativo da ferida operatória e retirada de pontos.', 'Rio de Janeiro', 'RJ', 'Rua Pereira Nunes', 'Vila Isabel', '965', 'Casa 1', 360.00, 'normal', '1x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Dieta para consolidação óssea e prevenção de sarcopenia.', 'Rio de Janeiro', 'RJ', 'Rua Pereira Nunes', 'Vila Isabel', '965', 'Casa 1', 200.00, 'normal', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 67] Celso Augusto Pires - 4 especialidades - Barra da Tijuca (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 67] Multidisciplinar - Celso Augusto Pires',
  'Celso Augusto Pires',
  'Técnico de Enfermagem',
  '7x_semana',
  'normal',
  7320.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Avenida Olegário Maciel',
  '1002',
  NULL,
  'Barra da Tijuca',
  '22621200',
  'Zona Oeste',
  'Avenida Olegário Maciel, 1002 - Barra da Tijuca - Rio de Janeiro/RJ - CEP 22621-200',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 83 anos, Alzheimer moderado, desorientação, episódios de agitação ao entardecer, deambula com supervisão.

Serviços: Técnico de Enfermagem diariamente, Psicologia 1x por semana, Fisioterapia 2x por semana, Fonoaudiologia 1x por semana.

Dispositivos: nenhum. Convênio SulAmérica Saúde.',
  'Família pede profissional com experiência em demência.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 67]

PAD: Técnico de Enfermagem diariamente + Psicologia 1x por semana + Fisioterapia 2x por semana + Fonoaudiologia 1x por semana.

POSSÍVEL DATA: 30/11/2026
HORA: 15h30

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Celso Augusto Pires

DATA DE NASCIMENTO: 15/11/1943

IDADE: 83 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Avenida Olegário Maciel, 1002 - Barra da Tijuca - Rio de Janeiro/RJ - CEP 22621-200

TELEFONE: (21) 90000-2026 - Adriana (esposa)

HD: Doença de Alzheimer em fase moderada.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família pede profissional com experiência em demência.

SERVIÇOS SOLICITADOS:
- Técnico de Enfermagem (diariamente): Acompanhamento diurno 12h: higiene, alimentação e administração de medicação.
- Psicologia (1x por semana): Orientação familiar e estimulação cognitiva.
- Fisioterapia (2x por semana): Prevenção de quedas e manutenção da mobilidade.
- Fonoaudiologia (1x por semana): Estimulação da linguagem e da memória.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Técnico de Enfermagem', 'Acompanhamento diurno 12h: higiene, alimentação e administração de medicação.', 'Rio de Janeiro', 'RJ', 'Avenida Olegário Maciel', 'Barra da Tijuca', '1002', NULL, 5040.00, 'normal', '7x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Orientação familiar e estimulação cognitiva.', 'Rio de Janeiro', 'RJ', 'Avenida Olegário Maciel', 'Barra da Tijuca', '1002', NULL, 520.00, 'normal', '1x_semana', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Prevenção de quedas e manutenção da mobilidade.', 'Rio de Janeiro', 'RJ', 'Avenida Olegário Maciel', 'Barra da Tijuca', '1002', NULL, 1200.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Estimulação da linguagem e da memória.', 'Rio de Janeiro', 'RJ', 'Avenida Olegário Maciel', 'Barra da Tijuca', '1002', NULL, 560.00, 'normal', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 68] Dirceu Almeida Neto - 2 especialidades - Cachambi (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 68] Multidisciplinar - Dirceu Almeida Neto',
  'Dirceu Almeida Neto',
  'Fisioterapia',
  '3x_semana',
  'urgente',
  2160.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Cachambi',
  '1039',
  'Apto 1104, Bloco 2',
  'Cachambi',
  '20771200',
  'Zona Norte',
  'Rua Cachambi, 1039 - Apto 1104, Bloco 2 - Cachambi - Rio de Janeiro/RJ - CEP 20771-200',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 70 anos, DPOC grave, O2 domiciliar 2 L/min, dispneia aos pequenos esforços, internação recente por exacerbação.

Serviços: Fisioterapia 3x por semana, Enfermagem 1x por semana.

Dispositivos: Concentrador de O2. Convênio Amil.',
  'Paciente ex-tabagista. Casa térrea.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 68]

PAD: Fisioterapia 3x por semana + Enfermagem 1x por semana.

POSSÍVEL DATA: 01/12/2026
HORA: 16h00

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Dirceu Almeida Neto

DATA DE NASCIMENTO: 22/04/1956

IDADE: 70 anos

CONVÊNIO: Amil

ENDEREÇO: Rua Cachambi, 1039 - Apto 1104, Bloco 2 - Cachambi - Rio de Janeiro/RJ - CEP 20771-200

TELEFONE: (21) 90000-2027 - Renata (marido)

HD: DPOC com dependência de oxigênio domiciliar.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente ex-tabagista. Casa térrea.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia respiratória: higiene brônquica e reexpansão pulmonar.
- Enfermagem (1x por semana): Avaliação clínica, controle de saturação e orientação do uso do O2.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória: higiene brônquica e reexpansão pulmonar.', 'Rio de Janeiro', 'RJ', 'Rua Cachambi', 'Cachambi', '1039', 'Apto 1104, Bloco 2', 1800.00, 'urgente', '3x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Avaliação clínica, controle de saturação e orientação do uso do O2.', 'Rio de Janeiro', 'RJ', 'Rua Cachambi', 'Cachambi', '1039', 'Apto 1104, Bloco 2', 360.00, 'urgente', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 69] Elisa Moreira Campos - 3 especialidades - Santa Teresa (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 69] Multidisciplinar - Elisa Moreira Campos',
  'Elisa Moreira Campos',
  'Fisioterapia',
  '3x_semana',
  'normal',
  3440.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Leopoldo Fróes',
  '1076',
  'Fundos',
  'Santa Teresa',
  '20241330',
  'Centro',
  'Rua Leopoldo Fróes, 1076 - Fundos - Santa Teresa - Rio de Janeiro/RJ - CEP 20241-330',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 7 anos, criança com paralisia cerebral, sem marcha independente, alimentação por via oral com dificuldade.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana, Psicologia 1x por semana.

Dispositivos: nenhum. Convênio Porto Seguro Saúde.',
  'Atendimentos à tarde (escola pela manhã). Mãe é a cuidadora.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 69]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana + Psicologia 1x por semana.

POSSÍVEL DATA: 02/12/2026
HORA: 08h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Elisa Moreira Campos

DATA DE NASCIMENTO: 01/09/2019

IDADE: 7 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua Leopoldo Fróes, 1076 - Fundos - Santa Teresa - Rio de Janeiro/RJ - CEP 20241-330

TELEFONE: (21) 90000-2028 - Sílvia (neta)

HD: Paralisia cerebral com atraso global do desenvolvimento.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Atendimentos à tarde (escola pela manhã). Mãe é a cuidadora.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia neuropediátrica: controle de tronco e estímulo à marcha.
- Fonoaudiologia (2x por semana): Terapia de alimentação e estimulação da fala.
- Psicologia (1x por semana): Acompanhamento do desenvolvimento e orientação à família.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia neuropediátrica: controle de tronco e estímulo à marcha.', 'Rio de Janeiro', 'RJ', 'Rua Leopoldo Fróes', 'Santa Teresa', '1076', 'Fundos', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Terapia de alimentação e estimulação da fala.', 'Rio de Janeiro', 'RJ', 'Rua Leopoldo Fróes', 'Santa Teresa', '1076', 'Fundos', 1120.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Acompanhamento do desenvolvimento e orientação à família.', 'Rio de Janeiro', 'RJ', 'Rua Leopoldo Fróes', 'Santa Teresa', '1076', 'Fundos', 520.00, 'normal', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 70] Francisco das Chagas Rocha - 3 especialidades - Tijuca (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 70] Multidisciplinar - Francisco das Chagas Rocha',
  'Francisco das Chagas Rocha',
  'Enfermagem',
  '3x_semana',
  'baixa',
  2480.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Barão de Mesquita',
  '1113',
  'Apto 21',
  'Tijuca',
  '20540004',
  'Zona Norte',
  'Rua Barão de Mesquita, 1113 - Apto 21 - Tijuca - Rio de Janeiro/RJ - CEP 20540-004',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 69 anos, diabético insulinodependente, úlcera plantar em pé direito há 2 meses, glicemias descontroladas.

Serviços: Enfermagem 3x por semana, Nutrição quinzenal, Fisioterapia 2x por semana.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Paciente mora sozinho. Vizinha tem a chave.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 70]

PAD: Enfermagem 3x por semana + Nutrição quinzenal + Fisioterapia 2x por semana.

POSSÍVEL DATA: 03/12/2026
HORA: 09h30

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Francisco das Chagas Rocha

DATA DE NASCIMENTO: 08/02/1957

IDADE: 69 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Barão de Mesquita, 1113 - Apto 21 - Tijuca - Rio de Janeiro/RJ - CEP 20540-004

TELEFONE: (21) 90000-2029 - Cláudia (mãe)

HD: Diabetes tipo 2 com úlcera em pé direito.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente mora sozinho. Vizinha tem a chave.

SERVIÇOS SOLICITADOS:
- Enfermagem (3x por semana): Curativo da úlcera e controle glicêmico.
- Nutrição (quinzenal): Plano alimentar para controle glicêmico.
- Fisioterapia (2x por semana): Fortalecimento e orientação de descarga de peso.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Enfermagem', 'Curativo da úlcera e controle glicêmico.', 'Rio de Janeiro', 'RJ', 'Rua Barão de Mesquita', 'Tijuca', '1113', 'Apto 21', 1080.00, 'baixa', '3x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Plano alimentar para controle glicêmico.', 'Rio de Janeiro', 'RJ', 'Rua Barão de Mesquita', 'Tijuca', '1113', 'Apto 21', 200.00, 'baixa', 'quinzenal', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Fortalecimento e orientação de descarga de peso.', 'Rio de Janeiro', 'RJ', 'Rua Barão de Mesquita', 'Tijuca', '1113', 'Apto 21', 1200.00, 'baixa', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 71] Geni Ribeiro Duarte - 2 especialidades - Santa Cruz (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 71] Multidisciplinar - Geni Ribeiro Duarte',
  'Geni Ribeiro Duarte',
  'Fisioterapia',
  '5x_semana',
  'normal',
  4120.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Felipe Cardoso',
  '1150',
  NULL,
  'Santa Cruz',
  '23510000',
  'Zona Oeste',
  'Rua Felipe Cardoso, 1150 - Santa Cruz - Rio de Janeiro/RJ - CEP 23510-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 57 anos, ELA com 2 anos de evolução, fraqueza nos quatro membros, disfagia progressiva, usa BiPAP noturno.

Serviços: Fisioterapia 5x por semana, Fonoaudiologia 2x por semana.

Dispositivos: BiPAP noturno. Convênio NotreDame Intermédica.',
  'Troca de prestador. Família muito presente.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 71]

PAD: Fisioterapia 5x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 24/11/2026
HORA: 10h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Geni Ribeiro Duarte

DATA DE NASCIMENTO: 15/07/1969

IDADE: 57 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Rua Felipe Cardoso, 1150 - Santa Cruz - Rio de Janeiro/RJ - CEP 23510-000

TELEFONE: (21) 90000-2030 - Roberto (sobrinha)

HD: Esclerose lateral amiotrófica (ELA).

DISPOSITIVO: (X) SIM = QUAL? BiPAP noturno  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Troca de prestador. Família muito presente.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia respiratória e motora, manejo do BiPAP.
- Fonoaudiologia (2x por semana): Manejo da disfagia e comunicação alternativa.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Notre%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Notre%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória e motora, manejo do BiPAP.', 'Rio de Janeiro', 'RJ', 'Rua Felipe Cardoso', 'Santa Cruz', '1150', NULL, 3000.00, 'normal', '5x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Manejo da disfagia e comunicação alternativa.', 'Rio de Janeiro', 'RJ', 'Rua Felipe Cardoso', 'Santa Cruz', '1150', NULL, 1120.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 72] Heitor Vasconcelos Lima - 3 especialidades - Leblon (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 72] Multidisciplinar - Heitor Vasconcelos Lima',
  'Heitor Vasconcelos Lima',
  'Psicologia',
  '2x_semana',
  'normal',
  2440.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua General Urquiza',
  '1187',
  'Casa 3',
  'Leblon',
  '22431040',
  'Zona Sul',
  'Rua General Urquiza, 1187 - Casa 3 - Leblon - Rio de Janeiro/RJ - CEP 22431-040',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 77 anos, quadro depressivo após viuvez há 6 meses, isolamento, perda de peso de 6 kg e sedentarismo.

Serviços: Psicologia 2x por semana, Nutrição quinzenal, Fisioterapia 2x por semana.

Dispositivos: nenhum. Convênio Hapvida.',
  'Filho mora em outro estado; prefere contato por WhatsApp.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 72]

PAD: Psicologia 2x por semana + Nutrição quinzenal + Fisioterapia 2x por semana.

POSSÍVEL DATA: 25/11/2026
HORA: 13h30

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Heitor Vasconcelos Lima

DATA DE NASCIMENTO: 22/12/1949

IDADE: 77 anos

CONVÊNIO: Hapvida

ENDEREÇO: Rua General Urquiza, 1187 - Casa 3 - Leblon - Rio de Janeiro/RJ - CEP 22431-040

TELEFONE: (21) 90000-2031 - Simone (irmã)

HD: Depressão grave em idosa após viuvez.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Filho mora em outro estado; prefere contato por WhatsApp.

SERVIÇOS SOLICITADOS:
- Psicologia (2x por semana): Psicoterapia domiciliar.
- Nutrição (quinzenal): Recuperação de peso e rotina alimentar.
- Fisioterapia (2x por semana): Atividade física orientada e prevenção de quedas.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Psicologia', 'Psicoterapia domiciliar.', 'Rio de Janeiro', 'RJ', 'Rua General Urquiza', 'Leblon', '1187', 'Casa 3', 1040.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Recuperação de peso e rotina alimentar.', 'Rio de Janeiro', 'RJ', 'Rua General Urquiza', 'Leblon', '1187', 'Casa 3', 200.00, 'normal', 'quinzenal', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Atividade física orientada e prevenção de quedas.', 'Rio de Janeiro', 'RJ', 'Rua General Urquiza', 'Leblon', '1187', 'Casa 3', 1200.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 73] Iolanda Batista Freire - 4 especialidades - Gamboa (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 73] Multidisciplinar - Iolanda Batista Freire',
  'Iolanda Batista Freire',
  'Técnico de Enfermagem',
  '7x_semana',
  'urgente',
  9520.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Pedro Ernesto',
  '1224',
  'Apto 302',
  'Gamboa',
  '20220350',
  'Centro',
  'Rua Pedro Ernesto, 1224 - Apto 302 - Gamboa - Rio de Janeiro/RJ - CEP 20220-350',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 64 anos, alta de UTI após 40 dias, traqueostomizado em ar ambiente, alimentação por gastrostomia, acamado.

Serviços: Técnico de Enfermagem diariamente, Enfermagem 1x por semana, Fisioterapia 5x por semana, Fonoaudiologia 2x por semana.

Dispositivos: TQT + GTT. Convênio Unimed.',
  'Cama hospitalar já instalada no quarto.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 73]

PAD: Técnico de Enfermagem diariamente + Enfermagem 1x por semana + Fisioterapia 5x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 26/11/2026
HORA: 14h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Iolanda Batista Freire

DATA DE NASCIMENTO: 01/05/1962

IDADE: 64 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua Pedro Ernesto, 1224 - Apto 302 - Gamboa - Rio de Janeiro/RJ - CEP 20220-350

TELEFONE: (21) 90000-2032 - Marcos (filha)

HD: Traqueostomia após internação prolongada em UTI.

DISPOSITIVO: (X) SIM = QUAL? TQT + GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Cama hospitalar já instalada no quarto.

SERVIÇOS SOLICITADOS:
- Técnico de Enfermagem (diariamente): Plantão 12h: aspiração, dieta por GTT e mudança de decúbito.
- Enfermagem (1x por semana): Supervisão, troca de cânula e avaliação de feridas.
- Fisioterapia (5x por semana): Fisioterapia respiratória e motora no leito.
- Fonoaudiologia (2x por semana): Avaliação para decanulação e retorno da via oral.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Técnico de Enfermagem', 'Plantão 12h: aspiração, dieta por GTT e mudança de decúbito.', 'Rio de Janeiro', 'RJ', 'Rua Pedro Ernesto', 'Gamboa', '1224', 'Apto 302', 5040.00, 'urgente', '7x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Supervisão, troca de cânula e avaliação de feridas.', 'Rio de Janeiro', 'RJ', 'Rua Pedro Ernesto', 'Gamboa', '1224', 'Apto 302', 360.00, 'urgente', '1x_semana', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória e motora no leito.', 'Rio de Janeiro', 'RJ', 'Rua Pedro Ernesto', 'Gamboa', '1224', 'Apto 302', 3000.00, 'urgente', '5x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Avaliação para decanulação e retorno da via oral.', 'Rio de Janeiro', 'RJ', 'Rua Pedro Ernesto', 'Gamboa', '1224', 'Apto 302', 1120.00, 'urgente', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 74] João Pedro Cardoso - 2 especialidades - Vila Isabel (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 74] Multidisciplinar - João Pedro Cardoso',
  'João Pedro Cardoso',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2920.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Teodoro da Silva',
  '1261',
  'Casa 1',
  'Vila Isabel',
  '20560005',
  'Zona Norte',
  'Rua Teodoro da Silva, 1261 - Casa 1 - Vila Isabel - Rio de Janeiro/RJ - CEP 20560-005',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 74 anos, Parkinson há 10 anos, bradicinesia, congelamento da marcha, voz baixa e três quedas no último mês.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Bradesco Saúde.',
  'Usa bengala dentro de casa. Esposa acompanha.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 74]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 27/11/2026
HORA: 15h30

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: João Pedro Cardoso

DATA DE NASCIMENTO: 08/10/1952

IDADE: 74 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Teodoro da Silva, 1261 - Casa 1 - Vila Isabel - Rio de Janeiro/RJ - CEP 20560-005

TELEFONE: (21) 90000-2033 - Larissa (filho)

HD: Doença de Parkinson com quedas frequentes.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Usa bengala dentro de casa. Esposa acompanha.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia neurofuncional: prevenção de quedas e estratégias para congelamento.
- Fonoaudiologia (2x por semana): Terapia de voz e deglutição.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia neurofuncional: prevenção de quedas e estratégias para congelamento.', 'Rio de Janeiro', 'RJ', 'Rua Teodoro da Silva', 'Vila Isabel', '1261', 'Casa 1', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Terapia de voz e deglutição.', 'Rio de Janeiro', 'RJ', 'Rua Teodoro da Silva', 'Vila Isabel', '1261', 'Casa 1', 1120.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 75] Laura Valentina Ramos - 3 especialidades - Taquara (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 75] Multidisciplinar - Laura Valentina Ramos',
  'Laura Valentina Ramos',
  'Enfermagem',
  '2x_semana',
  'baixa',
  2720.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Estrada do Rio Grande',
  '1298',
  NULL,
  'Taquara',
  '22723020',
  'Zona Oeste',
  'Estrada do Rio Grande, 1298 - Taquara - Rio de Janeiro/RJ - CEP 22723-020',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 82 anos, insuficiência cardíaca com alta hospitalar há 10 dias, edema de membros inferiores, cansaço aos pequenos esforços.

Serviços: Enfermagem 2x por semana, Fisioterapia 3x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio SulAmérica Saúde.',
  'Cardiologista pede controle de peso diário.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 75]

PAD: Enfermagem 2x por semana + Fisioterapia 3x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 28/11/2026
HORA: 16h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Laura Valentina Ramos

DATA DE NASCIMENTO: 15/03/1944

IDADE: 82 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Estrada do Rio Grande, 1298 - Taquara - Rio de Janeiro/RJ - CEP 22723-020

TELEFONE: (21) 90000-2034 - Adriana (esposa)

HD: Insuficiência cardíaca descompensada recente.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cardiologista pede controle de peso diário.

SERVIÇOS SOLICITADOS:
- Enfermagem (2x por semana): Controle de sinais vitais, peso e adesão aos medicamentos.
- Fisioterapia (3x por semana): Reabilitação cardiovascular leve.
- Nutrição (quinzenal): Dieta hipossódica e controle hídrico.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Enfermagem', 'Controle de sinais vitais, peso e adesão aos medicamentos.', 'Rio de Janeiro', 'RJ', 'Estrada do Rio Grande', 'Taquara', '1298', NULL, 720.00, 'baixa', '2x_semana', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Reabilitação cardiovascular leve.', 'Rio de Janeiro', 'RJ', 'Estrada do Rio Grande', 'Taquara', '1298', NULL, 1800.00, 'baixa', '3x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Dieta hipossódica e controle hídrico.', 'Rio de Janeiro', 'RJ', 'Estrada do Rio Grande', 'Taquara', '1298', NULL, 200.00, 'baixa', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 76] Milton Soares Rangel - 3 especialidades - Laranjeiras (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 76] Multidisciplinar - Milton Soares Rangel',
  'Milton Soares Rangel',
  'Fonoaudiologia',
  '3x_semana',
  'normal',
  2920.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Pinheiro Machado',
  '1335',
  'Apto 1104, Bloco 2',
  'Laranjeiras',
  '22231090',
  'Zona Sul',
  'Rua Pinheiro Machado, 1335 - Apto 1104, Bloco 2 - Laranjeiras - Rio de Janeiro/RJ - CEP 22231-090',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 3 anos, criança com TEA nível 2, fala com poucas palavras, seletividade alimentar importante.

Serviços: Fonoaudiologia 3x por semana, Psicologia 2x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio Amil.',
  'Atendimentos de preferência no fim da tarde.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 76]

PAD: Fonoaudiologia 3x por semana + Psicologia 2x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 29/11/2026
HORA: 08h00

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Milton Soares Rangel

DATA DE NASCIMENTO: 22/08/2023

IDADE: 3 anos

CONVÊNIO: Amil

ENDEREÇO: Rua Pinheiro Machado, 1335 - Apto 1104, Bloco 2 - Laranjeiras - Rio de Janeiro/RJ - CEP 22231-090

TELEFONE: (21) 90000-2035 - Renata (marido)

HD: Autismo nível 2 com atraso de linguagem.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Atendimentos de preferência no fim da tarde.

SERVIÇOS SOLICITADOS:
- Fonoaudiologia (3x por semana): Estimulação da linguagem e comunicação.
- Psicologia (2x por semana): Intervenção comportamental e orientação parental.
- Nutrição (quinzenal): Manejo da seletividade alimentar.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fonoaudiologia', 'Estimulação da linguagem e comunicação.', 'Rio de Janeiro', 'RJ', 'Rua Pinheiro Machado', 'Laranjeiras', '1335', 'Apto 1104, Bloco 2', 1680.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Intervenção comportamental e orientação parental.', 'Rio de Janeiro', 'RJ', 'Rua Pinheiro Machado', 'Laranjeiras', '1335', 'Apto 1104, Bloco 2', 1040.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Manejo da seletividade alimentar.', 'Rio de Janeiro', 'RJ', 'Rua Pinheiro Machado', 'Laranjeiras', '1335', 'Apto 1104, Bloco 2', 200.00, 'normal', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 77] Neide Aparecida Fontes - 2 especialidades - Rio Comprido (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 77] Multidisciplinar - Neide Aparecida Fontes',
  'Neide Aparecida Fontes',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2920.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Barão de Petrópolis',
  '1372',
  'Fundos',
  'Rio Comprido',
  '20251061',
  'Centro',
  'Rua Barão de Petrópolis, 1372 - Fundos - Rio Comprido - Rio de Janeiro/RJ - CEP 20251-061',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 75 anos, pós-AVC isquêmico há 30 dias, hemiparesia à esquerda, disfagia leve para líquidos, marcha com apoio de terceiros.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Porto Seguro Saúde.',
  'Filha acompanha nos atendimentos. Apartamento com elevador.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 77]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 30/11/2026
HORA: 09h30

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Neide Aparecida Fontes

DATA DE NASCIMENTO: 01/01/1951

IDADE: 75 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua Barão de Petrópolis, 1372 - Fundos - Rio Comprido - Rio de Janeiro/RJ - CEP 20251-061

TELEFONE: (21) 90000-2036 - Sílvia (neta)

HD: AVC isquêmico com hemiparesia à esquerda.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Filha acompanha nos atendimentos. Apartamento com elevador.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação motora: marcha, equilíbrio e fortalecimento do hemicorpo esquerdo.
- Fonoaudiologia (2x por semana): Reabilitação da deglutição e adaptação de consistências.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Reabilitação motora: marcha, equilíbrio e fortalecimento do hemicorpo esquerdo.', 'Rio de Janeiro', 'RJ', 'Rua Barão de Petrópolis', 'Rio Comprido', '1372', 'Fundos', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Reabilitação da deglutição e adaptação de consistências.', 'Rio de Janeiro', 'RJ', 'Rua Barão de Petrópolis', 'Rio Comprido', '1372', 'Fundos', 1120.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 78] Orlando Queiroz Melo - 3 especialidades - Méier (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 78] Multidisciplinar - Orlando Queiroz Melo',
  'Orlando Queiroz Melo',
  'Fisioterapia',
  '5x_semana',
  'urgente',
  3560.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Silva Rabelo',
  '1409',
  'Apto 21',
  'Méier',
  '20735080',
  'Zona Norte',
  'Rua Silva Rabelo, 1409 - Apto 21 - Méier - Rio de Janeiro/RJ - CEP 20735-080',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 81 anos, pós-operatório de osteossíntese de fêmur há 20 dias, carga parcial com andador, dor controlada.

Serviços: Fisioterapia 5x por semana, Enfermagem 1x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Mora com o marido idoso. Precisa de horário pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 78]

PAD: Fisioterapia 5x por semana + Enfermagem 1x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 01/12/2026
HORA: 10h00

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Orlando Queiroz Melo

DATA DE NASCIMENTO: 08/06/1945

IDADE: 81 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Silva Rabelo, 1409 - Apto 21 - Méier - Rio de Janeiro/RJ - CEP 20735-080

TELEFONE: (21) 90000-2037 - Cláudia (mãe)

HD: Pós-operatório de fratura de fêmur (osteossíntese).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Mora com o marido idoso. Precisa de horário pela manhã.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia motora pós-operatória: ganho de amplitude, fortalecimento e treino de marcha.
- Enfermagem (1x por semana): Curativo da ferida operatória e retirada de pontos.
- Nutrição (quinzenal): Dieta para consolidação óssea e prevenção de sarcopenia.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia motora pós-operatória: ganho de amplitude, fortalecimento e treino de marcha.', 'Rio de Janeiro', 'RJ', 'Rua Silva Rabelo', 'Méier', '1409', 'Apto 21', 3000.00, 'urgente', '5x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Curativo da ferida operatória e retirada de pontos.', 'Rio de Janeiro', 'RJ', 'Rua Silva Rabelo', 'Méier', '1409', 'Apto 21', 360.00, 'urgente', '1x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Dieta para consolidação óssea e prevenção de sarcopenia.', 'Rio de Janeiro', 'RJ', 'Rua Silva Rabelo', 'Méier', '1409', 'Apto 21', 200.00, 'urgente', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 79] Rosa Maria Siqueira - 4 especialidades - Barra da Tijuca (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 79] Multidisciplinar - Rosa Maria Siqueira',
  'Rosa Maria Siqueira',
  'Técnico de Enfermagem',
  '7x_semana',
  'normal',
  7320.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Avenida Sernambetiba',
  '1446',
  NULL,
  'Barra da Tijuca',
  '22620170',
  'Zona Oeste',
  'Avenida Sernambetiba, 1446 - Barra da Tijuca - Rio de Janeiro/RJ - CEP 22620-170',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 85 anos, Alzheimer moderado, desorientação, episódios de agitação ao entardecer, deambula com supervisão.

Serviços: Técnico de Enfermagem diariamente, Psicologia 1x por semana, Fisioterapia 2x por semana, Fonoaudiologia 1x por semana.

Dispositivos: nenhum. Convênio NotreDame Intermédica.',
  'Família pede profissional com experiência em demência.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 79]

PAD: Técnico de Enfermagem diariamente + Psicologia 1x por semana + Fisioterapia 2x por semana + Fonoaudiologia 1x por semana.

POSSÍVEL DATA: 02/12/2026
HORA: 13h30

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Rosa Maria Siqueira

DATA DE NASCIMENTO: 15/11/1941

IDADE: 85 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Avenida Sernambetiba, 1446 - Barra da Tijuca - Rio de Janeiro/RJ - CEP 22620-170

TELEFONE: (21) 90000-2038 - Roberto (sobrinha)

HD: Doença de Alzheimer em fase moderada.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família pede profissional com experiência em demência.

SERVIÇOS SOLICITADOS:
- Técnico de Enfermagem (diariamente): Acompanhamento diurno 12h: higiene, alimentação e administração de medicação.
- Psicologia (1x por semana): Orientação familiar e estimulação cognitiva.
- Fisioterapia (2x por semana): Prevenção de quedas e manutenção da mobilidade.
- Fonoaudiologia (1x por semana): Estimulação da linguagem e da memória.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Notre%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Notre%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Técnico de Enfermagem', 'Acompanhamento diurno 12h: higiene, alimentação e administração de medicação.', 'Rio de Janeiro', 'RJ', 'Avenida Sernambetiba', 'Barra da Tijuca', '1446', NULL, 5040.00, 'normal', '7x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Orientação familiar e estimulação cognitiva.', 'Rio de Janeiro', 'RJ', 'Avenida Sernambetiba', 'Barra da Tijuca', '1446', NULL, 520.00, 'normal', '1x_semana', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Prevenção de quedas e manutenção da mobilidade.', 'Rio de Janeiro', 'RJ', 'Avenida Sernambetiba', 'Barra da Tijuca', '1446', NULL, 1200.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Estimulação da linguagem e da memória.', 'Rio de Janeiro', 'RJ', 'Avenida Sernambetiba', 'Barra da Tijuca', '1446', NULL, 560.00, 'normal', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- [TESTE 80] Sebastiana Lopes Brito - 2 especialidades - Madureira (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 80] Multidisciplinar - Sebastiana Lopes Brito',
  'Sebastiana Lopes Brito',
  'Fisioterapia',
  '3x_semana',
  'baixa',
  2160.00,
  'aguardando_captacao',
  'Rio de Janeiro',
  'RJ',
  'Rua Carolina Machado',
  '1483',
  'Casa 3',
  'Madureira',
  '21350005',
  'Zona Norte',
  'Rua Carolina Machado, 1483 - Casa 3 - Madureira - Rio de Janeiro/RJ - CEP 21350-005',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 72 anos, DPOC grave, O2 domiciliar 2 L/min, dispneia aos pequenos esforços, internação recente por exacerbação.

Serviços: Fisioterapia 3x por semana, Enfermagem 1x por semana.

Dispositivos: Concentrador de O2. Convênio Hapvida.',
  'Paciente ex-tabagista. Casa térrea.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 80]

PAD: Fisioterapia 3x por semana + Enfermagem 1x por semana.

POSSÍVEL DATA: 03/12/2026
HORA: 14h00

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Sebastiana Lopes Brito

DATA DE NASCIMENTO: 22/04/1954

IDADE: 72 anos

CONVÊNIO: Hapvida

ENDEREÇO: Rua Carolina Machado, 1483 - Casa 3 - Madureira - Rio de Janeiro/RJ - CEP 21350-005

TELEFONE: (21) 90000-2039 - Simone (irmã)

HD: DPOC com dependência de oxigênio domiciliar.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente ex-tabagista. Casa térrea.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia respiratória: higiene brônquica e reexpansão pulmonar.
- Enfermagem (1x por semana): Avaliação clínica, controle de saturação e orientação do uso do O2.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória: higiene brônquica e reexpansão pulmonar.', 'Rio de Janeiro', 'RJ', 'Rua Carolina Machado', 'Madureira', '1483', 'Casa 3', 1800.00, 'baixa', '3x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Avaliação clínica, controle de saturação e orientação do uso do O2.', 'Rio de Janeiro', 'RJ', 'Rua Carolina Machado', 'Madureira', '1483', 'Casa 3', 360.00, 'baixa', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (lote 41-80, 2026-11-22)');

-- =============================================================================
-- RESULTADO
-- =============================================================================
SELECT d.id, d.title AS titulo, d.location_neighborhood AS bairro, d.location_zone AS zona,
       (SELECT GROUP_CONCAT(s.specialty ORDER BY s.id SEPARATOR ', ') FROM demand_sub_requests s WHERE s.demand_id = d.id) AS especialidades,
       hi.name AS operadora
FROM demands d
LEFT JOIN health_insurers hi ON hi.id = d.health_insurer_id
WHERE d.title LIKE '[TESTE __]%' AND CAST(SUBSTRING(d.title, 8, 2) AS UNSIGNED) BETWEEN 41 AND 80
ORDER BY d.id;

-- PARA APAGAR SÓ ESTE LOTE DEPOIS (rodar separado, se precisar):
--   CREATE TEMPORARY TABLE zz_lote AS SELECT id FROM demands
--     WHERE title LIKE '[TESTE __]%' AND CAST(SUBSTRING(title, 8, 2) AS UNSIGNED) BETWEEN 41 AND 80;
--   DELETE FROM demand_sub_requests WHERE demand_id IN (SELECT id FROM zz_lote);
--   DELETE FROM demand_status_logs WHERE demand_id IN (SELECT id FROM zz_lote);
--   DELETE FROM demands WHERE id IN (SELECT id FROM zz_lote);
