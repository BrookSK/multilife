-- =====================================================================
-- Templates OFICIAIS das mensagens enviadas ao PROFISSIONAL
-- (fornecidos pela operação — Maria Jullia / equipe de prospecção)
-- =====================================================================
-- Cobre:
--   1) Novo paciente autorizado (dados do paciente)  -> attendance_assigned
--   2) Hospitalização (suspender atendimentos)        -> attendance_hospitalization
--   3) Encerramento por término do período autorizado -> attendance_authorized_period_ended
--   4) Retomada dos atendimentos                       -> attendance_resumed
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Motivos de encerramento usados pela finalização de atendimento
--    (slug estável para o código identificar qual evento disparar)
-- ---------------------------------------------------------------------
INSERT INTO treatment_end_reasons (name, slug, is_active, is_system) VALUES
  ('Hospitalização', 'hospitalizacao', 1, 1),
  ('Término do período autorizado pela operadora', 'termino_periodo_autorizado', 1, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1;


-- ---------------------------------------------------------------------
-- 2) Evento: NOVO PACIENTE AUTORIZADO (atribuição) — atualiza o texto
--    oficial no evento já existente attendance_assigned.
-- ---------------------------------------------------------------------
UPDATE whatsapp_events
SET status = 'active',
    send_to_professional = 1,
    template_professional =
'Conforme acordado com equipe de prospecção segue *novo paciente autorizado* ✅:

*DADOS DO PACIENTE:*

*Beneficiário:* {{paciente_nome}}

Endereço: {{paciente_endereco}}

Contatos: {{paciente_contatos}}

*Frequência de atendimento:* {{frequencia}}

*Agendamento:* {{agendamento}}

*Valor acordado:* {{valor_acordado}}

Para qualquer dúvida, estarei aqui! *Peço para que entre em contato com a família/responsável para confirmar data e horário alinhado*'
WHERE system_event = 'attendance_assigned';


-- ---------------------------------------------------------------------
-- 3) Evento: HOSPITALIZAÇÃO (suspender atendimentos)
-- ---------------------------------------------------------------------
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient)
VALUES (
  'Atendimento suspenso por hospitalização',
  'attendance_hospitalization',
  'active', 1, 0,
'Prezado(a), como vai? *{{paciente_nome}}*,

O(a) beneficiário(a) evoluiu com *hospitalização em* {{data_hospitalizacao}}, suspender atendimentos e aguardar nosso contato novamente. Qualquer dúvida estamos a disposição.

*Por gentileza, informe-nos a quantidade de atendimento realizado até o momento.*

Atenciosamente,
{{atendente_nome}}',
  NULL
)
ON DUPLICATE KEY UPDATE
  template_professional = VALUES(template_professional),
  send_to_professional = 1,
  status = 'active';


-- ---------------------------------------------------------------------
-- 4) Evento: ENCERRAMENTO POR TÉRMINO DO PERÍODO AUTORIZADO
-- ---------------------------------------------------------------------
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient)
VALUES (
  'Encerramento por término do período autorizado',
  'attendance_authorized_period_ended',
  'active', 1, 0,
'Prezado(a), como vai? *{{paciente_nome}}*,

Sinalizamos a partir da data de hoje o encerramento dos atendimentos do beneficiário(a) supracitado.

*Atendimentos a partir desta data não serão faturados.*

Agradecemos seus atendimentos até a data de hoje e sinalizaremos quando tivermos outras demandas.

📌 Obs. Enviar todos os documentos pertinentes aos atendimentos para a Tânia

Atenciosamente,
{{atendente_nome}}',
  NULL
)
ON DUPLICATE KEY UPDATE
  template_professional = VALUES(template_professional),
  send_to_professional = 1,
  status = 'active';


-- ---------------------------------------------------------------------
-- 5) Evento: RETOMADA DOS ATENDIMENTOS
-- ---------------------------------------------------------------------
INSERT INTO whatsapp_events (name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient)
VALUES (
  'Retomada dos atendimentos autorizada',
  'attendance_resumed',
  'active', 1, 0,
'Boa tarde, tudo bem? *{{paciente_nome}}*,

Autorizado a retomada dos atendimentos a partir de {{data_retomada}}

Atenciosamente,
{{atendente_nome}}',
  NULL
)
ON DUPLICATE KEY UPDATE
  template_professional = VALUES(template_professional),
  send_to_professional = 1,
  status = 'active';


-- ---------------------------------------------------------------------
-- 6) Assinatura padrão das mensagens ({{atendente_nome}})
-- ---------------------------------------------------------------------
INSERT INTO admin_settings (setting_key, setting_value)
VALUES ('app.attendant_name', 'Maria Jullia')
ON DUPLICATE KEY UPDATE setting_value = 'Maria Jullia';


SELECT 'Templates oficiais aplicados.' AS resultado;
