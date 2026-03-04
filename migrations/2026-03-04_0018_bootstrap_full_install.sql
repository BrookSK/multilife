CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  module VARCHAR(80) NOT NULL,
  record_id VARCHAR(80) NULL,
  old_data JSON NULL,
  new_data JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user_id (user_id),
  KEY idx_audit_module (module),
  KEY idx_audit_created_at (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demands (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  location_city VARCHAR(120) NULL,
  location_state CHAR(2) NULL,
  specialty VARCHAR(120) NULL,
  description TEXT NULL,
  origin_email VARCHAR(190) NULL,
  status ENUM('aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado') NOT NULL DEFAULT 'aguardando_captacao',
  assumed_by_user_id INT UNSIGNED NULL,
  assumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_demands_status (status),
  KEY idx_demands_created_at (created_at),
  KEY idx_demands_assumed_by (assumed_by_user_id),
  CONSTRAINT fk_demands_assumed_by FOREIGN KEY (assumed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demand_status_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  demand_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  user_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_demand_status_logs_demand_id (demand_id),
  KEY idx_demand_status_logs_created_at (created_at),
  CONSTRAINT fk_demand_status_logs_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE,
  CONSTRAINT fk_demand_status_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  specialty VARCHAR(120) NULL,
  city VARCHAR(120) NULL,
  state CHAR(2) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_whatsapp_groups_status (status),
  KEY idx_whatsapp_groups_filters (specialty, city, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demand_dispatch_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  demand_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NULL,
  dispatched_by_user_id INT UNSIGNED NULL,
  message TEXT NULL,
  dispatch_status ENUM('queued','sent','error') NOT NULL DEFAULT 'sent',
  error_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_demand_dispatch_logs_demand_id (demand_id),
  KEY idx_demand_dispatch_logs_group_id (group_id),
  KEY idx_demand_dispatch_logs_created_at (created_at),
  CONSTRAINT fk_demand_dispatch_logs_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE,
  CONSTRAINT fk_demand_dispatch_logs_group FOREIGN KEY (group_id) REFERENCES whatsapp_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_demand_dispatch_logs_user FOREIGN KEY (dispatched_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  external_phone VARCHAR(30) NOT NULL,
  contact_kind ENUM('unknown','professional','patient') NOT NULL DEFAULT 'unknown',
  contact_ref_id BIGINT UNSIGNED NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  assigned_user_id INT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  last_message_preview VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_conversations_status (status),
  KEY idx_chat_conversations_last_message_at (last_message_at),
  KEY idx_chat_conversations_assigned_user_id (assigned_user_id),
  UNIQUE KEY uk_chat_conversations_phone_open (external_phone, status),
  CONSTRAINT fk_chat_conversations_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('in','out') NOT NULL,
  body TEXT NOT NULL,
  sent_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_messages_conversation_id (conversation_id),
  KEY idx_chat_messages_created_at (created_at),
  CONSTRAINT fk_chat_messages_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_messages_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM('transfer','finalize','reopen','assign') NOT NULL,
  from_user_id INT UNSIGNED NULL,
  to_user_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_events_conversation_id (conversation_id),
  KEY idx_chat_events_created_at (created_at),
  CONSTRAINT fk_chat_events_conversation FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_events_from_user FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_chat_events_to_user FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS professional_documentations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  professional_user_id INT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NULL,

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
  KEY idx_prof_docs_appointment_id (appointment_id),
  KEY idx_prof_docs_status (status),
  KEY idx_prof_docs_due_at (due_at),
  KEY idx_prof_docs_submitted_at (submitted_at),
  CONSTRAINT fk_prof_docs_professional FOREIGN KEY (professional_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_prof_docs_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
  CONSTRAINT fk_prof_docs_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type ENUM('patient','professional','company') NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  category VARCHAR(60) NOT NULL,
  title VARCHAR(160) NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_documents_entity (entity_type, entity_id),
  KEY idx_documents_category (category),
  KEY idx_documents_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id BIGINT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  file_size BIGINT UNSIGNED NULL,
  valid_until DATE NULL,
  uploaded_by_user_id INT UNSIGNED NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_doc_versions (document_id, version_no),
  KEY idx_doc_versions_uploaded_at (uploaded_at),
  KEY idx_doc_versions_valid_until (valid_until),
  CONSTRAINT fk_doc_versions_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_versions_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  updated_by_user_id INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_admin_settings_key (setting_key),
  CONSTRAINT fk_admin_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_employees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  role_title VARCHAR(120) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hr_employees_status (status),
  KEY idx_hr_employees_full_name (full_name),
  KEY idx_hr_employees_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(60) NOT NULL,
  action VARCHAR(80) NOT NULL,
  status ENUM('success','error') NOT NULL,
  http_status INT NULL,
  request_payload JSON NULL,
  response_payload JSON NULL,
  error_message VARCHAR(255) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_integration_logs_provider (provider),
  KEY idx_integration_logs_action (action),
  KEY idx_integration_logs_status (status),
  KEY idx_integration_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(60) NOT NULL,
  action VARCHAR(80) NOT NULL,
  status ENUM('pending','running','success','error','dead') NOT NULL DEFAULT 'pending',
  payload JSON NULL,
  last_error VARCHAR(255) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
  next_run_at DATETIME NULL,
  last_run_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_integration_jobs_status (status),
  KEY idx_integration_jobs_next_run_at (next_run_at),
  KEY idx_integration_jobs_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_access_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  action VARCHAR(60) NOT NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_patient_access_logs_patient_id (patient_id),
  KEY idx_patient_access_logs_user_id (user_id),
  KEY idx_patient_access_logs_created_at (created_at),
  CONSTRAINT fk_patient_access_logs_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_patient_access_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(60) NOT NULL,
  status ENUM('running','success','error') NOT NULL DEFAULT 'running',
  started_by_user_id INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  output_path VARCHAR(255) NULL,
  error_message VARCHAR(255) NULL,
  meta_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_backup_runs_kind (kind),
  KEY idx_backup_runs_status (status),
  KEY idx_backup_runs_started_at (started_at),
  CONSTRAINT fk_backup_runs_user FOREIGN KEY (started_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'Admin' AS name, 'admin' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'admin')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'Financeiro' AS name, 'financeiro' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'financeiro')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'Captador / Admissão' AS name, 'captador' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'captador')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'TI' AS name, 'ti' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'ti')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (
  SELECT 'Profissional' AS name, 'profissional' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'profissional')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar usuários' AS name, 'users.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'users.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar perfis' AS name, 'roles.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'roles.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar permissões' AS name, 'permissions.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'permissions.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar captação (demandas)' AS name, 'demands.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'demands.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar grupos WhatsApp' AS name, 'whatsapp_groups.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'whatsapp_groups.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Acessar chat interno' AS name, 'chat.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'chat.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar candidaturas de profissionais' AS name, 'professional_applications.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'professional_applications.manage')
LIMIT 1;

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

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar financeiro' AS name, 'finance.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'finance.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar documentos' AS name, 'documents.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'documents.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Dashboard administrativo' AS name, 'admin.dashboard' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'admin.dashboard')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar configurações do Admin' AS name, 'admin.settings.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'admin.settings.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar RH (funcionários internos)' AS name, 'hr.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'hr.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Visualizar logs técnicos' AS name, 'tech_logs.view' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'tech_logs.view')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar jobs de integração' AS name, 'integration_jobs.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'integration_jobs.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Visualizar acessos a prontuário' AS name, 'patient_access_logs.view' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'patient_access_logs.view')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar backups' AS name, 'backups.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'backups.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Acessar relatórios' AS name, 'reports.view' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'reports.view')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar WhatsApp (Evolution)' AS name, 'whatsapp.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'whatsapp.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar OpenAI (ChatGPT)' AS name, 'openai.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'openai.manage')
LIMIT 1;

INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar ZapSign' AS name, 'zapsign.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'zapsign.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'users.manage','roles.manage','permissions.manage',
  'demands.manage','whatsapp_groups.manage','chat.manage',
  'professional_applications.manage',
  'professional_docs.submit','professional_docs.review',
  'patients.manage','patient_links.manage','patients.view_linked',
  'appointments.manage','appointments.view_linked',
  'finance.manage','documents.manage',
  'admin.dashboard','admin.settings.manage','hr.manage',
  'tech_logs.view','integration_jobs.manage',
  'patient_access_logs.view','backups.manage',
  'reports.view',
  'whatsapp.manage','openai.manage','zapsign.manage'
)
WHERE r.slug = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('finance.manage','professional_docs.review')
WHERE r.slug = 'financeiro'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('demands.manage','patients.manage','patient_links.manage','appointments.manage','chat.manage','documents.manage')
WHERE r.slug = 'captador'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('tech_logs.view','integration_jobs.manage','backups.manage','patient_access_logs.view','documents.manage','whatsapp.manage','openai.manage','zapsign.manage','reports.view')
WHERE r.slug = 'ti'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('professional_docs.submit','patients.view_linked','appointments.view_linked')
WHERE r.slug = 'profissional'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (
  SELECT 'docs.reminder_days_before_due' AS setting_key, '2' AS setting_value
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'docs.reminder_days_before_due')
LIMIT 1;

INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (
  SELECT 'finance.repasse_cycle_days' AS setting_key, '15' AS setting_value
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'finance.repasse_cycle_days')
LIMIT 1;

INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (
  SELECT 'demands.assume_timeout_hours' AS setting_key, '4' AS setting_value
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'demands.assume_timeout_hours')
LIMIT 1;

SET @admin_email := 'contato@multilifecare.com.br';
SET @admin_name := 'Admin MultiLife';
SET @admin_password_hash := 'REPLACE_WITH_BCRYPT_HASH_OF_admin123';

INSERT INTO users (name, email, password_hash, status)
SELECT @admin_name, @admin_email, @admin_password_hash, 'active'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = @admin_email)
LIMIT 1;

INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
JOIN roles r ON r.slug = 'admin'
WHERE u.email = @admin_email
  AND NOT EXISTS (
    SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id
  );
