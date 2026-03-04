CREATE TABLE IF NOT EXISTS admin_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  updated_by_user_id INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_admin_settings_key (setting_key),
  CONSTRAINT fk_admin_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  role_title VARCHAR(120) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hr_employees_status (status),
  KEY idx_hr_employees_full_name (full_name),
  KEY idx_hr_employees_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (
  SELECT 'docs.reminder_days_before_due' AS setting_key, '2' AS setting_value
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'docs.reminder_days_before_due')
LIMIT 1;

INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (
  SELECT 'finance.repasse_cycle_days' AS setting_key, '15' AS setting_value
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'finance.repasse_cycle_days')
LIMIT 1;

INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (
  SELECT 'demands.assume_timeout_hours' AS setting_key, '4' AS setting_value
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'demands.assume_timeout_hours')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Dashboard administrativo' AS name, 'admin.dashboard' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'admin.dashboard')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar configurações do Admin' AS name, 'admin.settings.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'admin.settings.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar RH (funcionários internos)' AS name, 'hr.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'hr.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('admin.dashboard','admin.settings.manage','hr.manage')
WHERE r.slug IN ('admin')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
