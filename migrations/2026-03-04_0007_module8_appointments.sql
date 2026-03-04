CREATE TABLE IF NOT EXISTS appointments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  demand_id BIGINT UNSIGNED NULL,
  patient_id BIGINT UNSIGNED NOT NULL,
  professional_user_id INT UNSIGNED NOT NULL,

  first_at DATETIME NOT NULL,
  recurrence_type ENUM('single','weekly','monthly','custom') NOT NULL DEFAULT 'single',
  recurrence_rule TEXT NULL,

  value_per_session DECIMAL(10,2) NOT NULL DEFAULT 0.00,

  status ENUM('agendado','pendente_formulario','realizado','atrasado','cancelado','revisao_admin') NOT NULL DEFAULT 'agendado',
  cancel_reason VARCHAR(255) NULL,

  created_by_user_id INT UNSIGNED NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_appointments_first_at (first_at),
  KEY idx_appointments_status (status),
  KEY idx_appointments_patient_id (patient_id),
  KEY idx_appointments_professional_user_id (professional_user_id),
  CONSTRAINT fk_appointments_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE SET NULL,
  CONSTRAINT fk_appointments_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_appointments_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_appointments_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_status_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  user_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_appointment_status_logs_appointment_id (appointment_id),
  KEY idx_appointment_status_logs_created_at (created_at),
  CONSTRAINT fk_appointment_status_logs_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
  CONSTRAINT fk_appointment_status_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar agendamentos' AS name, 'appointments.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'appointments.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Visualizar agendamentos vinculados (profissional)' AS name, 'appointments.view_linked' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'appointments.view_linked')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('appointments.manage')
WHERE r.slug IN ('admin','captador')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('appointments.view_linked')
WHERE r.slug IN ('profissional')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
