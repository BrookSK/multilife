-- Migration: Credenciais de sistema externo do profissional na pré-admissão
-- Data: 2026-09-18
--
-- Contexto (reunião 15/09): a pré-admissão passa a registrar login e senha que o
-- profissional usará em SISTEMAS EXTERNOS (não é o login do MultiLife). Essas
-- credenciais devem ser enviadas ao PROFISSIONAL por WhatsApp e e-mail na aprovação.
--
-- Aqui garantimos:
--   1) As colunas em patient_assignments (também criadas em runtime, de forma idempotente,
--      por pre_admissao_approve.php — este DDL é a versão versionada).
--   2) O evento WhatsApp 'preadmission_approved' passa a enviar ao PROFISSIONAL
--      (send_to_professional = 1) e o template do profissional inclui as credenciais.
--      IMPORTANTE: as credenciais entram SOMENTE no template do profissional, NUNCA
--      no do paciente (evita vazamento da senha do profissional para o paciente).
--      Os placeholders {{login_externo}}/{{senha_externa}} vazios são removidos
--      automaticamente pelo dispatcher quando não há credenciais preenchidas.

-- 1) Colunas de credenciais externas (texto livre; sem hashing pois precisam ser repassadas em claro).
ALTER TABLE patient_assignments ADD COLUMN external_login VARCHAR(190) NULL;
ALTER TABLE patient_assignments ADD COLUMN external_password VARCHAR(190) NULL;

-- 2) Habilitar envio ao profissional e incluir as credenciais no template do profissional.
UPDATE whatsapp_events
SET send_to_professional = 1,
    template_professional = 'Olá, {{profissional_nome}}! 👋\n\n✅ *Pré-admissão Aprovada!*\n\n📋 *Detalhes:*\n• Paciente: {{paciente_nome}}\n• Especialidade: {{especialidade}}\n• ID: #{{id_preadmissao}}\n• Aprovado em: {{data_aprovacao}}\n\n🔐 *Credenciais de acesso (sistema externo):*\n• Login: {{login_externo}}\n• Senha: {{senha_externa}}\n\n🔗 Acesse: {{link_atendimento}}\n\nO atendimento está confirmado. Em breve você receberá mais detalhes.\n\nEquipe MultiLife Care'
WHERE system_event = 'preadmission_approved';
