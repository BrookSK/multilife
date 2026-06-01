-- Migration: Validação de registros profissionais nos conselhos brasileiros
-- Data: 2026-06-01

-- Cache de consultas (evita múltiplas requisições ao mesmo portal)
CREATE TABLE IF NOT EXISTS council_validation_cache (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  council_abbr    VARCHAR(20)     NOT NULL COMMENT 'CRP, CRN, COREN, CREFITO, CRM, CRO, CREA, OAB',
  registry_number VARCHAR(40)     NOT NULL,
  council_state   CHAR(2)         NOT NULL,
  result_json     JSON            NOT NULL,
  expires_at      DATETIME        NOT NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_council_cache (council_abbr, registry_number, council_state),
  KEY idx_council_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log detalhado de cada consulta realizada
CREATE TABLE IF NOT EXISTS council_validation_logs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  council_abbr    VARCHAR(20)     NOT NULL,
  registry_number VARCHAR(40)     NOT NULL,
  council_state   CHAR(2)         NOT NULL,
  success         TINYINT(1)      NOT NULL DEFAULT 0,
  valid           TINYINT(1)      NOT NULL DEFAULT 0,
  name_found      VARCHAR(200)    NULL,
  status_found    VARCHAR(60)     NULL,
  source          VARCHAR(200)    NULL,
  error_message   TEXT            NULL,
  raw_result_json JSON            NULL,
  triggered_by_user_id INT UNSIGNED NULL,
  triggered_by_application_id BIGINT UNSIGNED NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cvl_abbr    (council_abbr),
  KEY idx_cvl_number  (registry_number),
  KEY idx_cvl_created (created_at),
  KEY idx_cvl_valid   (valid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coluna para armazenar resultado da última validação na candidatura
ALTER TABLE professional_applications
  ADD COLUMN IF NOT EXISTS council_validation_result JSON NULL
    COMMENT 'Resultado da última validação do registro no portal do conselho'
    AFTER council_state,
  ADD COLUMN IF NOT EXISTS council_validated_at DATETIME NULL
    COMMENT 'Data/hora da última validação do registro'
    AFTER council_validation_result,
  ADD COLUMN IF NOT EXISTS council_validation_status VARCHAR(30) NULL
    COMMENT 'Status resumido: VALID, INVALID, ERROR, PENDING'
    AFTER council_validated_at;

-- Permissão para validar registros
INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Validar registros profissionais' AS name, 'council_validation.run' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'council_validation.run')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'council_validation.run'
WHERE r.slug IN ('admin', 'ti')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
