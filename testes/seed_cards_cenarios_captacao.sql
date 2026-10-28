-- =====================================================================
-- Cards de TESTE da captação: 6 cenários diferentes.
-- Requer a migration 2026-10-27_0002 (colunas location_zone / location_zip).
-- Todos usam "Paciente Teste" e origem @example.com para identificar/limpar depois.
-- Ajuste o nome da especialidade se no seu cadastro for diferente
-- (ex.: "Fisioterapia Domiciliar").
-- =====================================================================

-- 1) CIDADE SEM ZONA, completo -> pronto para captar (cria grupo "Fisioterapia - Mirassol/SP").
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, origin_email,
    location_street, location_number, location_complement, location_neighborhood, location_city, location_state, location_zip,
    ai_summary, captation_note, description, status)
VALUES ('Paciente Teste C - Fisioterapia', 'Paciente Teste C', 'Fisioterapia', '3x_semana', 'normal', 120.00, 'teste.captacao@example.com',
    'Rua Antônio Carlos', '310', NULL, 'Centro', 'Mirassol', 'SP', '15130000',
    'Paciente de 74 anos, pós-AVC há 2 meses, hemiparesia à direita. Necessita fisioterapia motora domiciliar para marcha e transferências.',
    'Preferência por atendimento pela manhã.', 'Cenário 1: cidade sem zona, card completo.', 'aguardando_captacao');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (LAST_INSERT_ID(), NULL, 'aguardando_captacao', NULL, 'seed de teste - cenário 1 (cidade sem zona)');

-- 2) SÃO PAULO CAPITAL, ZONA LESTE pelo bairro (Tatuapé) -> grupo "... - São Paulo/SP - Zona Leste".
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, origin_email,
    location_street, location_number, location_complement, location_neighborhood, location_city, location_state, location_zone, location_zip,
    ai_summary, captation_note, description, status)
VALUES ('Paciente Teste D - Fonoaudiologia', 'Paciente Teste D', 'Fonoaudiologia', '2x_semana', 'normal', 110.00, 'teste.captacao@example.com',
    'Rua Tuiuti', '1200', 'Apto 52', 'Tatuapé', 'São Paulo', 'SP', 'Zona Leste', '03081000',
    'Paciente de 81 anos com disfagia leve após internação. Necessita fonoaudiologia para deglutição e comunicação.',
    NULL, 'Cenário 2: São Paulo capital com zona deduzida do bairro.', 'aguardando_captacao');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (LAST_INSERT_ID(), NULL, 'aguardando_captacao', NULL, 'seed de teste - cenário 2 (SP Zona Leste)');

-- 3) RIO DE JANEIRO, ZONA SUL (Copacabana), URGENTE -> grupo "... - Rio de Janeiro/RJ - Zona Sul".
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, origin_email,
    location_street, location_number, location_complement, location_neighborhood, location_city, location_state, location_zone, location_zip,
    ai_summary, captation_note, description, status)
VALUES ('Paciente Teste E - Enfermagem', 'Paciente Teste E', 'Enfermagem', '7x_semana', 'urgente', 180.00, 'teste.captacao@example.com',
    'Avenida Nossa Senhora de Copacabana', '800', 'Bloco B', 'Copacabana', 'Rio de Janeiro', 'RJ', 'Zona Sul', '22050002',
    'Paciente de 66 anos, curativo de lesão por pressão sacral grau 3 e controle de glicemia. Visitas diárias.',
    'Início imediato.', 'Cenário 3: Rio de Janeiro com zona, urgente.', 'aguardando_captacao');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (LAST_INSERT_ID(), NULL, 'aguardando_captacao', NULL, 'seed de teste - cenário 3 (RJ Zona Sul, urgente)');

-- 4) TRATAMENTO MANUAL: falta UF e bairro -> deve mostrar "Falta: UF" no kanban e no card.
--    Teste: Editar, preencher UF = SP e salvar -> sai sozinho para "Recebimento de E-mail".
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, origin_email,
    location_street, location_number, location_city, location_state,
    ai_summary, description, status)
VALUES ('Paciente Teste F - Fisioterapia', 'Paciente Teste F', 'Fisioterapia', '2x_semana', 'normal', 120.00, 'teste.captacao@example.com',
    'Rua Barão de Jaguara', '900', 'Campinas', NULL,
    'Paciente de 59 anos, pós-operatório de joelho. Fisioterapia motora domiciliar.',
    'Cenário 4: sem UF e sem bairro (tratamento manual).', 'tratamento_manual');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (LAST_INSERT_ID(), NULL, 'tratamento_manual', NULL, 'seed de teste - cenário 4 (tratamento_manual: faltando UF)');

-- 5) TRATAMENTO MANUAL: São Paulo capital com bairro NÃO reconhecido -> falta a ZONA.
--    Teste: Editar e escolher a zona manualmente (ou corrigir o bairro).
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, origin_email,
    location_street, location_number, location_neighborhood, location_city, location_state,
    ai_summary, description, status)
VALUES ('Paciente Teste G - Fonoaudiologia', 'Paciente Teste G', 'Fonoaudiologia', '1x_semana', 'baixa', 100.00, 'teste.captacao@example.com',
    'Rua Exemplo', '45', 'Jardim Inventado', 'São Paulo', 'SP',
    'Paciente de 7 anos com atraso de fala. Fonoaudiologia domiciliar.',
    'Cenário 5: SP capital com bairro desconhecido (falta zona).', 'tratamento_manual');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (LAST_INSERT_ID(), NULL, 'tratamento_manual', NULL, 'seed de teste - cenário 5 (tratamento_manual: faltando zona)');

-- 6) ENDEREÇO SÓ EM UMA LINHA (como vem no e-mail), campos separados vazios.
--    Teste: Editar -> clicar "Separar endereço" (ou só salvar) e conferir os campos preenchidos.
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, origin_email,
    location_raw, ai_summary, description, status)
VALUES ('Paciente Teste H - Fisioterapia', 'Paciente Teste H', 'Fisioterapia', '3x_semana', 'normal', 120.00, 'teste.captacao@example.com',
    'Rua Bernardino de Campos, 1500 - casa 2 - Vila Imperial - São José do Rio Preto/SP - CEP 15015-300',
    'Paciente de 70 anos, Parkinson, risco de quedas. Fisioterapia motora e treino de equilíbrio.',
    'Cenário 6: endereço colado em uma linha só.', 'tratamento_manual');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (LAST_INSERT_ID(), NULL, 'tratamento_manual', NULL, 'seed de teste - cenário 6 (endereço em uma linha)');

-- Conferência:
-- SELECT id, title, status, location_city, location_state, location_zone FROM demands
-- WHERE origin_email = 'teste.captacao@example.com' ORDER BY id DESC LIMIT 10;

-- Limpeza depois dos testes (apaga também logs/disparos ligados por FK em cascata):
-- DELETE FROM demands WHERE origin_email = 'teste.captacao@example.com' AND patient_name LIKE 'Paciente Teste%';
