-- ============================================
-- SQL ÚNICO COMPLETO - MÓDULO DE RH
-- Todas as tabelas e campos necessários
-- ============================================

-- ============================================
-- 1. TABELA PRINCIPAL: hr_employees
-- ============================================
CREATE TABLE IF NOT EXISTS hr_employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Dados Pessoais
    full_name VARCHAR(255) NOT NULL,
    cpf VARCHAR(20) NULL,
    rg VARCHAR(20) NULL,
    rg_issuer VARCHAR(50) NULL,
    rg_issue_date DATE NULL,
    birth_date DATE NULL,
    gender ENUM('masculino','feminino','outro') NULL,
    marital_status ENUM('solteiro','casado','divorciado','viuvo','uniao_estavel') NULL,
    nationality VARCHAR(100) NULL,
    birth_city VARCHAR(100) NULL,
    birth_state VARCHAR(2) NULL,
    mother_name VARCHAR(255) NULL,
    father_name VARCHAR(255) NULL,
    
    -- Documentação Trabalhista
    ctps_number VARCHAR(20) NULL,
    ctps_series VARCHAR(10) NULL,
    ctps_state VARCHAR(2) NULL,
    ctps_issue_date DATE NULL,
    pis_pasep VARCHAR(20) NULL,
    voter_title VARCHAR(20) NULL,
    voter_zone VARCHAR(10) NULL,
    voter_section VARCHAR(10) NULL,
    military_certificate VARCHAR(20) NULL,
    driver_license VARCHAR(20) NULL,
    driver_license_category VARCHAR(5) NULL,
    driver_license_expiry DATE NULL,
    
    -- Contato
    phone VARCHAR(20) NULL,
    phone_secondary VARCHAR(20) NULL,
    email VARCHAR(255) NULL,
    emergency_contact_name VARCHAR(255) NULL,
    emergency_contact_phone VARCHAR(20) NULL,
    emergency_contact_relationship VARCHAR(50) NULL,
    
    -- Endereço
    address_cep VARCHAR(10) NULL,
    address_street VARCHAR(255) NULL,
    address_number VARCHAR(20) NULL,
    address_complement VARCHAR(100) NULL,
    address_neighborhood VARCHAR(100) NULL,
    address_city VARCHAR(100) NULL,
    address_state VARCHAR(2) NULL,
    address_country VARCHAR(100) DEFAULT 'Brasil',
    
    -- Dados Bancários
    bank_name VARCHAR(100) NULL,
    bank_agency VARCHAR(20) NULL,
    bank_account VARCHAR(30) NULL,
    bank_account_type ENUM('corrente','poupanca') NULL,
    bank_pix_key VARCHAR(255) NULL,
    
    -- Dados Contratuais
    employee_number VARCHAR(50) NULL,
    position VARCHAR(100) NULL COMMENT 'Cargo/Função',
    department VARCHAR(100) NULL,
    contract_type ENUM('clt','pj','estagio','temporario','autonomo') NULL,
    admission_date DATE NULL,
    termination_date DATE NULL,
    termination_reason TEXT NULL,
    base_salary DECIMAL(10,2) NULL,
    work_hours VARCHAR(50) NULL,
    work_regime ENUM('presencial','remoto','hibrido') NULL,
    supervisor_id BIGINT UNSIGNED NULL,
    
    -- Benefícios
    benefit_transport BOOLEAN DEFAULT 0,
    benefit_food_value DECIMAL(10,2) NULL,
    benefit_meal_value DECIMAL(10,2) NULL,
    benefit_health_plan BOOLEAN DEFAULT 0,
    benefit_dental_plan BOOLEAN DEFAULT 0,
    benefit_life_insurance BOOLEAN DEFAULT 0,
    benefit_other TEXT NULL,
    
    -- Escolaridade
    education_level ENUM('fundamental_incompleto','fundamental_completo','medio_incompleto','medio_completo','superior_incompleto','superior_completo','pos_graduacao','mestrado','doutorado') NULL,
    education_course VARCHAR(255) NULL,
    education_institution VARCHAR(255) NULL,
    education_year INT NULL,
    
    -- Saúde
    blood_type VARCHAR(5) NULL,
    allergies TEXT NULL,
    medical_restrictions TEXT NULL,
    medications TEXT NULL,
    
    -- Outros
    photo_url VARCHAR(500) NULL,
    notes TEXT NULL,
    status ENUM('active','inactive','terminated') DEFAULT 'active',
    
    -- Vínculo com usuário do sistema
    user_id INT NULL,
    
    -- Auditoria
    created_by_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_hr_employees_status (status),
    KEY idx_hr_employees_email (email),
    KEY idx_hr_employees_cpf (cpf),
    KEY idx_hr_employees_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. TABELA: hr_employee_dependents
-- ============================================
CREATE TABLE IF NOT EXISTS hr_employee_dependents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    cpf VARCHAR(20) NULL,
    birth_date DATE NULL,
    relationship ENUM('filho','filha','conjuge','pai','mae','outro') NOT NULL,
    is_ir_dependent BOOLEAN DEFAULT 0 COMMENT 'Dependente para IR',
    is_health_plan_dependent BOOLEAN DEFAULT 0 COMMENT 'Dependente no plano de saúde',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_dependents_employee_id (employee_id),
    CONSTRAINT fk_dependents_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. TABELA: hr_employee_history
-- ============================================
CREATE TABLE IF NOT EXISTS hr_employee_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    change_type ENUM('admissao','promocao','aumento','reducao','transferencia','desligamento','ferias','afastamento','outro') NOT NULL,
    change_date DATE NOT NULL,
    description TEXT NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    created_by_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_history_employee_id (employee_id),
    KEY idx_history_change_date (change_date),
    CONSTRAINT fk_history_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. TABELA: zapsign_config
-- ============================================
CREATE TABLE IF NOT EXISTS zapsign_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_token VARCHAR(500) NULL,
    sandbox_mode BOOLEAN DEFAULT 1,
    webhook_url VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. TABELA: zapsign_contract_templates
-- ============================================
CREATE TABLE IF NOT EXISTS zapsign_contract_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    contract_type ENUM('clt','pj','estagio','temporario','autonomo') NOT NULL,
    file_path VARCHAR(500) NULL COMMENT 'Caminho do PDF template',
    is_active BOOLEAN DEFAULT 1,
    created_by_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_templates_contract_type (contract_type),
    KEY idx_templates_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. TABELA: hr_employee_contracts
-- ============================================
CREATE TABLE IF NOT EXISTS hr_employee_contracts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    signer_name VARCHAR(255) NOT NULL,
    signer_email VARCHAR(255) NOT NULL,
    signer_phone VARCHAR(20) NULL,
    zapsign_doc_token VARCHAR(255) NULL,
    zapsign_status ENUM('pending','signed','expired','cancelled') DEFAULT 'pending',
    sent_at DATETIME NULL,
    signed_at DATETIME NULL,
    pdf_url VARCHAR(500) NULL,
    pdf_signed_url VARCHAR(500) NULL,
    notes TEXT NULL,
    created_by_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_contracts_employee_id (employee_id),
    KEY idx_contracts_status (zapsign_status),
    KEY idx_contracts_doc_token (zapsign_doc_token),
    CONSTRAINT fk_contracts_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_contracts_template FOREIGN KEY (template_id) REFERENCES zapsign_contract_templates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. TABELA: hr_employee_payroll
-- ============================================
CREATE TABLE IF NOT EXISTS hr_employee_payroll (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    base_salary DECIMAL(10,2) NOT NULL,
    start_date DATE NOT NULL,
    first_payment_due_date DATE NOT NULL,
    payment_day INT NOT NULL DEFAULT 5 COMMENT 'Dia do mês para pagamento (1-31)',
    is_active BOOLEAN DEFAULT 1,
    end_date DATE NULL,
    notes TEXT NULL,
    created_by_user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_payroll_employee_id (employee_id),
    KEY idx_payroll_is_active (is_active),
    KEY idx_payroll_start_date (start_date),
    CONSTRAINT fk_payroll_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. TABELA: hr_payroll_entries
-- ============================================
CREATE TABLE IF NOT EXISTS hr_payroll_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    payroll_id BIGINT UNSIGNED NOT NULL,
    reference_month VARCHAR(7) NOT NULL COMMENT 'Formato: YYYY-MM',
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    financial_entry_id BIGINT UNSIGNED NULL COMMENT 'ID do lançamento financeiro gerado',
    status ENUM('pending','generated','paid','cancelled') DEFAULT 'pending',
    generated_at DATETIME NULL,
    paid_at DATETIME NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_payroll_entries_employee_id (employee_id),
    KEY idx_payroll_entries_reference_month (reference_month),
    KEY idx_payroll_entries_status (status),
    KEY idx_payroll_entries_financial_entry_id (financial_entry_id),
    UNIQUE KEY unique_payroll_month (employee_id, reference_month),
    CONSTRAINT fk_payroll_entries_employee FOREIGN KEY (employee_id) REFERENCES hr_employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_entries_payroll FOREIGN KEY (payroll_id) REFERENCES hr_employee_payroll(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. ADICIONAR CAMPOS DE SUSPENSÃO EM USERS
-- ============================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_suspended BOOLEAN DEFAULT 0 AFTER status;
ALTER TABLE users ADD COLUMN IF NOT EXISTS suspended_at DATETIME NULL AFTER is_suspended;
ALTER TABLE users ADD COLUMN IF NOT EXISTS suspended_by_user_id INT NULL AFTER suspended_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS suspension_reason TEXT NULL AFTER suspended_by_user_id;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_is_suspended (is_suspended);

-- ============================================
-- 10. TABELA: settings (se não existir)
-- ============================================
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. CONFIGURAÇÕES PADRÃO
-- ============================================
INSERT INTO settings (setting_key, setting_value) 
VALUES ('payroll.default_category', 'Folha de Pagamento')
ON DUPLICATE KEY UPDATE setting_value = 'Folha de Pagamento';

INSERT INTO settings (setting_key, setting_value) 
VALUES ('payroll.default_cost_center', 'Administrativo')
ON DUPLICATE KEY UPDATE setting_value = 'Administrativo';

INSERT INTO settings (setting_key, setting_value) 
VALUES ('payroll.auto_generate_enabled', '1')
ON DUPLICATE KEY UPDATE setting_value = '1';

-- ============================================
-- VERIFICAÇÃO FINAL
-- ============================================
SELECT 'Instalação concluída com sucesso!' as status,
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'hr_%') as tabelas_hr,
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'zapsign_%') as tabelas_zapsign;
