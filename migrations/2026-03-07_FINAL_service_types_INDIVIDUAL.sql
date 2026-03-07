-- ============================================================================
-- MIGRATION: Sistema de Serviços Individuais por Especialidade
-- Data: 2026-03-07
-- Descrição: Cada especialidade cria seus próprios serviços do zero
-- ============================================================================

-- Criar tabela de serviços (cada especialidade tem seus próprios)
DROP TABLE IF EXISTS specialty_services;

CREATE TABLE specialty_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    specialty_id INT NOT NULL,
    service_name VARCHAR(100) NOT NULL,
    description TEXT,
    base_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_specialty_id (specialty_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT = 'Serviços individuais por especialidade - cada especialidade cria os seus';

-- Adicionar colunas nas tabelas relacionadas (verificando se já existem)

-- Tabela: professional_applications
SET @dbname = DATABASE();
SET @tablename = 'professional_applications';
SET @columnname = 'specialty_service_id';
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

CREATE INDEX IF NOT EXISTS idx_prof_apps_specialty_service ON professional_applications(specialty_service_id);

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

CREATE INDEX IF NOT EXISTS idx_users_specialty_service ON users(specialty_service_id);

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

CREATE INDEX IF NOT EXISTS idx_demands_specialty_service ON demands(specialty_service_id);

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

CREATE INDEX IF NOT EXISTS idx_assignments_specialty_service ON patient_assignments(specialty_service_id);

-- ============================================================================
-- VERIFICAÇÃO
-- ============================================================================

-- Ver serviços por especialidade
SELECT 
    s.name AS especialidade,
    ss.service_name AS servico,
    ss.base_value AS valor_base,
    ss.status
FROM specialty_services ss
JOIN specialties s ON s.id = ss.specialty_id
ORDER BY s.name, ss.display_order, ss.service_name;

-- Contar serviços por especialidade
SELECT 
    s.name AS especialidade,
    COUNT(ss.id) AS total_servicos
FROM specialties s
LEFT JOIN specialty_services ss ON ss.specialty_id = s.id
GROUP BY s.id, s.name
ORDER BY s.name;
