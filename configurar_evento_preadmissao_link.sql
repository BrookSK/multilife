-- ============================================================================
-- Configura o evento WhatsApp "preadmission_approved" para enviar ao PROFISSIONAL
-- a mensagem com o LINK de atualização cadastral ({{link_cadastro}}).
--
-- SEGURO: mexe apenas na tabela whatsapp_events (configuração de mensagens).
--   NÃO altera login, senha, conexão nem estrutura crítica.
--
-- É idempotente: se o evento já existir, atualiza; senão, cria.
-- ============================================================================

SET @tpl := CONCAT(
    'Olá {{profissional_nome}}! 🎉\n\n',
    'Seu atendimento foi aprovado na pré-admissão.\n\n',
    'Para finalizarmos o seu cadastro (dados pessoais, bancários e PIX), ',
    'complete suas informações neste link:\n\n',
    '{{link_cadastro}}\n\n',
    'É rápido e não precisa de senha. Obrigado!\n\nEquipe MultiLife Care'
);

-- Existe evento preadmission_approved?
SET @evt_id := (SELECT id FROM whatsapp_events WHERE system_event = 'preadmission_approved' ORDER BY id ASC LIMIT 1);

-- Atualiza se já existir
UPDATE whatsapp_events
SET name = 'Pré-admissão aprovada',
    status = 'active',
    send_to_professional = 1,
    template_professional = @tpl,
    updated_at = CURRENT_TIMESTAMP
WHERE id = @evt_id;

-- Cria se não existir
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient)
SELECT 'Pré-admissão aprovada', 'preadmission_approved', 'active', 1, 0, @tpl, NULL
FROM DUAL
WHERE @evt_id IS NULL;

-- Conferência: mostra o evento configurado
SELECT id, name, system_event, status, send_to_professional, send_to_patient, template_professional
FROM whatsapp_events
WHERE system_event = 'preadmission_approved';
