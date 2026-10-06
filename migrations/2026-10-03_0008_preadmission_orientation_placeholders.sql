-- Migration: incluir a orientação da operadora e a tabela de dias no template do
-- profissional no evento 'preadmission_approved'.
-- Data: 2026-10-03
--
-- Os placeholders {{orientacao_operadora}} e {{tabela_dias}} são preenchidos no
-- envio pela pré-admissão (variação escolhida + tabela de padronização de dias).
-- Só adicionamos se o template ainda NÃO contiver {{orientacao_operadora}} (para
-- não sobrescrever customizações feitas no painel).

UPDATE whatsapp_events
SET template_professional = CONCAT(
    template_professional,
    '\n\n{{orientacao_operadora}}\n\n{{tabela_dias}}'
)
WHERE system_event = 'preadmission_approved'
  AND template_professional IS NOT NULL
  AND template_professional NOT LIKE '%{{orientacao_operadora}}%';

-- Observação: o e-mail ao profissional é ESPELHADO a partir deste mesmo texto do
-- WhatsApp (WhatsAppEventDispatcher::sendEmailNotification), portanto não é
-- necessário editar a tabela email_events para esta funcionalidade.
