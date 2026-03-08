-- ============================================
-- SISTEMA DE OPERADORAS DE SAÚDE
-- ============================================

-- 1. Criar tabela de operadoras
CREATE TABLE IF NOT EXISTS health_insurers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    cnpj VARCHAR(18),
    contact_phone VARCHAR(20),
    contact_email VARCHAR(255),
    billing_email VARCHAR(255),
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Adicionar campo health_insurer_id em patient_assignments
ALTER TABLE patient_assignments 
ADD COLUMN health_insurer_id INT NULL AFTER specialty_service_id,
ADD KEY idx_health_insurer (health_insurer_id);

-- 3. Adicionar campo health_insurer_id em patients (para pré-seleção)
ALTER TABLE patients 
ADD COLUMN health_insurer_id INT NULL AFTER address_complement,
ADD KEY idx_health_insurer (health_insurer_id);

-- 4. Inserir algumas operadoras padrão
INSERT INTO health_insurers (name, notes) VALUES
('Particular', 'Pacientes particulares sem convênio'),
('Unimed', 'Unimed - Cooperativa de Trabalho Médico'),
('Bradesco Saúde', 'Bradesco Seguros'),
('SulAmérica', 'SulAmérica Seguros'),
('Amil', 'Amil Assistência Médica Internacional'),
('NotreDame Intermédica', 'Grupo NotreDame Intermédica');

-- 5. Verificar resultado
SELECT 
    'OPERADORAS CADASTRADAS' as info,
    id,
    name,
    is_active
FROM health_insurers
ORDER BY name;
