ALTER TABLE appointments
  ADD COLUMN specialty VARCHAR(120) NULL AFTER professional_user_id;

CREATE TABLE IF NOT EXISTS specialty_minimums (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  specialty VARCHAR(120) NOT NULL,
  minimum_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_specialty_minimums_specialty (specialty),
  KEY idx_specialty_minimums_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_value_authorizations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',

  demand_id BIGINT UNSIGNED NULL,
  patient_id BIGINT UNSIGNED NOT NULL,
  professional_user_id INT UNSIGNED NOT NULL,
  specialty VARCHAR(120) NOT NULL,

  first_at DATETIME NOT NULL,
  recurrence_type VARCHAR(40) NOT NULL,
  recurrence_rule TEXT NULL,

  requested_value DECIMAL(10,2) NOT NULL,
  minimum_value DECIMAL(10,2) NOT NULL,

  requested_by_user_id INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  reviewed_by_user_id INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(255) NULL,

  created_appointment_id BIGINT UNSIGNED NULL,

  PRIMARY KEY (id),
  KEY idx_ava_status (status),
  KEY idx_ava_requested_at (requested_at),
  KEY idx_ava_specialty (specialty),
  KEY idx_ava_patient_id (patient_id),
  KEY idx_ava_professional_user_id (professional_user_id),
  KEY idx_ava_created_appointment_id (created_appointment_id),
  CONSTRAINT fk_ava_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE SET NULL,
  CONSTRAINT fk_ava_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ava_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ava_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ava_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ava_created_appointment FOREIGN KEY (created_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
