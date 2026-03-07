-- ============================================================================
-- MIGRATION: Sistema Hierárquico de Tipos de Serviço
-- Data: 2026-03-07
-- Estrutura: ESPECIALIDADE > SERVIÇO > VALORES INDIVIDUAIS
-- ============================================================================

-- PASSO 1: Criar tabela de SERVIÇOS GENÉRICOS
DROP TABLE IF EXISTS service_types;

CREATE TABLE service_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_display_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT = 'Tipos de serviço genéricos (Online, Presencial, Híbrido)';

-- PASSO 2: Inserir SERVIÇOS GENÉRICOS
INSERT INTO service_types (name, description, display_order, status) VALUES
('Atendimento Online', 'Atendimento remoto via videochamada', 1, 'active'),
('Atendimento Presencial', 'Atendimento presencial no local indicado', 2, 'active'),
('Atendimento Híbrido', 'Combinação de atendimento online e presencial', 3, 'active');

-- PASSO 3: Criar tabela de VALORES POR ESPECIALIDADE + SERVIÇO
DROP TABLE IF EXISTS specialty_service_values;

CREATE TABLE specialty_service_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    specialty_id INT NOT NULL,
    service_type_id INT NOT NULL,
    base_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_specialty_service (specialty_id, service_type_id),
    INDEX idx_specialty_id (specialty_id),
    INDEX idx_service_type_id (service_type_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT = 'Valores individuais por especialidade e tipo de serviço';

-- PASSO 4: NÃO inserir valores automaticamente
-- Os valores devem ser configurados manualmente pelo usuário em /specialty_services_v2.php
-- Cada especialidade terá seus próprios valores individuais configurados pelo admin

-- PASSO 5: Adicionar colunas nas tabelas relacionadas (verificando se já existem)
-- Agora as tabelas referenciam o SERVIÇO GENÉRICO (service_type_id)

-- Tabela: professional_applications
SET @dbname = DATABASE();
SET @tablename = 'professional_applications';
SET @columnname = 'service_type_id';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE (table_name = @tablename) AND (table_schema = @dbname)
   AND (column_name = @columnname)) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

CREATE INDEX IF NOT EXISTS idx_prof_apps_service_type ON professional_applications(service_type_id);

-- Tabela: users
SET @tablename = 'users';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE (table_name = @tablename) AND (table_schema = @dbname)
   AND (column_name = @columnname)) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

CREATE INDEX IF NOT EXISTS idx_users_service_type ON users(service_type_id);

-- Tabela: demands
SET @tablename = 'demands';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE (table_name = @tablename) AND (table_schema = @dbname)
   AND (column_name = @columnname)) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

CREATE INDEX IF NOT EXISTS idx_demands_service_type ON demands(service_type_id);

-- Tabela: patient_assignments
SET @tablename = 'patient_assignments';
SET @preparedStatement = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE (table_name = @tablename) AND (table_schema = @dbname)
   AND (column_name = @columnname)) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

CREATE INDEX IF NOT EXISTS idx_assignments_service_type ON patient_assignments(service_type_id);

-- ============================================================================
-- VERIFICAÇÃO
-- ============================================================================

-- Ver serviços genéricos
SELECT 'SERVIÇOS GENÉRICOS:' AS info;
SELECT * FROM service_types ORDER BY display_order;

-- Ver valores por especialidade
SELECT 'VALORES POR ESPECIALIDADE:' AS info;
SELECT 
    s.name AS especialidade,
    st.name AS servico,
    ssv.base_value AS valor,
    ssv.status
FROM specialty_service_values ssv
JOIN specialties s ON s.id = ssv.specialty_id
JOIN service_types st ON st.id = ssv.service_type_id
ORDER BY s.name, st.display_order;

-- Contar valores por especialidade
SELECT 
    s.name AS especialidade,
    COUNT(ssv.id) AS total_servicos
FROM specialties s
LEFT JOIN specialty_service_values ssv ON ssv.specialty_id = s.id
GROUP BY s.id, s.name
ORDER BY s.name;

-- ============================================================================
-- EXEMPLO DE CONSULTA PARA BUSCAR VALOR
-- ============================================================================
-- Para buscar o valor de um serviço específico de uma especialidade:
-- 
-- SELECT ssv.base_value
-- FROM specialty_service_values ssv
-- WHERE ssv.specialty_id = ? AND ssv.service_type_id = ? AND ssv.status = 'active';
-- ============================================================================
