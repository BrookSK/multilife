-- ============================================================================
-- Card de PROSPECÇÃO / CAPTAÇÃO de TESTE — preenchendo TODOS os campos possíveis
-- Paciente: Maria Aparecida Souza (atendimento multidisciplinar domiciliar)
--
-- Objetivo: criar um card completo para testes, exercitando TODAS as colunas
-- disponíveis da tabela `demands` (acumuladas pelas migrations) + sub-solicitações
-- (demand_sub_requests) + log de status (demand_status_logs).
--
-- Status inicial: 'aguardando_captacao' => aparece na coluna "Recebimento de E-mail".
-- Trocar para 'tratamento_manual' caso queira que entre direto em "Tratamento Manual".
--
-- OBS: client_id, health_insurer_id, service_type_id e assumed_by_user_id ficam NULL
--      por padrão para evitar erro de FK em bases sem esses registros. Logo abaixo há
--      um bloco OPCIONAL que resolve esses IDs automaticamente caso existam.
-- ============================================================================

-- 1) Demanda principal (card) — todos os campos possíveis
INSERT INTO demands (
    title,
    patient_name,
    location_city,
    location_state,
    location_street,
    location_neighborhood,
    location_number,
    location_complement,
    location_raw,
    specialty,
    description,
    origin_email,
    source_email_id,
    procedure_value,
    ai_summary,
    captation_note,
    urgency,
    frequency,
    has_multiple_requests,
    status,
    cancellation_reason,
    cancelled_at,
    archived_at,
    archived_reason,
    service_type_id,
    client_id,
    health_insurer_id,
    is_confidential,
    confidential_exclude_user_id,
    assumed_by_user_id,
    assumed_at,
    created_at,
    updated_at
) VALUES (
    'Atendimento multidisciplinar domiciliar para Maria Aparecida',  -- title
    'Maria Aparecida Souza',                                         -- patient_name
    'São Paulo',                                                     -- location_city
    'SP',                                                            -- location_state
    'Avenida Paulista',                                              -- location_street
    'Bela Vista',                                                    -- location_neighborhood
    '1578',                                                          -- location_number
    'Apto 42, Bloco B',                                              -- location_complement
    'Av. Paulista, 1578, Apto 42 Bloco B - Bela Vista, São Paulo/SP, CEP 01310-200', -- location_raw (texto bruto)
    'Fisioterapia Domiciliar',                                       -- specialty (especialidade principal)
    CONCAT(
        'Solicitação de atendimento multidisciplinar domiciliar para Maria Aparecida Souza, 68 anos.\n\n',
        'DIAGNÓSTICO: Pós-operatório de artroplastia de quadril com mobilidade reduzida, ',
        'quadro de DPOC e acompanhamento clínico contínuo. Paciente teve alta hospitalar recente ',
        'e precisa de reabilitação motora, suporte respiratório e acompanhamento de enfermagem em domicílio.\n\n',
        'SERVIÇOS SOLICITADOS:\n',
        '- Fisioterapia domiciliar: reabilitação motora e respiratória. 3 sessões/semana, ~1h por sessão.\n',
        '- Fonoaudiologia domiciliar: avaliação e reabilitação da deglutição. 2 sessões/semana, ~45min.\n',
        '- Enfermagem domiciliar: curativos, medicações e monitoramento clínico. Visitas diárias, ~2h/dia.\n',
        '- Psicologia domiciliar: suporte emocional no pós-operatório. 1 sessão/semana, ~1h.\n\n',
        'PERÍODO: inicialmente 3 meses, com possibilidade de extensão conforme evolução clínica.\n',
        'VALOR: plano autorizou atendimento multidisciplinar domiciliar no valor total de R$ 12.800,00 por mês.\n',
        'CONTATO: Fernanda Souza Lima (filha) - (11) 98877-6655.'
    ),                                                               -- description
    'fernanda.souza@example.com',                                    -- origin_email
    NULL,                                                            -- source_email_id (FK inbound_emails; NULL p/ evitar erro)
    12800.00,                                                        -- procedure_value (valor total mensal)
    CONCAT(
        'Paciente 68 anos, pós-artroplastia de quadril + DPOC. Precisa de atendimento ',
        'multidisciplinar domiciliar: fisioterapia 3x/sem, fonoaudiologia 2x/sem, enfermagem diária ',
        'e psicologia 1x/sem, por ~3 meses. Plano autorizou R$ 12.800,00/mês. Início com urgência alta.'
    ),                                                               -- ai_summary
    'Cliente pediu início o quanto antes. Confirmar disponibilidade de fisioterapeuta na região da Paulista. Família muito presente.', -- captation_note (aparece na mensagem WhatsApp)
    'urgente',                                                       -- urgency (urgente | normal | baixa)
    '3x_semana',                                                     -- frequency
    1,                                                               -- has_multiple_requests (1 = múltiplas especialidades)
    'aguardando_captacao',                                           -- status
    NULL,                                                            -- cancellation_reason
    NULL,                                                            -- cancelled_at
    NULL,                                                            -- archived_at
    NULL,                                                            -- archived_reason
    NULL,                                                            -- service_type_id (FK; NULL por padrão)
    NULL,                                                            -- client_id (FK; NULL por padrão)
    NULL,                                                            -- health_insurer_id (FK; NULL por padrão)
    0,                                                               -- is_confidential
    NULL,                                                            -- confidential_exclude_user_id
    NULL,                                                            -- assumed_by_user_id (FK users; NULL por padrão)
    NULL,                                                            -- assumed_at
    NOW(),                                                           -- created_at
    NOW()                                                            -- updated_at
);

-- Guardar o ID da demanda recém-criada
SET @demand_id = LAST_INSERT_ID();

-- 2) Sub-solicitações (uma por especialidade) — todos os campos possíveis
INSERT INTO demand_sub_requests (
    demand_id,
    specialty,
    description,
    location_city,
    location_state,
    location_street,
    location_neighborhood,
    location_number,
    location_complement,
    procedure_value,
    urgency,
    frequency,
    status,
    dispatched_at,
    created_at
) VALUES
    (@demand_id, 'Fisioterapia Domiciliar',
     'Reabilitação motora e respiratória pós-artroplastia de quadril. 3 sessões/semana, ~1h por sessão.',
     'São Paulo', 'SP', 'Avenida Paulista', 'Bela Vista', '1578', 'Apto 42, Bloco B',
     4800.00, 'urgente', '3x_semana', 'pendente', NULL, NOW()),

    (@demand_id, 'Fonoaudiologia Domiciliar',
     'Avaliação e reabilitação da deglutição. 2 sessões/semana, ~45min por sessão.',
     'São Paulo', 'SP', 'Avenida Paulista', 'Bela Vista', '1578', 'Apto 42, Bloco B',
     2600.00, 'urgente', '2x_semana', 'pendente', NULL, NOW()),

    (@demand_id, 'Enfermagem Domiciliar',
     'Curativos, administração de medicações e monitoramento clínico. Visitas diárias, ~2h por dia.',
     'São Paulo', 'SP', 'Avenida Paulista', 'Bela Vista', '1578', 'Apto 42, Bloco B',
     3900.00, 'urgente', '7x_semana', 'pendente', NULL, NOW()),

    (@demand_id, 'Psicologia Domiciliar',
     'Suporte emocional no pós-operatório. 1 sessão/semana, ~1h por sessão.',
     'São Paulo', 'SP', 'Avenida Paulista', 'Bela Vista', '1578', 'Apto 42, Bloco B',
     1500.00, 'normal', '1x_semana', 'pendente', NULL, NOW());

-- 3) Log de status inicial da demanda
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (@demand_id, NULL, 'aguardando_captacao', NULL,
        'Card de prospecção de teste (completo) criado via SQL.');

-- ============================================================================
-- 4) OPCIONAL — vincular FKs automaticamente SE existirem registros na base.
--    Descomente o bloco abaixo para preencher client_id / health_insurer_id /
--    service_type_id / assumed_by_user_id usando o primeiro registro disponível.
-- ============================================================================
-- UPDATE demands
-- SET
--     client_id          = (SELECT id FROM clients            ORDER BY id LIMIT 1),
--     health_insurer_id  = (SELECT id FROM health_insurers    ORDER BY id LIMIT 1),
--     service_type_id    = (SELECT id FROM service_types       ORDER BY id LIMIT 1),
--     assumed_by_user_id = (SELECT id FROM users               ORDER BY id LIMIT 1),
--     assumed_at         = NOW()
-- WHERE id = @demand_id;

-- ============================================================================
-- 5) CONFERÊNCIA (opcional)
-- ============================================================================
-- SELECT * FROM demands              WHERE id = @demand_id;
-- SELECT * FROM demand_sub_requests  WHERE demand_id = @demand_id;
-- SELECT * FROM demand_status_logs   WHERE demand_id = @demand_id;
