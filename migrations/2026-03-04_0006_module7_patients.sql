CREATE TABLE IF NOT EXISTS patients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  full_name VARCHAR(160) NOT NULL,
  cpf VARCHAR(20) NULL,
  rg VARCHAR(30) NULL,
  birth_date DATE NULL,
  sex VARCHAR(20) NULL,
  marital_status VARCHAR(40) NULL,
  profession VARCHAR(80) NULL,
  education_level VARCHAR(80) NULL,
  photo_path VARCHAR(255) NULL,

  email VARCHAR(190) NULL,
  phone_primary VARCHAR(30) NULL,
  phone_secondary VARCHAR(30) NULL,
  whatsapp VARCHAR(30) NULL,
  preferred_contact VARCHAR(30) NULL,

  address_zip VARCHAR(12) NULL,
  address_street VARCHAR(160) NULL,
  address_number VARCHAR(20) NULL,
  address_complement VARCHAR(80) NULL,
  address_neighborhood VARCHAR(80) NULL,
  address_city VARCHAR(120) NULL,
  address_state CHAR(2) NULL,
  address_country VARCHAR(60) NULL,

  emergency_name VARCHAR(160) NULL,
  emergency_relationship VARCHAR(60) NULL,
  emergency_phone VARCHAR(30) NULL,

  insurance_name VARCHAR(120) NULL,
  insurance_card_number VARCHAR(60) NULL,
  insurance_valid_until DATE NULL,
  insurance_notes TEXT NULL,

  health_json JSON NULL,
  medical_history_json JSON NULL,
  documents_json JSON NULL,
  finance_json JSON NULL,
  lgpd_json JSON NULL,
  responsible_json JSON NULL,

  admin_status VARCHAR(40) NULL,
  unit VARCHAR(80) NULL,
  doctor_responsible VARCHAR(160) NULL,

  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_patients_full_name (full_name),
  KEY idx_patients_cpf (cpf),
  KEY idx_patients_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_professionals (
  patient_id BIGINT UNSIGNED NOT NULL,
  professional_user_id INT UNSIGNED NOT NULL,
  specialty VARCHAR(120) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (patient_id, professional_user_id),
  KEY idx_patient_professionals_professional (professional_user_id),
  CONSTRAINT fk_patient_professionals_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_patient_professionals_user FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_prontuario_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  professional_user_id INT UNSIGNED NULL,
  origin VARCHAR(80) NOT NULL,
  occurred_at DATETIME NOT NULL,
  sessions_count INT UNSIGNED NULL,
  notes TEXT NULL,
  attachments_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prontuario_patient (patient_id),
  KEY idx_prontuario_occurred_at (occurred_at),
  CONSTRAINT fk_prontuario_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_prontuario_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar pacientes' AS name, 'patients.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'patients.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar vínculos paciente-profissional' AS name, 'patient_links.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'patient_links.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Visualizar pacientes vinculados (profissional)' AS name, 'patients.view_linked' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'patients.view_linked')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('patients.manage','patient_links.manage')
WHERE r.slug IN ('admin','captador')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('patients.view_linked')
WHERE r.slug IN ('profissional')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
