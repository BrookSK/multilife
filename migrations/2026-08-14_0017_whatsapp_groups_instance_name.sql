-- ============================================================================
-- Vincular grupos WhatsApp à instância (número) que os criou
-- ============================================================================
-- Problema:
--   whatsapp_groups é global e não guarda em qual instância/número o grupo foi
--   criado. Na captação, o sistema reusava um grupo existente independente da
--   instância do captador — então o grupo podia "existir" no banco mas não no
--   WhatsApp de quem prospecta (ex.: Grace), pois pertencia a outro número.
--
-- Solução:
--   Adiciona instance_name em whatsapp_groups. A busca de grupo existente passa
--   a considerar a instância do captador; a criação registra a instância usada.
-- Data: 2026-08-14
-- ============================================================================

ALTER TABLE whatsapp_groups
ADD COLUMN IF NOT EXISTS instance_name VARCHAR(100) NULL COMMENT 'Instância Evolution que criou/possui este grupo' AFTER evolution_group_jid;

ALTER TABLE whatsapp_groups
ADD INDEX IF NOT EXISTS idx_whatsapp_groups_instance (instance_name);
