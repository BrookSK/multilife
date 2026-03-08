-- Migration: Adicionar coluna color à tabela cost_centers existente
-- Data: 2026-03-08
-- Descrição: Adicionar campo de cor para personalização visual dos centros de custo

-- Adicionar coluna color se não existir
ALTER TABLE cost_centers 
ADD COLUMN IF NOT EXISTS color VARCHAR(7) DEFAULT '#3b82f6' AFTER description;

-- Atualizar cores dos centros de custo existentes
UPDATE cost_centers SET color = '#3b82f6' WHERE name = 'Administrativo';
UPDATE cost_centers SET color = '#10b981' WHERE name = 'Clínico';
UPDATE cost_centers SET color = '#f59e0b' WHERE name = 'Marketing';
UPDATE cost_centers SET color = '#8b5cf6' WHERE name = 'Infraestrutura';
UPDATE cost_centers SET color = '#ec4899' WHERE name = 'Recursos Humanos';

-- Adicionar centro de custo "Fluxo Operacional" se não existir
INSERT INTO cost_centers (name, description, color, is_active) 
VALUES ('Fluxo Operacional', 'Receitas e despesas operacionais do sistema de captação', '#10b981', 1)
ON DUPLICATE KEY UPDATE description = VALUES(description), color = VALUES(color);

-- Adicionar índice na coluna cost_center da tabela financial_entries (se ainda não existir)
-- Isso melhora a performance de queries com filtro por centro de custo
ALTER TABLE financial_entries 
ADD INDEX IF NOT EXISTS idx_cost_center (cost_center);
