-- ============================================================================
-- MIGRATION: Sistema de Tipos de Serviço por Especialidade
-- Data: 2026-03-07
-- USANDO APENAS TABELAS QUE EXISTEM NO SISTEMA
-- ============================================================================

-- PASSO 1: Criar tabela de tipos de serviço
DROP TABLE IF EXISTS specialty_service_types;

CREATE TABLE specialty_service_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    specialty_id INT NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    description TEXT,
    base_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_specialty_service (specialty_id, service_type),
    INDEX idx_specialty_id (specialty_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASSO 2: Inserir dados (usando IDs fixos 1-10)

-- Fisioterapia (specialty_id = 1)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(1, 'Atendimento Online', 'Atendimento remoto via videochamada', 80.00, 'active'),
(1, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 120.00, 'active'),
(1, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 100.00, 'active');

-- Fonoaudiologia (specialty_id = 2)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(2, 'Atendimento Online', 'Atendimento remoto via videochamada', 75.00, 'active'),
(2, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 110.00, 'active'),
(2, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 92.50, 'active');

-- Terapia Ocupacional (specialty_id = 3)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(3, 'Atendimento Online', 'Atendimento remoto via videochamada', 75.00, 'active'),
(3, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 110.00, 'active'),
(3, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 92.50, 'active');

-- Psicologia (specialty_id = 4)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(4, 'Atendimento Online', 'Atendimento remoto via videochamada', 90.00, 'active'),
(4, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 130.00, 'active'),
(4, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 110.00, 'active');

-- Nutrição (specialty_id = 5)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(5, 'Atendimento Online', 'Atendimento remoto via videochamada', 70.00, 'active'),
(5, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 100.00, 'active'),
(5, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 85.00, 'active');

-- Enfermagem (specialty_id = 6)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(6, 'Atendimento Online', 'Atendimento remoto via videochamada', 60.00, 'active'),
(6, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 90.00, 'active'),
(6, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 75.00, 'active');

-- Pedagogia (specialty_id = 7)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(7, 'Atendimento Online', 'Atendimento remoto via videochamada', 65.00, 'active'),
(7, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 95.00, 'active'),
(7, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 80.00, 'active');

-- Psicopedagogia (specialty_id = 8)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(8, 'Atendimento Online', 'Atendimento remoto via videochamada', 70.00, 'active'),
(8, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 105.00, 'active'),
(8, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 87.50, 'active');

-- Musicoterapia (specialty_id = 9)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(9, 'Atendimento Online', 'Atendimento remoto via videochamada', 65.00, 'active'),
(9, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 95.00, 'active'),
(9, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 80.00, 'active');

-- Educação Física (specialty_id = 10)
INSERT INTO specialty_service_types (specialty_id, service_type, description, base_value, status) VALUES
(10, 'Atendimento Online', 'Atendimento remoto via videochamada', 60.00, 'active'),
(10, 'Atendimento Presencial', 'Atendimento presencial no local indicado', 90.00, 'active'),
(10, 'Atendimento Híbrido', 'Combinação de atendimento online e presencial', 75.00, 'active');

-- PASSO 3: Adicionar colunas APENAS nas tabelas que EXISTEM

-- Tabela: professional_applications (candidaturas de profissionais)
ALTER TABLE professional_applications ADD COLUMN service_type_id INT NULL;
CREATE INDEX idx_prof_apps_service_type ON professional_applications(service_type_id);

-- Tabela: users (profissionais cadastrados)
ALTER TABLE users ADD COLUMN service_type_id INT NULL;
CREATE INDEX idx_users_service_type ON users(service_type_id);

-- Tabela: demands (captações)
ALTER TABLE demands ADD COLUMN service_type_id INT NULL;
CREATE INDEX idx_demands_service_type ON demands(service_type_id);

-- Tabela: patient_assignments (atribuições de pacientes)
ALTER TABLE patient_assignments ADD COLUMN service_type_id INT NULL;
CREATE INDEX idx_assignments_service_type ON patient_assignments(service_type_id);

-- ============================================================================
-- VERIFICAÇÃO
-- ============================================================================

SELECT 'Tipos de serviço criados:' AS status, COUNT(*) AS total FROM specialty_service_types;

SELECT * FROM specialty_service_types ORDER BY specialty_id, service_type;
