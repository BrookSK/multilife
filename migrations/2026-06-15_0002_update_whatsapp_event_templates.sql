-- Migration: Atualizar templates de notificações WhatsApp para serem mais profissionais
-- Data: 2026-06-15

-- Atualizar template: Atendimento atribuído ao profissional
UPDATE whatsapp_events 
SET template_professional = 'Olá, {{profissional_nome}}! 👋\n\n✅ *Novo Atendimento Atribuído*\n\n📋 *Detalhes:*\n• Paciente: {{paciente_nome}}\n• Especialidade: {{especialidade}}\n• Serviço: {{servico}}\n• Sessões: {{sessoes}}\n• Frequência: {{frequencia}}\n• Valor/sessão: R$ {{valor_acordado}}\n• Data: {{data_atendimento}}\n• ID: #{{id_atendimento}}\n\n🔗 Acesse o sistema: {{link_atendimento}}\n\nPor favor, entre em contato com o paciente para agendar a primeira sessão.\n\nEquipe MultiLife Care'
WHERE system_event = 'attendance_assigned';

-- Atualizar template: Pré-admissão aprovada
UPDATE whatsapp_events 
SET template_professional = 'Olá, {{profissional_nome}}! 👋\n\n✅ *Pré-admissão Aprovada!*\n\n📋 *Detalhes:*\n• Paciente: {{paciente_nome}}\n• Especialidade: {{especialidade}}\n• ID: #{{id_preadmissao}}\n• Aprovado em: {{data_aprovacao}}\n\n🔗 Acesse: {{link_atendimento}}\n\nO atendimento está confirmado. Em breve você receberá mais detalhes.\n\nEquipe MultiLife Care',
    template_patient = 'Olá, {{paciente_nome}}! 👋\n\n✅ *Sua pré-admissão foi aprovada!*\n\n📋 *Detalhes:*\n• Profissional: {{profissional_nome}}\n• Especialidade: {{especialidade}}\n• ID: #{{id_preadmissao}}\n• Aprovado em: {{data_aprovacao}}\n\nEm breve o profissional entrará em contato para agendar os atendimentos.\n\nEquipe MultiLife Care',
    send_to_patient = 1
WHERE system_event = 'preadmission_approved';

-- Atualizar template: Profissional recebeu atendimento (se existir)
UPDATE whatsapp_events 
SET template_professional = 'Olá, {{profissional_nome}}! 👋\n\n📋 *Novo atendimento disponível*\n\n• Paciente: {{paciente_nome}}\n• Data: {{data_atendimento}}\n• ID: #{{id_atendimento}}\n\n🔗 Acesse: {{link_atendimento}}\n\nEquipe MultiLife Care'
WHERE system_event = 'professional_received_attendance';

-- Inserir template para substituição de profissional (se não existir)
INSERT IGNORE INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient) VALUES
('Profissional substituído', 'professional_substituted', 'active', 1, 1,
'Olá, {{profissional_nome}}! 👋\n\n🔄 *Substituição de Profissional*\n\nVocê foi designado como novo profissional para um atendimento.\n\n• Paciente: {{paciente_nome}}\n• ID: #{{id_atendimento}}\n\n🔗 Acesse: {{link_atendimento}}\n\nEquipe MultiLife Care',
'Olá, {{paciente_nome}}! 👋\n\nInformamos que houve uma alteração no profissional responsável pelo seu atendimento.\n\n• Novo profissional: {{profissional_nome}}\n\nEm breve o profissional entrará em contato.\n\nEquipe MultiLife Care');
