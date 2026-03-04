CREATE TABLE IF NOT EXISTS professional_applications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status ENUM('pending','approved','rejected','need_more_info') NOT NULL DEFAULT 'pending',

  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) NOT NULL,

  cities_of_operation TEXT NULL,
  marital_status VARCHAR(40) NULL,
  sex VARCHAR(20) NULL,
  religion VARCHAR(60) NULL,
  birthplace VARCHAR(120) NULL,
  nationality VARCHAR(80) NULL,
  education_level VARCHAR(80) NULL,

  address_street VARCHAR(160) NULL,
  address_number VARCHAR(20) NULL,
  address_complement VARCHAR(80) NULL,
  address_neighborhood VARCHAR(80) NULL,
  address_city VARCHAR(120) NULL,
  address_state CHAR(2) NULL,
  address_zip VARCHAR(12) NULL,

  rg VARCHAR(30) NULL,
  council_abbr VARCHAR(20) NULL,
  council_number VARCHAR(30) NULL,
  council_state CHAR(2) NULL,

  bank_name VARCHAR(80) NULL,
  bank_agency VARCHAR(20) NULL,
  bank_account VARCHAR(30) NULL,
  bank_account_type VARCHAR(20) NULL,
  bank_account_holder VARCHAR(160) NULL,
  bank_account_holder_cpf VARCHAR(20) NULL,
  pix_key VARCHAR(120) NULL,
  pix_holder VARCHAR(160) NULL,

  home_care_experience TEXT NULL,
  years_of_experience VARCHAR(40) NULL,
  specializations TEXT NULL,

  admin_note VARCHAR(255) NULL,
  reviewed_by_user_id INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_user_id INT UNSIGNED NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_prof_apps_status (status),
  KEY idx_prof_apps_created_at (created_at),
  UNIQUE KEY uk_prof_apps_email (email),
  CONSTRAINT fk_prof_apps_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_prof_apps_created_user FOREIGN KEY (created_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar candidaturas de profissionais' AS name, 'professional_applications.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'professional_applications.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('professional_applications.manage')
WHERE r.slug IN ('admin')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
