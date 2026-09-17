-- ============================================================================
-- Criar um ATENDIMENTO ADMITIDO com sessões, pronto para testar a
-- SUBSTITUIÇÃO DE PROFISSIONAL direto no Monitoramento (sem passar por todo o fluxo).
--
-- Pré-requisitos:
--   - Rodar antes: seed_card_captacao_teste.sql (cria a demanda do Roberto)
--     e seed_card_para_autorizacao.sql (cria o paciente Roberto).
--     Se já rodou, pode rodar este direto.
--
-- O Monitoramento mostra sessões de atendimentos com status
-- IN ('admitted','awaiting_documents','awaiting_financial_approval') e admitted_at != NULL.
-- Este script cria exatamente isso: um patient_assignment 'admitted' + 6 sessões futuras.
-- ============================================================================

-- Demanda do Roberto (criada nos seeds anteriores)
SET @demand_id = (
    SELECT id FROM demands
    WHERE title = 'Atendimento multidisciplinar domiciliar para Roberto'
    ORDER BY id DESC LIMIT 1
);

-- Paciente Roberto (cria se ainda não existir)
INSERT INTO patients (full_name, phone_primary, whatsapp, address_street, address_number, address_neighborhood, address_city, address_state, admin_status, registration_date)
SELECT 'Roberto Almeida Ferreira', '12997415528', '12997415528', 'Rua das Flores', '123', 'Centro', 'São Paulo', 'SP', 'Ativo', CURDATE()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM patients WHERE full_name = 'Roberto Almeida Ferreira' AND deleted_at IS NULL);

SET @patient_id = (
    SELECT id FROM patients
    WHERE full_name = 'Roberto Almeida Ferreira' AND deleted_at IS NULL
    ORDER BY id DESC LIMIT 1
);

-- Profissional ATUAL do atendimento (o que será substituído): um profissional ativo.
-- Preferência por Fisioterapia; senão qualquer profissional ativo.
SET @prof_id = (
    SELECT u.id
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE r.slug = 'profissional' AND u.status = 'active'
    ORDER BY (CASE WHEN u.specialty LIKE '%isio%' THEN 0 ELSE 1 END), u.id ASC
    LIMIT 1
);

-- JID do profissional (telefone@s.whatsapp.net), quando houver telefone.
SET @prof_jid = (
    SELECT CASE
             WHEN u.phone IS NULL OR u.phone = '' THEN NULL
             ELSE CONCAT(REPLACE(REPLACE(REPLACE(REPLACE(u.phone,' ',''),'-',''),'(',''),')',''), '@s.whatsapp.net')
           END
    FROM users u WHERE u.id = @prof_id
);

-- 1) Criar o atendimento já ADMITIDO (frequência 3x/semana, valor acordado R$ 150 por sessão)
INSERT INTO patient_assignments
    (demand_id, patient_id, professional_user_id, professional_remote_jid, assigned_by_user_id,
     specialty, service_type, session_quantity, session_frequency,
     payment_value, agreed_value, authorized_value, notes, status, admitted_at, created_at)
VALUES
    (@demand_id, @patient_id, @prof_id, @prof_jid, @prof_id,
     'Fisioterapia Domiciliar', 'Fisioterapia Domiciliar', 6, '3x_semana',
     150.00, 150.00, 200.00, 'Atendimento de teste para substituição de profissional.', 'admitted', NOW(), NOW());

SET @assignment_id = LAST_INSERT_ID();

-- 2) Criar 6 sessões FUTURAS (pendentes), a partir de amanhã, em dias alternados.
INSERT INTO billing_document_requirements
    (assignment_id, patient_id, professional_user_id, session_number, session_date, status, created_at)
VALUES
    (@assignment_id, @patient_id, @prof_id, 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'pending', NOW()),
    (@assignment_id, @patient_id, @prof_id, 2, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'pending', NOW()),
    (@assignment_id, @patient_id, @prof_id, 3, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'pending', NOW()),
    (@assignment_id, @patient_id, @prof_id, 4, DATE_ADD(CURDATE(), INTERVAL 8 DAY), 'pending', NOW()),
    (@assignment_id, @patient_id, @prof_id, 5, DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'pending', NOW()),
    (@assignment_id, @patient_id, @prof_id, 6, DATE_ADD(CURDATE(), INTERVAL 12 DAY), 'pending', NOW());

-- Conferência (opcional):
-- SELECT @assignment_id AS assignment_id, @patient_id AS patient_id, @prof_id AS prof_atual_id;
-- SELECT id, session_number, session_date, professional_user_id, status FROM billing_document_requirements WHERE assignment_id = @assignment_id;
