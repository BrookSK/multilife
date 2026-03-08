-- ============================================
-- FIX: Adicionar colunas faltantes em hr_employees
-- ============================================
-- Execute este SQL para adicionar todas as colunas que faltam

-- IMPORTANTE: Adicionar coluna 'position' primeiro (campo básico que estava faltando)
ALTER TABLE hr_employees 
ADD COLUMN IF NOT EXISTS position VARCHAR(100) NULL COMMENT 'Cargo/Função' AFTER phone;

-- Verificar e adicionar colunas de Dados Pessoais
ALTER TABLE hr_employees 
ADD COLUMN IF NOT EXISTS rg VARCHAR(20) NULL AFTER cpf,
ADD COLUMN IF NOT EXISTS rg_issuer VARCHAR(50) NULL AFTER rg,
ADD COLUMN IF NOT EXISTS rg_issue_date DATE NULL AFTER rg_issuer,
ADD COLUMN IF NOT EXISTS birth_date DATE NULL AFTER rg_issue_date,
ADD COLUMN IF NOT EXISTS gender ENUM('masculino','feminino','outro') NULL AFTER birth_date,
ADD COLUMN IF NOT EXISTS marital_status ENUM('solteiro','casado','divorciado','viuvo','uniao_estavel') NULL AFTER gender,
ADD COLUMN IF NOT EXISTS nationality VARCHAR(100) NULL AFTER marital_status,
ADD COLUMN IF NOT EXISTS birth_city VARCHAR(100) NULL AFTER nationality,
ADD COLUMN IF NOT EXISTS birth_state VARCHAR(2) NULL AFTER birth_city,
ADD COLUMN IF NOT EXISTS mother_name VARCHAR(255) NULL AFTER birth_state,
ADD COLUMN IF NOT EXISTS father_name VARCHAR(255) NULL AFTER mother_name;

-- Documentação Trabalhista
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS ctps_number VARCHAR(20) NULL AFTER father_name,
ADD COLUMN IF NOT EXISTS ctps_series VARCHAR(10) NULL AFTER ctps_number,
ADD COLUMN IF NOT EXISTS ctps_state VARCHAR(2) NULL AFTER ctps_series,
ADD COLUMN IF NOT EXISTS ctps_issue_date DATE NULL AFTER ctps_state,
ADD COLUMN IF NOT EXISTS pis_pasep VARCHAR(20) NULL AFTER ctps_issue_date,
ADD COLUMN IF NOT EXISTS voter_title VARCHAR(20) NULL AFTER pis_pasep,
ADD COLUMN IF NOT EXISTS voter_zone VARCHAR(10) NULL AFTER voter_title,
ADD COLUMN IF NOT EXISTS voter_section VARCHAR(10) NULL AFTER voter_zone,
ADD COLUMN IF NOT EXISTS military_certificate VARCHAR(20) NULL AFTER voter_section,
ADD COLUMN IF NOT EXISTS driver_license VARCHAR(20) NULL AFTER military_certificate,
ADD COLUMN IF NOT EXISTS driver_license_category VARCHAR(5) NULL AFTER driver_license,
ADD COLUMN IF NOT EXISTS driver_license_expiry DATE NULL AFTER driver_license_category;

-- Contato
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS phone_secondary VARCHAR(20) NULL AFTER phone,
ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(255) NULL AFTER email,
ADD COLUMN IF NOT EXISTS emergency_contact_phone VARCHAR(20) NULL AFTER emergency_contact_name,
ADD COLUMN IF NOT EXISTS emergency_contact_relationship VARCHAR(50) NULL AFTER emergency_contact_phone;

-- Endereço
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS address_cep VARCHAR(10) NULL AFTER emergency_contact_relationship,
ADD COLUMN IF NOT EXISTS address_street VARCHAR(255) NULL AFTER address_cep,
ADD COLUMN IF NOT EXISTS address_number VARCHAR(20) NULL AFTER address_street,
ADD COLUMN IF NOT EXISTS address_complement VARCHAR(100) NULL AFTER address_number,
ADD COLUMN IF NOT EXISTS address_neighborhood VARCHAR(100) NULL AFTER address_complement,
ADD COLUMN IF NOT EXISTS address_city VARCHAR(100) NULL AFTER address_neighborhood,
ADD COLUMN IF NOT EXISTS address_state VARCHAR(2) NULL AFTER address_city,
ADD COLUMN IF NOT EXISTS address_country VARCHAR(100) DEFAULT 'Brasil' AFTER address_state;

-- Dados Bancários
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS bank_name VARCHAR(100) NULL AFTER address_country,
ADD COLUMN IF NOT EXISTS bank_agency VARCHAR(20) NULL AFTER bank_name,
ADD COLUMN IF NOT EXISTS bank_account VARCHAR(30) NULL AFTER bank_agency,
ADD COLUMN IF NOT EXISTS bank_account_type ENUM('corrente','poupanca') NULL AFTER bank_account,
ADD COLUMN IF NOT EXISTS bank_pix_key VARCHAR(255) NULL AFTER bank_account_type;

-- Dados Contratuais
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS employee_number VARCHAR(50) NULL AFTER bank_pix_key,
ADD COLUMN IF NOT EXISTS department VARCHAR(100) NULL AFTER position,
ADD COLUMN IF NOT EXISTS contract_type ENUM('clt','pj','estagio','temporario','autonomo') NULL AFTER department,
ADD COLUMN IF NOT EXISTS admission_date DATE NULL AFTER contract_type,
ADD COLUMN IF NOT EXISTS termination_date DATE NULL AFTER admission_date,
ADD COLUMN IF NOT EXISTS termination_reason TEXT NULL AFTER termination_date,
ADD COLUMN IF NOT EXISTS base_salary DECIMAL(10,2) NULL AFTER termination_reason,
ADD COLUMN IF NOT EXISTS work_hours VARCHAR(50) NULL AFTER base_salary,
ADD COLUMN IF NOT EXISTS work_regime ENUM('presencial','remoto','hibrido') NULL AFTER work_hours,
ADD COLUMN IF NOT EXISTS supervisor_id BIGINT UNSIGNED NULL AFTER work_regime;

-- Benefícios
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS benefit_transport BOOLEAN DEFAULT 0 AFTER supervisor_id,
ADD COLUMN IF NOT EXISTS benefit_food_value DECIMAL(10,2) NULL AFTER benefit_transport,
ADD COLUMN IF NOT EXISTS benefit_meal_value DECIMAL(10,2) NULL AFTER benefit_food_value,
ADD COLUMN IF NOT EXISTS benefit_health_plan BOOLEAN DEFAULT 0 AFTER benefit_meal_value,
ADD COLUMN IF NOT EXISTS benefit_dental_plan BOOLEAN DEFAULT 0 AFTER benefit_health_plan,
ADD COLUMN IF NOT EXISTS benefit_life_insurance BOOLEAN DEFAULT 0 AFTER benefit_dental_plan,
ADD COLUMN IF NOT EXISTS benefit_other TEXT NULL AFTER benefit_life_insurance;

-- Escolaridade
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS education_level ENUM('fundamental_incompleto','fundamental_completo','medio_incompleto','medio_completo','superior_incompleto','superior_completo','pos_graduacao','mestrado','doutorado') NULL AFTER benefit_other,
ADD COLUMN IF NOT EXISTS education_course VARCHAR(255) NULL AFTER education_level,
ADD COLUMN IF NOT EXISTS education_institution VARCHAR(255) NULL AFTER education_course,
ADD COLUMN IF NOT EXISTS education_year INT NULL AFTER education_institution;

-- Saúde
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS blood_type VARCHAR(5) NULL AFTER education_year,
ADD COLUMN IF NOT EXISTS allergies TEXT NULL AFTER blood_type,
ADD COLUMN IF NOT EXISTS medical_restrictions TEXT NULL AFTER allergies,
ADD COLUMN IF NOT EXISTS medications TEXT NULL AFTER medical_restrictions;

-- Outros
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS photo_url VARCHAR(500) NULL AFTER medications,
ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER photo_url;

-- Adicionar índices
ALTER TABLE hr_employees ADD INDEX IF NOT EXISTS idx_hr_employees_cpf (cpf);
ALTER TABLE hr_employees ADD INDEX IF NOT EXISTS idx_hr_employees_user_id (user_id);

-- Mensagem de confirmação
SELECT 'Colunas adicionadas com sucesso!' as status,
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hr_employees') as total_colunas;
