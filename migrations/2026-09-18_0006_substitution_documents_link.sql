-- Migration: Incluir o link de DOCUMENTOS DA OPERADORA na notificação de
-- substituição de profissional (novo profissional).
-- Data: 2026-09-18
--
-- Contexto: ao substituir o profissional de um atendimento, o NOVO profissional
-- recebe a notificação, mas o template só trazia o link de atualização cadastral
-- ({{link_atendimento}}), não o link dos documentos da operadora ({{link_documentos}}).
-- O endpoint monitoramento_substituicao_post.php agora envia 'documents_link';
-- aqui adicionamos o placeholder ao template do profissional.

UPDATE whatsapp_events
SET template_professional = 'Olá, {{profissional_nome}}! 👋\n\n🔄 *Substituição de Profissional*\n\nVocê foi designado como novo profissional para um atendimento.\n\n• Paciente: {{paciente_nome}}\n• ID: #{{id_atendimento}}\n• Especialidade: {{especialidade}}\n\n📄 *Documentos da operadora:*\n{{link_documentos}}\n\n🔗 Atualizar cadastro: {{link_atendimento}}\n\nEquipe MultiLife Care'
WHERE system_event = 'professional_substituted';
