-- Migration: endereço, contatos (paciente + família) e agendamento na mensagem
-- "Pré-admissão aprovada" enviada ao PROFISSIONAL (WhatsApp + cópia por e-mail).
-- Data: 2026-11-17
--
-- O sistema também faz isto sozinho na primeira aprovação de pré-admissão
-- (preadmission_template_ensure_patient_block), então rodar este SQL é opcional.
-- Só altera textos que ainda NÃO têm {{paciente_endereco}} (não sobrescreve
-- personalizações) e marca a flag para o código não repetir.

-- 1) Texto padrão: insere o bloco logo depois da linha "Aprovado em".
UPDATE whatsapp_events
SET template_professional = REPLACE(
    template_professional,
    '• Aprovado em: {{data_aprovacao}}',
    '• Aprovado em: {{data_aprovacao}}\n\n📍 *Dados do paciente:*\n• Endereço: {{paciente_endereco}}\n• Contatos: {{paciente_contatos}}\n• Agendamento: {{agendamento}}'
)
WHERE system_event = 'preadmission_approved'
  AND template_professional IS NOT NULL
  AND template_professional NOT LIKE '%{{paciente_endereco}}%'
  AND template_professional LIKE '%• Aprovado em: {{data_aprovacao}}%';

-- 2) Texto personalizado sem aquela linha: acrescenta o bloco no final.
UPDATE whatsapp_events
SET template_professional = CONCAT(
    template_professional,
    '\n\n📍 *Dados do paciente:*\n• Endereço: {{paciente_endereco}}\n• Contatos: {{paciente_contatos}}\n• Agendamento: {{agendamento}}'
)
WHERE system_event = 'preadmission_approved'
  AND template_professional IS NOT NULL
  AND template_professional NOT LIKE '%{{paciente_endereco}}%';

-- 3) Marca como aplicado (o código não repete a inclusão).
INSERT INTO admin_settings (setting_key, setting_value)
VALUES ('whatsapp.preadmission_patient_block', '1')
ON DUPLICATE KEY UPDATE setting_value = '1';

-- Conferência:
-- SELECT id, status, template_professional FROM whatsapp_events WHERE system_event = 'preadmission_approved';
