-- Migration: anexos e imagens dos e-mails de captação (lidos pela IA ao criar o card).
-- Data: 2026-11-17
--
-- Opcional: o sistema cria a tabela e a coluna sozinho (inbound_attachments_ensure_schema)
-- na primeira vez que o poll de e-mails ou a extração rodarem.

CREATE TABLE IF NOT EXISTS inbound_email_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  inbound_email_id INT UNSIGNED NOT NULL,
  part_index VARCHAR(40) NOT NULL COMMENT 'Parte MIME (ex.: 2, 1.2) ou html-N / docx-imgN',
  source VARCHAR(20) NOT NULL DEFAULT 'imap' COMMENT 'imap | html (colada no corpo) | documento (dentro do docx/odt)',
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(150) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  content_id VARCHAR(255) NULL,
  is_inline TINYINT(1) NOT NULL DEFAULT 0,
  sha1 CHAR(40) NULL,
  file_path VARCHAR(500) NULL COMMENT 'Arquivo no servidor (fora da pasta pública quando possível)',
  extraction_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending | done | empty | unsupported | skipped | error',
  extracted_text MEDIUMTEXT NULL,
  extraction_error VARCHAR(500) NULL,
  extracted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_iea_part (inbound_email_id, part_index),
  KEY idx_iea_email (inbound_email_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE inbound_emails
  ADD COLUMN IF NOT EXISTS attachments_fetched_at DATETIME NULL COMMENT 'Quando os anexos foram buscados no IMAP';
