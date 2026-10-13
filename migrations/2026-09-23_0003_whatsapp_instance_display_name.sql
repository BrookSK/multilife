-- Apelido amigável para cada instância de WhatsApp, exibido nos filtros do chat
-- (ex.: "ml-guethie" -> "Atendimento Guethie"). Só rótulo, não afeta a Evolution API.
ALTER TABLE whatsapp_instances
    ADD COLUMN IF NOT EXISTS display_name VARCHAR(120) NULL COMMENT 'Apelido amigável exibido nos filtros/telas' AFTER instance_name;
