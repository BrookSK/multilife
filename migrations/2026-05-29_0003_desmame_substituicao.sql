-- Migration: Tabelas para registro de desmame (alteração de frequência) e substituição de profissional
-- Data: 2026-05-29

-- Log de alterações de frequência (desmame)
CREATE TABLE IF NOT EXISTS patient_frequency_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL COMMENT 'Atendimento afetado',
  patient_id BIGINT UNSIGNED NOT NULL,
  old_frequency VARCHAR(50) NULL,
  new_frequency VARCHAR(50) NOT NULL,
  old_session_quantity INT UNSIGNED NULL,
  new_session_quantity INT UNSIGNED NULL,
  reason TEXT NULL COMMENT 'Motivo da alteração',
  apply_to_all TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 se aplicou a todos os atendimentos do paciente',
  changed_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_freq_changes_assignment (assignment_id),
  KEY idx_freq_changes_patient (patient_id),
  CONSTRAINT fk_freq_changes_assignment FOREIGN KEY (assignment_id) REFERENCES patient_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_freq_changes_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de substituições de profissional
CREATE TABLE IF NOT EXISTS patient_professional_substitutions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assignment_id BIGINT UNSIGNED NOT NULL COMMENT 'Atendimento afetado',
  patient_id BIGINT UNSIGNED NOT NULL,
  old_professional_user_id INT UNSIGNED NULL,
  new_professional_user_id INT UNSIGNED NULL,
  old_professional_jid VARCHAR(100) NULL,
  new_professional_jid VARCHAR(100) NULL,
  reason TEXT NULL COMMENT 'Motivo da substituição',
  apply_to_all TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 se aplicou a todos os atendimentos do paciente',
  notify_patient TINYINT(1) NOT NULL DEFAULT 1,
  notify_old_professional TINYINT(1) NOT NULL DEFAULT 1,
  notify_new_professional TINYINT(1) NOT NULL DEFAULT 1,
  changed_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_substitutions_assignment (assignment_id),
  KEY idx_substitutions_patient (patient_id),
  CONSTRAINT fk_substitutions_assignment FOREIGN KEY (assignment_id) REFERENCES patient_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_substitutions_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
