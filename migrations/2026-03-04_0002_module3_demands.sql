CREATE TABLE IF NOT EXISTS demands (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  location_city VARCHAR(120) NULL,
  location_state CHAR(2) NULL,
  specialty VARCHAR(120) NULL,
  description TEXT NULL,
  origin_email VARCHAR(190) NULL,
  status ENUM('aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado') NOT NULL DEFAULT 'aguardando_captacao',
  assumed_by_user_id INT UNSIGNED NULL,
  assumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_demands_status (status),
  KEY idx_demands_created_at (created_at),
  KEY idx_demands_assumed_by (assumed_by_user_id),
  CONSTRAINT fk_demands_assumed_by FOREIGN KEY (assumed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demand_status_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  demand_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  user_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_demand_status_logs_demand_id (demand_id),
  KEY idx_demand_status_logs_created_at (created_at),
  CONSTRAINT fk_demand_status_logs_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE,
  CONSTRAINT fk_demand_status_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  specialty VARCHAR(120) NULL,
  city VARCHAR(120) NULL,
  state CHAR(2) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_whatsapp_groups_status (status),
  KEY idx_whatsapp_groups_filters (specialty, city, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demand_dispatch_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  demand_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NULL,
  dispatched_by_user_id INT UNSIGNED NULL,
  message TEXT NULL,
  dispatch_status ENUM('queued','sent','error') NOT NULL DEFAULT 'sent',
  error_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_demand_dispatch_logs_demand_id (demand_id),
  KEY idx_demand_dispatch_logs_group_id (group_id),
  KEY idx_demand_dispatch_logs_created_at (created_at),
  CONSTRAINT fk_demand_dispatch_logs_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE,
  CONSTRAINT fk_demand_dispatch_logs_group FOREIGN KEY (group_id) REFERENCES whatsapp_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_demand_dispatch_logs_user FOREIGN KEY (dispatched_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar captação (demandas)' AS name, 'demands.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'demands.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar grupos WhatsApp' AS name, 'whatsapp_groups.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'whatsapp_groups.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('demands.manage','whatsapp_groups.manage')
WHERE r.slug = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('demands.manage')
WHERE r.slug = 'captador'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
