-- ============================================================================
-- Levar o card de TESTE (Roberto Almeida Ferreira) para a etapa de AUTORIZAÇÃO.
--
-- A tela "Autorização" lê a tabela authorization_requests com
-- status = 'aguardando_autorizacao'. Este script:
--   1) Localiza a demanda de teste já criada (pelo título).
--   2) Garante um PACIENTE para o Roberto (cria se ainda não existir).
--   3) Escolhe um PROFISSIONAL ativo (obrigatório na autorização).
--   4) Cria o registro em authorization_requests (aguardando_autorizacao).
--   5) Atualiza o status da demanda para 'aguardando_autorizacao'.
--
-- Pré-requisito: rodar antes o seed_card_captacao_teste.sql (cria a demanda).
--
-- OBS (regra nova): o valor da operadora NÃO é definido aqui — ele será
-- informado na própria tela de Autorização, ao "Marcar como aprovada".
-- Por isso proposal_value fica 0. O agreed_value (custo do profissional) é
-- de exemplo (R$ 150,00 por sessão).
-- ============================================================================

-- 1) Demanda de teste (criada pelo seed anterior)
SET @demand_id = (
    SELECT id FROM demands
    WHERE title = 'Atendimento multidisciplinar domiciliar para Roberto'
    ORDER BY id DESC LIMIT 1
);

-- 2) Paciente do Roberto: cria só se ainda não existir (por nome)
INSERT INTO patients (full_name, phone_primary, whatsapp, address_street, address_number, address_neighborhood, address_city, address_state, admin_status, registration_date)
SELECT 'Roberto Almeida Ferreira', '12997415528', '12997415528', 'Rua das Flores', '123', 'Centro', 'São Paulo', 'SP', 'Ativo', CURDATE()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM patients WHERE full_name = 'Roberto Almeida Ferreira' AND deleted_at IS NULL
);

SET @patient_id = (
    SELECT id FROM patients
    WHERE full_name = 'Roberto Almeida Ferreira' AND deleted_at IS NULL
    ORDER BY id DESC LIMIT 1
);

-- 3) Um profissional ativo qualquer (obrigatório na autorização).
--    Preferência por especialidade de Fisioterapia; senão, qualquer profissional ativo.
SET @prof_id = (
    SELECT u.id
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE r.slug = 'profissional' AND u.status = 'active'
    ORDER BY (CASE WHEN u.specialty LIKE '%isio%' THEN 0 ELSE 1 END), u.id ASC
    LIMIT 1
);

-- 4) Criar a solicitação de autorização (aparece em "Autorização" > Aguardando Resposta)
INSERT INTO authorization_requests
    (demand_id, patient_id, professional_user_id, proposal_value, agreed_value,
     start_date, start_time, end_time, frequency, frequency_details,
     sessions_per_week, total_sessions, duration_weeks, operator_email, operator_name,
     status, created_by_user_id, created_at)
VALUES
    (
        @demand_id,
        @patient_id,
        @prof_id,
        0.00,                 -- valor da operadora: definido na aprovação da autorização
        150.00,               -- custo do profissional por sessão (exemplo)
        DATE_ADD(CURDATE(), INTERVAL 3 DAY),
        '08:00:00',
        '09:00:00',
        '3x_semana',
        JSON_OBJECT('type', '3x_semana', 'description', 'Fisioterapia 3x/semana', 'sessions_per_week', 3, 'duration_weeks', 12, 'total_sessions', 36),
        3,
        36,
        12,
        'juliana.almeida@example.com',
        'Juliana Almeida Ferreira',
        'aguardando_autorizacao',
        NULL,
        NOW()
    );

SET @auth_id = LAST_INSERT_ID();

-- 5) Atualizar a demanda para o estágio de autorização + log
UPDATE demands SET status = 'aguardando_autorizacao' WHERE id = @demand_id;

INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
VALUES (@demand_id, NULL, 'aguardando_autorizacao', NULL, 'Card enviado para autorização (teste, via SQL).');

-- Histórico da autorização
INSERT INTO authorization_request_history (authorization_request_id, action, proposal_value, notes, user_id)
VALUES (@auth_id, 'created', 0.00, 'Solicitação de autorização criada para teste (via SQL).', NULL);

-- Conferência (opcional):
-- SELECT id, demand_id, patient_id, professional_user_id, status FROM authorization_requests WHERE id = @auth_id;
-- SELECT id, title, status FROM demands WHERE id = @demand_id;
