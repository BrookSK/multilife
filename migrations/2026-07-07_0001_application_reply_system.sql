-- ============================================
-- Sistema de respostas de candidatos (complemento)
-- ============================================

-- Token de acesso público para o candidato responder
ALTER TABLE professional_applications
  ADD COLUMN IF NOT EXISTS reply_token VARCHAR(64) NULL AFTER admin_note,
  ADD COLUMN IF NOT EXISTS reply_token_created_at DATETIME NULL AFTER reply_token;

CREATE UNIQUE INDEX IF NOT EXISTS uk_prof_apps_reply_token ON professional_applications(reply_token);

-- Tabela de respostas do candidato
CREATE TABLE IF NOT EXISTS professional_application_replies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id BIGINT UNSIGNED NOT NULL,
  reply_type ENUM('text','file','image') NOT NULL DEFAULT 'text',
  content TEXT NULL COMMENT 'Texto da resposta',
  file_path VARCHAR(500) NULL COMMENT 'Caminho do arquivo/imagem',
  file_name VARCHAR(255) NULL COMMENT 'Nome original do arquivo',
  file_size INT UNSIGNED NULL COMMENT 'Tamanho em bytes',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_app_replies_application (application_id),
  CONSTRAINT fk_app_replies_application FOREIGN KEY (application_id) REFERENCES professional_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
