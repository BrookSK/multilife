-- ============================================================================
-- Card de captação de TESTE — Roberto Almeida Ferreira (atendimento multidisciplinar)
-- Origem: e-mail de solicitação de orçamento (Juliana Almeida Ferreira).
-- Cria a demanda principal + 4 sub-solicitações (Fisio, Fono, Enfermagem, Psicologia)
-- + log de status inicial.
--
-- Observações:
--  - Status inicial: 'aguardando_captacao' (o card aparece na coluna "Recebimento de E-mail").
--    Se preferir que ele já entre em "Tratamento Manual", troque para 'tratamento_manual'.
--  - procedure_value = 11500.00 (valor total mensal autorizado pelo plano, conforme o e-mail).
--  - urgency = 'urgente' (o solicitante pediu início nos próximos dias).
-- ============================================================================

-- 1) Demanda principal
INSERT INTO demands
    (title, patient_name, location_city, location_state, location_street, location_neighborhood, location_number,
     specialty, description, origin_email, status, procedure_value, ai_summary, urgency, frequency, has_multiple_requests, created_at)
VALUES
    (
        'Atendimento multidisciplinar domiciliar para Roberto',
        'Roberto Almeida Ferreira',
        'São Paulo',
        'SP',
        'Rua das Flores',
        'Centro',
        '123',
        'Fisioterapia Domiciliar',
        CONCAT(
            'Solicitação de atendimento multidisciplinar domiciliar para Roberto Almeida Ferreira, 72 anos.\n\n',
            'DIAGNÓSTICO: Recuperação pós-AVC isquêmico com limitações motoras, dificuldades na fala e necessidade de acompanhamento contínuo. ',
            'Paciente recebeu alta hospitalar recentemente e necessita de acompanhamento domiciliar contínuo para reabilitação motora, suporte clínico e acompanhamento terapêutico multidisciplinar. ',
            'Apresenta dificuldades de locomoção, fala reduzida e necessidade de auxílio parcial nas atividades diárias.\n\n',
            'SERVIÇOS SOLICITADOS:\n',
            '- Fisioterapia domiciliar: reabilitação motora, fortalecimento muscular e mobilidade. 3 sessões/semana, ~1h por sessão.\n',
            '- Fonoaudiologia domiciliar: reabilitação da fala, comunicação e deglutição. 2 sessões/semana, ~45min por sessão.\n',
            '- Enfermagem domiciliar: administração de medicações, monitoramento clínico e suporte geral. Visitas diárias, ~2h por dia.\n',
            '- Psicologia domiciliar: suporte emocional e acompanhamento psicológico. 1 sessão/semana, ~1h por sessão.\n\n',
            'PERÍODO: inicialmente 3 meses, com possibilidade de extensão conforme evolução clínica.\n',
            'VALOR: plano de saúde autorizou atendimento multidisciplinar domiciliar no valor total de R$ 11.500,00 por mês.\n',
            'CONTATO: Juliana Almeida Ferreira - (12) 99741-5528.'
        ),
        'juliana.almeida@example.com',
        'aguardando_captacao',
        11500.00,
        'Paciente 72 anos, pós-AVC isquêmico com limitações motoras e de fala. Precisa de atendimento multidisciplinar domiciliar (fisioterapia 3x/sem, fonoaudiologia 2x/sem, enfermagem diária, psicologia 1x/sem) por ~3 meses. Plano autorizou R$ 11.500,00/mês. Início urgente.',
        'urgente',
        '3x_semana',
        1,
        NOW()
    );

-- Guardar o ID da demanda recém-criada para vincular as sub-solicitações
SET @demand_id = LAST_INSERT_ID();

-- 2) Sub-solicitações (uma por especialidade do atendimento multidisciplinar)
INSERT INTO demand_sub_requests
    (demand_id, specialty, description, location_city, location_state, procedure_value, urgency, frequency)
VALUES
    (@demand_id, 'Fisioterapia Domiciliar',
     'Reabilitação motora, fortalecimento muscular e melhora da mobilidade. 3 sessões/semana, ~1h por sessão.',
     'São Paulo', 'SP', NULL, 'urgente', '3x_semana'),

    (@demand_id, 'Fonoaudiologia Domiciliar',
     'Reabilitação da fala, comunicação e funções de deglutição. 2 sessões/semana, ~45min por sessão.',
     'São Paulo', 'SP', NULL, 'urgente', '2x_semana'),

    (@demand_id, 'Enfermagem Domiciliar',
     'Administração de medicações, monitoramento clínico e suporte geral ao paciente. Visitas diárias, ~2h por dia.',
     'São Paulo', 'SP', NULL, 'urgente', '7x_semana'),

    (@demand_id, 'Psicologia Domiciliar',
     'Suporte emocional e acompanhamento psicológico durante a recuperação. 1 sessão/semana, ~1h por sessão.',
     'São Paulo', 'SP', NULL, 'urgente', '1x_semana');

-- 3) Log de status inicial da demanda
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (@demand_id, NULL, 'aguardando_captacao', NULL, 'Card de captação de teste criado via SQL (solicitação por e-mail).');

-- Conferência (opcional): ver o card criado
-- SELECT * FROM demands WHERE id = @demand_id;
-- SELECT * FROM demand_sub_requests WHERE demand_id = @demand_id;
