-- Migration: Neutralizar as notificações de substituição de profissional.
-- Data: 2026-10-03
--
-- Objetivo (Ajuste 1): nem o profissional substituído nem o novo profissional
-- podem perceber que se trata de uma substituição. Portanto removemos qualquer
-- termo como "Substituição de Profissional", "você foi substituído" ou
-- "novo profissional" das mensagens enviadas aos profissionais (WhatsApp e e-mail,
-- que espelham o mesmo texto).
--
-- Importante: só sobrescrevemos quando o template ainda está no texto DEFAULT
-- conhecido (versões anteriores desta base), para não apagar customizações que a
-- equipe tenha feito manualmente no painel. A comparação é feita pelo marcador
-- "Substituição de Profissional" (presente em todos os defaults antigos).

-- 1) NOVO profissional (evento professional_substituted): vira um aviso neutro de
--    designação de atendimento, sem citar substituição.
UPDATE whatsapp_events
SET template_professional = 'Olá, {{profissional_nome}}! 👋\n\n✅ *Novo atendimento designado*\n\nVocê foi designado para um atendimento domiciliar.\n\n• Paciente: {{paciente_nome}}\n• ID: #{{id_atendimento}}\n• Especialidade: {{especialidade}}\n\n📄 *Documentos da operadora:*\n{{link_documentos}}\n\n🔗 Atualizar cadastro: {{link_atendimento}}\n\nEquipe MultiLife Care'
WHERE system_event = 'professional_substituted'
  AND template_professional LIKE '%Substituição de Profissional%';

-- Template do PACIENTE do mesmo evento: informa a troca do profissional sem usar
-- o termo técnico "substituição".
UPDATE whatsapp_events
SET template_patient = 'Olá, {{paciente_nome}}! 👋\n\nInformamos que o profissional responsável pelo seu atendimento foi atualizado.\n\n• Profissional: {{profissional_nome}}\n\nEm breve o profissional entrará em contato.\n\nEquipe MultiLife Care'
WHERE system_event = 'professional_substituted'
  AND template_patient LIKE '%alteração no profissional%';

-- 2) Profissional ANTERIOR (evento professional_removed): vira um aviso neutro de
--    encerramento do atendimento, sem dizer que foi substituído.
UPDATE whatsapp_events
SET template_professional = 'Olá, {{profissional_nome}}!\n\nInformamos que o atendimento abaixo foi encerrado e não está mais sob sua responsabilidade:\n\n• Paciente: {{paciente_nome}}\n• Atendimento: #{{id_atendimento}}\n• Especialidade: {{especialidade}}\n\nAgradecemos o seu trabalho e dedicação.\n\nEquipe MultiLife Care'
WHERE system_event = 'professional_removed'
  AND (template_professional LIKE '%foi substituído%' OR template_professional LIKE '%Substituição de Profissional%');
