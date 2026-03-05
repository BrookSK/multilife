CREATE TABLE IF NOT EXISTS whatsapp_group_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  demand_id BIGINT UNSIGNED NULL,
  group_id BIGINT UNSIGNED NULL,

  group_jid VARCHAR(120) NOT NULL,
  external_message_id VARCHAR(190) NOT NULL,

  sender_phone VARCHAR(30) NOT NULL,
  body TEXT NOT NULL,

  received_at DATETIME NULL,
  raw_json JSON NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uk_wgm_external (group_jid, external_message_id),
  KEY idx_wgm_demand_id (demand_id),
  KEY idx_wgm_group_id (group_id),
  KEY idx_wgm_sender_phone (sender_phone),
  KEY idx_wgm_created_at (created_at),
  CONSTRAINT fk_wgm_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE SET NULL,
  CONSTRAINT fk_wgm_group FOREIGN KEY (group_id) REFERENCES whatsapp_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
