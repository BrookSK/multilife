CREATE TABLE IF NOT EXISTS pending_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(60) NOT NULL,
  status ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
  title VARCHAR(200) NOT NULL,
  detail TEXT NULL,
  related_table VARCHAR(60) NULL,
  related_id BIGINT UNSIGNED NULL,
  assigned_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_pending_status (status),
  KEY idx_pending_type (type),
  KEY idx_pending_assigned_user_id (assigned_user_id),
  KEY idx_pending_created_at (created_at),
  KEY idx_pending_related (related_table, related_id),
  CONSTRAINT fk_pending_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
