CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  module VARCHAR(80) NOT NULL,
  record_id VARCHAR(80) NULL,
  old_data JSON NULL,
  new_data JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user_id (user_id),
  KEY idx_audit_module (module),
  KEY idx_audit_created_at (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'Admin' AS name, 'admin' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'admin')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'Financeiro' AS name, 'financeiro' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'financeiro')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'Captador / Admissão' AS name, 'captador' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'captador')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'TI' AS name, 'ti' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'ti')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'Profissional' AS name, 'profissional' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'profissional')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar usuários' AS name, 'users.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'users.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar perfis' AS name, 'roles.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'roles.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar permissões' AS name, 'permissions.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'permissions.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('users.manage','roles.manage','permissions.manage')
WHERE r.slug = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
