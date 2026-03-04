CREATE TABLE IF NOT EXISTS professional_documentations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  professional_user_id INT UNSIGNED NOT NULL,

  patient_ref VARCHAR(160) NOT NULL,
  sessions_count INT UNSIGNED NOT NULL DEFAULT 1,

  billing_docs TEXT NULL,
  productivity_docs TEXT NULL,
  notes TEXT NULL,

  status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  due_at DATETIME NULL,
  submitted_at DATETIME NULL,

  reviewed_by_user_id INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(255) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_prof_docs_professional (professional_user_id),
  KEY idx_prof_docs_status (status),
  KEY idx_prof_docs_due_at (due_at),
  KEY idx_prof_docs_submitted_at (submitted_at),
  CONSTRAINT fk_prof_docs_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_prof_docs_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Enviar documentação (profissional)' AS name, 'professional_docs.submit' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'professional_docs.submit')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Revisar documentação (admin/financeiro)' AS name, 'professional_docs.review' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'professional_docs.review')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('professional_docs.submit')
WHERE r.slug IN ('profissional')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('professional_docs.review')
WHERE r.slug IN ('admin','financeiro')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
