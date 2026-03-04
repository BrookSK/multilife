CREATE TABLE IF NOT EXISTS chat_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  external_phone VARCHAR(30) NOT NULL,
  contact_kind ENUM('unknown','professional','patient') NOT NULL DEFAULT 'unknown',
  contact_ref_id BIGINT UNSIGNED NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  assigned_user_id INT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  last_message_preview VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_conversations_status (status),
  KEY idx_chat_conversations_last_message_at (last_message_at),
  KEY idx_chat_conversations_assigned_user_id (assigned_user_id),
  UNIQUE KEY uk_chat_conversations_phone_open (external_phone, status),
  CONSTRAINT fk_chat_conversations_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('in','out') NOT NULL,
  body TEXT NOT NULL,
  sent_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_messages_conversation_id (conversation_id),
  KEY idx_chat_messages_created_at (created_at),
  CONSTRAINT fk_chat_messages_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_messages_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('transfer','finalize','reopen','assign') NOT NULL,
  from_user_id INT UNSIGNED NULL,
  to_user_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_events_conversation_id (conversation_id),
  KEY idx_chat_events_created_at (created_at),
  CONSTRAINT fk_chat_events_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_events_from_user FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_chat_events_to_user FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Acessar chat interno' AS name, 'chat.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'chat.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('chat.manage')
WHERE r.slug IN ('admin','captador')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
