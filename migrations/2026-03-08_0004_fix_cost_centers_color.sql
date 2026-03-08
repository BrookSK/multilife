-- Migration: Adicionar coluna color e atualizar centros de custo
-- Data: 2026-03-08
-- Descrição: Adicionar campo color à tabela cost_centers existente e inserir "Fluxo Operacional"

-- ETAPA 1: Adicionar coluna color se não existir
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'cost_centers' 
  AND COLUMN_NAME = 'color';

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE cost_centers ADD COLUMN color VARCHAR(7) DEFAULT ''#3b82f6'' AFTER description',
    'SELECT ''Coluna color já existe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ETAPA 2: Atualizar cores dos centros existentes
UPDATE cost_centers SET color = '#3b82f6' WHERE name = 'Administrativo' AND (color IS NULL OR color = '#3b82f6');
UPDATE cost_centers SET color = '#10b981' WHERE name = 'Clínico' AND (color IS NULL OR color = '#3b82f6');
UPDATE cost_centers SET color = '#f59e0b' WHERE name = 'Marketing' AND (color IS NULL OR color = '#3b82f6');
UPDATE cost_centers SET color = '#8b5cf6' WHERE name = 'Infraestrutura' AND (color IS NULL OR color = '#3b82f6');
UPDATE cost_centers SET color = '#ec4899' WHERE name = 'Recursos Humanos' AND (color IS NULL OR color = '#3b82f6');

-- ETAPA 3: Adicionar "Fluxo Operacional" se não existir
INSERT INTO cost_centers (name, description, color, is_active) 
VALUES ('Fluxo Operacional', 'Receitas e despesas operacionais do sistema de captação', '#10b981', 1)
ON DUPLICATE KEY UPDATE 
    description = VALUES(description), 
    color = VALUES(color);

-- ETAPA 4: Adicionar índice se não existir
SET @idx_exists = 0;
SELECT COUNT(*) INTO @idx_exists 
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'financial_entries' 
  AND INDEX_NAME = 'idx_cost_center';

SET @sql = IF(@idx_exists = 0, 
    'ALTER TABLE financial_entries ADD INDEX idx_cost_center (cost_center)',
    'SELECT ''Índice idx_cost_center já existe'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
