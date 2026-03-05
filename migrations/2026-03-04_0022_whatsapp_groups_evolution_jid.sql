ALTER TABLE whatsapp_groups
  ADD COLUMN evolution_group_jid VARCHAR(120) NULL AFTER name,
  ADD COLUMN contacts_count INT UNSIGNED NULL AFTER evolution_group_jid;

CREATE INDEX idx_whatsapp_groups_evolution_group_jid ON whatsapp_groups (evolution_group_jid);
