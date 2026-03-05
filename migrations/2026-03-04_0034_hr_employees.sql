CREATE TABLE IF NOT EXISTS hr_employees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(160) NOT NULL,
  cpf VARCHAR(20) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  position VARCHAR(120) NULL,
  hire_date DATE NULL,
  status ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hr_employees_status (status),
  KEY idx_hr_employees_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employee_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id BIGINT UNSIGNED NOT NULL,
  document_type ENUM('contract','id','address_proof','other') NOT NULL,
  title VARCHAR(160) NULL,
  zapsign_doc_token VARCHAR(120) NULL,
  zapsign_status ENUM('pending','signed','expired','cancelled') NULL,
  signed_at DATETIME NULL,
  document_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hr_employee_docs_employee_id (employee_id),
  KEY idx_hr_employee_docs_zapsign_token (zapsign_doc_token),
  KEY idx_hr_employee_docs_zapsign_status (zapsign_status),
  CONSTRAINT fk_hr_employee_docs_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_hr_employee_docs_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar RH (funcionários)' AS name, 'hr.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'hr.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'hr.manage'
WHERE r.slug IN ('admin','rh')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
