CREATE TABLE IF NOT EXISTS patient_access_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  action VARCHAR(60) NOT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patient_access_logs_patient_id (patient_id),
  KEY idx_patient_access_logs_user_id (user_id),
  KEY idx_patient_access_logs_created_at (created_at),
  CONSTRAINT fk_patient_access_logs_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_patient_access_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(60) NOT NULL,
  status ENUM('running','success','error') NOT NULL DEFAULT 'running',
  started_by_user_id INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  output_path VARCHAR(255) NULL,
  error_message VARCHAR(255) NULL,
  meta_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_backup_runs_kind (kind),
  KEY idx_backup_runs_status (status),
  KEY idx_backup_runs_started_at (started_at),
  CONSTRAINT fk_backup_runs_user FOREIGN KEY (started_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Visualizar acessos a prontuário' AS name, 'patient_access_logs.view' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'patient_access_logs.view')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar backups' AS name, 'backups.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'backups.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('patient_access_logs.view','backups.manage')
WHERE r.slug IN ('admin','ti')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
