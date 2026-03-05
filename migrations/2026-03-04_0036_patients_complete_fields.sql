-- Expansão completa do cadastro de pacientes com todos os campos solicitados
-- Organizado em 19 seções conforme especificação

ALTER TABLE patients
  -- 1. Identificação (campos adicionais)
  ADD COLUMN social_name VARCHAR(160) NULL AFTER full_name,
  ADD COLUMN gender VARCHAR(40) NULL AFTER sex,
  ADD COLUMN nationality VARCHAR(80) NULL AFTER birth_date,
  ADD COLUMN birth_city VARCHAR(120) NULL AFTER nationality,
  ADD COLUMN birth_state CHAR(2) NULL AFTER birth_city,
  ADD COLUMN rg_issuer VARCHAR(60) NULL AFTER rg,
  
  -- 2. Contato (campo adicional)
  ADD COLUMN emergency_phone_secondary VARCHAR(30) NULL AFTER emergency_phone,
  ADD COLUMN emergency_email VARCHAR(190) NULL AFTER emergency_phone_secondary,
  
  -- 3. Convênio (campos expandidos)
  ADD COLUMN has_insurance TINYINT(1) NULL DEFAULT 0 AFTER insurance_notes,
  ADD COLUMN insurance_plan VARCHAR(120) NULL AFTER insurance_name,
  ADD COLUMN insurance_holder_name VARCHAR(160) NULL AFTER insurance_card_number,
  ADD COLUMN insurance_dependency_level VARCHAR(60) NULL AFTER insurance_holder_name,
  ADD COLUMN insurance_company VARCHAR(160) NULL AFTER insurance_dependency_level,
  ADD COLUMN insurance_card_front_path VARCHAR(255) NULL AFTER insurance_company,
  ADD COLUMN insurance_card_back_path VARCHAR(255) NULL AFTER insurance_card_front_path,
  
  -- 6. Informações Médicas Básicas
  ADD COLUMN blood_type VARCHAR(10) NULL AFTER health_json,
  ADD COLUMN rh_factor VARCHAR(10) NULL AFTER blood_type,
  ADD COLUMN height_cm DECIMAL(5,2) NULL AFTER rh_factor,
  ADD COLUMN weight_kg DECIMAL(5,2) NULL AFTER height_cm,
  ADD COLUMN bmi DECIMAL(5,2) NULL AFTER weight_kg,
  ADD COLUMN blood_pressure VARCHAR(20) NULL AFTER bmi,
  ADD COLUMN heart_rate INT NULL AFTER blood_pressure,
  ADD COLUMN body_temperature DECIMAL(4,2) NULL AFTER heart_rate,
  
  -- 12. Hábitos de Vida
  ADD COLUMN smoker VARCHAR(20) NULL AFTER body_temperature,
  ADD COLUMN alcohol_consumption VARCHAR(60) NULL AFTER smoker,
  ADD COLUMN drug_use VARCHAR(60) NULL AFTER alcohol_consumption,
  ADD COLUMN physical_activity VARCHAR(60) NULL AFTER drug_use,
  ADD COLUMN exercise_frequency VARCHAR(60) NULL AFTER physical_activity,
  ADD COLUMN diet_type VARCHAR(80) NULL AFTER exercise_frequency,
  
  -- 13. Dados Biométricos
  ADD COLUMN waist_circumference_cm DECIMAL(5,2) NULL AFTER diet_type,
  ADD COLUMN body_fat_percentage DECIMAL(5,2) NULL AFTER waist_circumference_cm,
  ADD COLUMN muscle_mass_kg DECIMAL(5,2) NULL AFTER body_fat_percentage,
  ADD COLUMN oxygen_saturation DECIMAL(5,2) NULL AFTER muscle_mass_kg,
  
  -- 15. Administrativo (campos expandidos)
  ADD COLUMN registration_date DATE NULL AFTER admin_status,
  
  -- 16. LGPD (campos expandidos - alguns já em lgpd_json)
  ADD COLUMN consent_data_usage TINYINT(1) NULL DEFAULT 0 AFTER lgpd_json,
  ADD COLUMN consent_privacy_terms TINYINT(1) NULL DEFAULT 0 AFTER consent_data_usage,
  ADD COLUMN consent_contact TINYINT(1) NULL DEFAULT 0 AFTER consent_privacy_terms,
  ADD COLUMN consent_data_sharing TINYINT(1) NULL DEFAULT 0 AFTER consent_contact,
  ADD COLUMN consent_signature_path VARCHAR(255) NULL AFTER consent_data_sharing,
  ADD COLUMN consent_signed_at DATETIME NULL AFTER consent_signature_path;

-- Tabela para Alergias (Seção 7)
CREATE TABLE IF NOT EXISTS patient_allergies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  has_allergies TINYINT(1) NOT NULL DEFAULT 0,
  allergy_type VARCHAR(60) NULL COMMENT 'medications, foods, substances',
  allergen_name VARCHAR(160) NULL,
  severity VARCHAR(40) NULL COMMENT 'mild, moderate, severe',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patient_allergies_patient (patient_id),
  CONSTRAINT fk_patient_allergies_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para Doenças Preexistentes (Seção 8)
CREATE TABLE IF NOT EXISTS patient_chronic_conditions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  condition_type VARCHAR(80) NOT NULL COMMENT 'diabetes, hypertension, cardiopathy, asthma, autoimmune, respiratory, neurological, other',
  condition_name VARCHAR(160) NULL,
  diagnosed_at DATE NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patient_chronic_patient (patient_id),
  KEY idx_patient_chronic_type (condition_type),
  CONSTRAINT fk_patient_chronic_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para Histórico Familiar (Seção 9)
CREATE TABLE IF NOT EXISTS patient_family_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  condition_type VARCHAR(80) NOT NULL COMMENT 'hereditary, cancer, cardiac, diabetes, other',
  condition_name VARCHAR(160) NULL,
  relationship VARCHAR(60) NULL COMMENT 'father, mother, sibling, grandparent',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patient_family_history_patient (patient_id),
  CONSTRAINT fk_patient_family_history_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para Medicamentos em Uso (Seção 10)
CREATE TABLE IF NOT EXISTS patient_medications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  medication_name VARCHAR(160) NOT NULL,
  dosage VARCHAR(80) NULL,
  frequency VARCHAR(80) NULL,
  started_at DATE NULL,
  prescribing_doctor VARCHAR(160) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patient_medications_patient (patient_id),
  KEY idx_patient_medications_active (is_active),
  CONSTRAINT fk_patient_medications_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para Histórico Médico (Seção 11)
CREATE TABLE IF NOT EXISTS patient_medical_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL COMMENT 'surgery, hospitalization, procedure, trauma, pregnancy, birth, abortion',
  event_name VARCHAR(160) NULL,
  occurred_at DATE NULL,
  location VARCHAR(160) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patient_medical_history_patient (patient_id),
  KEY idx_patient_medical_history_type (event_type),
  CONSTRAINT fk_patient_medical_history_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para Documentos e Arquivos (Seção 14)
CREATE TABLE IF NOT EXISTS patient_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(80) NOT NULL COMMENT 'id_document, sus_card, insurance_card, lab_exam, imaging_exam, prescription, medical_report, other',
  document_name VARCHAR(160) NULL,
  file_path VARCHAR(255) NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_patient_documents_patient (patient_id),
  KEY idx_patient_documents_type (document_type),
  CONSTRAINT fk_patient_documents_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para Responsável Legal (Seção 17)
CREATE TABLE IF NOT EXISTS patient_legal_guardians (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  guardian_name VARCHAR(160) NOT NULL,
  guardian_cpf VARCHAR(20) NULL,
  relationship VARCHAR(60) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  address_zip VARCHAR(12) NULL,
  address_street VARCHAR(160) NULL,
  address_number VARCHAR(20) NULL,
  address_complement VARCHAR(80) NULL,
  address_neighborhood VARCHAR(80) NULL,
  address_city VARCHAR(120) NULL,
  address_state CHAR(2) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patient_guardians_patient (patient_id),
  CONSTRAINT fk_patient_guardians_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
