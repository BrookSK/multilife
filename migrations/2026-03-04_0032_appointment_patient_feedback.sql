CREATE TABLE IF NOT EXISTS appointment_patient_feedback (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  token VARCHAR(80) NOT NULL,
  status ENUM('pending','confirmed','professional_absent','cancelled') NOT NULL DEFAULT 'pending',
  note VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_appt_feedback_token (token),
  UNIQUE KEY uk_appt_feedback_appointment (appointment_id),
  KEY idx_appt_feedback_status (status),
  KEY idx_appt_feedback_updated_at (updated_at),
  CONSTRAINT fk_appt_feedback_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
