CREATE TABLE IF NOT EXISTS integration_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(60) NOT NULL,
  action VARCHAR(80) NOT NULL,
  status ENUM('success','error') NOT NULL,
  http_status INT NULL,
  request_payload JSON NULL,
  response_payload JSON NULL,
  error_message VARCHAR(255) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_integration_logs_provider (provider),
  KEY idx_integration_logs_action (action),
  KEY idx_integration_logs_status (status),
  KEY idx_integration_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(60) NOT NULL,
  action VARCHAR(80) NOT NULL,
  status ENUM('pending','running','success','error','dead') NOT NULL DEFAULT 'pending',
  payload JSON NULL,
  last_error VARCHAR(255) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
  next_run_at DATETIME NULL,
  last_run_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_integration_jobs_status (status),
  KEY idx_integration_jobs_next_run_at (next_run_at),
  KEY idx_integration_jobs_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Visualizar logs técnicos' AS name, 'tech_logs.view' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'tech_logs.view')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar jobs de integração' AS name, 'integration_jobs.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'integration_jobs.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('tech_logs.view','integration_jobs.manage')
WHERE r.slug IN ('admin','ti')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
