CREATE TABLE IF NOT EXISTS finance_accounts_receivable (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  patient_id BIGINT UNSIGNED NOT NULL,
  professional_user_id INT UNSIGNED NOT NULL,

  amount DECIMAL(10,2) NOT NULL,
  due_at DATETIME NULL,
  status ENUM('pendente','recebido','inadimplente') NOT NULL DEFAULT 'pendente',
  received_at DATETIME NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uk_fin_ar_appointment (appointment_id),
  KEY idx_fin_ar_status (status),
  KEY idx_fin_ar_due_at (due_at),
  KEY idx_fin_ar_patient_id (patient_id),
  CONSTRAINT fk_fin_ar_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
  CONSTRAINT fk_fin_ar_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_fin_ar_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_accounts_payable (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  professional_user_id INT UNSIGNED NOT NULL,

  amount DECIMAL(10,2) NOT NULL,
  due_at DATETIME NULL,
  status ENUM('pendente','pago') NOT NULL DEFAULT 'pendente',
  paid_at DATETIME NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uk_fin_ap_appointment (appointment_id),
  KEY idx_fin_ap_status (status),
  KEY idx_fin_ap_due_at (due_at),
  KEY idx_fin_ap_professional_user_id (professional_user_id),
  CONSTRAINT fk_fin_ap_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
  CONSTRAINT fk_fin_ap_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar financeiro' AS name, 'finance.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'finance.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('finance.manage')
WHERE r.slug IN ('admin','financeiro')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
