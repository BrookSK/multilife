-- Migration: Sistema de Centro de Custos
-- Data: 2026-03-08
-- Descrição: Criar tabela de centros de custo para organização financeira

-- Criar tabela de centros de custo
CREATE TABLE IF NOT EXISTS cost_centers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    color VARCHAR(7) DEFAULT '#3b82f6',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir centros de custo padrão
INSERT INTO cost_centers (name, description, color, is_active) VALUES
('Fluxo Operacional', 'Receitas e despesas operacionais do sistema de captação', '#10b981', 1),
('Administrativo', 'Despesas administrativas e gerais', '#3b82f6', 1),
('Marketing', 'Investimentos em marketing e divulgação', '#f59e0b', 1),
('Infraestrutura', 'Custos com infraestrutura e tecnologia', '#8b5cf6', 1),
('Recursos Humanos', 'Folha de pagamento e benefícios', '#ec4899', 1);

-- Adicionar índice na coluna cost_center da tabela financial_entries (se ainda não existir)
-- Isso melhora a performance de queries com filtro por centro de custo
ALTER TABLE financial_entries 
ADD INDEX idx_cost_center (cost_center);
