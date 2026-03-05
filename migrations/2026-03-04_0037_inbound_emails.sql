-- Tabela para armazenar e-mails recebidos via IMAP (Módulo 3.1)
CREATE TABLE IF NOT EXISTS inbound_emails (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id VARCHAR(255) NOT NULL COMMENT 'Message-ID do e-mail (único)',
  from_address VARCHAR(255) NULL,
  to_address VARCHAR(255) NULL,
  subject VARCHAR(500) NULL,
  body_text TEXT NULL,
  body_html TEXT NULL,
  received_at DATETIME NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'received' COMMENT 'received, processed, error',
  processed_at DATETIME NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_inbound_emails_message_id (message_id),
  KEY idx_inbound_emails_status (status),
  KEY idx_inbound_emails_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
