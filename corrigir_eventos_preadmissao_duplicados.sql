-- ============================================================================
-- Corrige DUPLICIDADE de eventos "preadmission_approved".
-- Há vários eventos ativos com o mesmo system_event; o disparador usa apenas UM
-- (SELECT ... WHERE system_event=? AND status='active' LIMIT 1), então precisamos
-- garantir que o ÚNICO ativo seja o que contém {{link_cadastro}}.
--
-- SEGURO: mexe apenas na tabela whatsapp_events (configuração de mensagens).
-- ============================================================================

-- PASSO 1 (DIAGNÓSTICO): veja todos os eventos preadmission_approved e seus templates.
-- Rode SÓ este SELECT primeiro para conferir qual tem o {{link_cadastro}}.
SELECT id, name, status, send_to_professional, send_to_patient,
       (template_professional LIKE '%{{link_cadastro}}%') AS tem_link_cadastro,
       template_professional
FROM whatsapp_events
WHERE system_event = 'preadmission_approved'
ORDER BY id ASC;

-- ----------------------------------------------------------------------------
-- PASSO 2 (CORREÇÃO): descomente o bloco abaixo e rode para deixar ATIVO apenas
-- o evento que tem o {{link_cadastro}}, desativando os demais.
-- ----------------------------------------------------------------------------
-- -- Escolhe o menor id que JÁ contém o {{link_cadastro}} para manter ativo.
-- SET @keep_id := (
--     SELECT id FROM whatsapp_events
--     WHERE system_event = 'preadmission_approved'
--       AND template_professional LIKE '%{{link_cadastro}}%'
--     ORDER BY id ASC LIMIT 1
-- );
--
-- -- Se nenhum tinha o link, garante o link no de menor id e o elege como keep.
-- SET @keep_id := IFNULL(@keep_id, (
--     SELECT id FROM whatsapp_events WHERE system_event = 'preadmission_approved' ORDER BY id ASC LIMIT 1
-- ));
--
-- UPDATE whatsapp_events
-- SET template_professional = CONCAT(
--         'Olá {{profissional_nome}}! 🎉\n\n',
--         'Seu atendimento foi aprovado na pré-admissão.\n\n',
--         'Para finalizarmos o seu cadastro (dados pessoais, bancários e PIX), ',
--         'complete suas informações neste link:\n\n{{link_cadastro}}\n\n',
--         'É rápido e não precisa de senha. Obrigado!\n\nEquipe MultiLife Care'
--     ),
--     send_to_professional = 1,
--     status = 'active',
--     updated_at = CURRENT_TIMESTAMP
-- WHERE id = @keep_id;
--
-- -- Desativa todos os outros eventos preadmission_approved (evita ambiguidade).
-- UPDATE whatsapp_events
-- SET status = 'inactive', updated_at = CURRENT_TIMESTAMP
-- WHERE system_event = 'preadmission_approved' AND id <> @keep_id;
--
-- -- Conferência final: deve restar apenas 1 ativo, com o link.
-- SELECT id, name, status,
--        (template_professional LIKE '%{{link_cadastro}}%') AS tem_link_cadastro
-- FROM whatsapp_events
-- WHERE system_event = 'preadmission_approved'
-- ORDER BY id ASC;
