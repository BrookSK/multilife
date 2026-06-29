-- Migration: Ajustes operacionais - Relatório de Entrega Jun/2026
-- Data: 2026-06-29
-- Referência: Destacamentos e Pontuações do Relatório Consolidado

-- =============================================================================
-- 1. COMUNICAÇÃO E CAPTAÇÃO: Adicionar campos de endereço na demanda
-- =============================================================================

ALTER TABLE demands
ADD COLUMN IF NOT EXISTS location_street VARCHAR(200) NULL COMMENT 'Rua/Logradouro do local de atendimento' AFTER location_state,
ADD COLUMN IF NOT EXISTS location_neighborhood VARCHAR(120) NULL COMMENT 'Bairro do local de atendimento' AFTER location_street,
ADD COLUMN IF NOT EXISTS location_number VARCHAR(20) NULL COMMENT 'Número' AFTER location_neighborhood,
ADD COLUMN IF NOT EXISTS location_complement VARCHAR(120) NULL COMMENT 'Complemento' AFTER location_number;

ALTER TABLE demand_sub_requests
ADD COLUMN IF NOT EXISTS location_street VARCHAR(200) NULL COMMENT 'Rua/Logradouro' AFTER location_state,
ADD COLUMN IF NOT EXISTS location_neighborhood VARCHAR(120) NULL COMMENT 'Bairro' AFTER location_street,
ADD COLUMN IF NOT EXISTS location_number VARCHAR(20) NULL COMMENT 'Número' AFTER location_neighborhood,
ADD COLUMN IF NOT EXISTS location_complement VARCHAR(120) NULL COMMENT 'Complemento' AFTER location_number;

-- =============================================================================
-- 2. ESTRUTURA DOS GRUPOS: Campo para indicar grupo fechado (announcement mode)
-- =============================================================================

ALTER TABLE whatsapp_groups
ADD COLUMN IF NOT EXISTS is_announcement TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Somente admins publicam (fechado)' AFTER status,
ADD COLUMN IF NOT EXISTS allow_reactions TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Permite reações dos membros' AFTER is_announcement;

-- =============================================================================
-- 3. TABELA DE PADRONIZAÇÃO DE FREQUÊNCIA / DIAS DA SEMANA
-- Regra: 1x=Qua, 2x=Ter+Qui, 3x=Seg+Qua+Sex, 4x=Seg+Ter+Qua+Qui, etc.
-- =============================================================================

CREATE TABLE IF NOT EXISTS frequency_weekday_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    frequency_code VARCHAR(30) NOT NULL COMMENT 'Código: 1x_semana, 2x_semana, ..., quinzenal, mensal',
    frequency_label VARCHAR(60) NOT NULL COMMENT 'Label amigável: 1x/Semana, 2x/Semana, etc.',
    sessions_per_week INT NOT NULL DEFAULT 1 COMMENT 'Número de sessões por semana',
    weekdays JSON NOT NULL COMMENT 'Array de dias da semana [1=Seg..7=Dom]',
    weekday_labels VARCHAR(120) NOT NULL COMMENT 'Labels: 4ª Feira, 3ª e 5ª Feira, etc.',
    count_rule VARCHAR(200) NOT NULL COMMENT 'Regra de contagem mensal: Conta quantas X tem o mês',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_freq_code (frequency_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO frequency_weekday_rules (frequency_code, frequency_label, sessions_per_week, weekdays, weekday_labels, count_rule, sort_order) VALUES
('1x_semana', '1x/Semana', 1, '[3]', '4ª Feira', 'Conta quantas quartas tem o mês', 1),
('2x_semana', '2x/Semana', 2, '[2,4]', '3ª e 5ª Feira', 'Conta quantas terças e quintas tem o mês', 2),
('3x_semana', '3x/Semana', 3, '[1,3,5]', '2ª, 4ª e 6ª Feira', 'Conta quantas segundas, quartas e sextas tem o mês', 3),
('4x_semana', '4x/Semana', 4, '[1,2,3,4]', '2ª, 3ª, 4ª e 5ª Feira', 'Conta quantas segundas, terças, quartas e quintas tem o mês', 4),
('5x_semana', '5x/Semana', 5, '[1,2,3,4,5]', '2ª, 3ª, 4ª, 5ª e 6ª Feira', 'Conta quantas seg a sex tem o mês', 5),
('6x_semana', '6x/Semana', 6, '[1,2,3,4,5,6]', '2ª, 3ª, 4ª, 5ª, 6ª Feira e Sábado', 'Conta seg a sáb no mês', 6),
('7x_semana', '7x/Semana (Diário)', 7, '[1,2,3,4,5,6,7]', 'Diário', 'Conta todos os dias do mês', 7),
('quinzenal', 'Quinzenal', 0, '[]', '2x/Mês', '1 atendimento na 1ª quinzena + 1 na 2ª quinzena do mês', 8),
('mensal', 'Mensal', 0, '[]', '1x/Mês', '1 atendimento por mês', 9)
ON DUPLICATE KEY UPDATE frequency_label = VALUES(frequency_label);

-- =============================================================================
-- 4. SELEÇÃO ANÔNIMA DE PROFISSIONAIS: Campo para controlar visibilidade
-- =============================================================================

ALTER TABLE demand_interested_professionals
ADD COLUMN IF NOT EXISTS identity_revealed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=dados ocultos durante seleção, 1=revelado após contratação' AFTER status;

-- =============================================================================
-- 5. AUTO-ARQUIVAMENTO: Configuração de timeout para arquivar demandas sem retorno
-- =============================================================================

ALTER TABLE demands
ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL COMMENT 'Data de arquivamento automático' AFTER cancelled_at,
ADD COLUMN IF NOT EXISTS archived_reason VARCHAR(200) NULL COMMENT 'Motivo do arquivamento' AFTER archived_at;

-- Configurações para auto-arquivamento
INSERT INTO admin_settings (setting_key, setting_value) VALUES
('demands.auto_archive_days', '7'),
('demands.auto_archive_enabled', '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- =============================================================================
-- 6. CONTROLE FINANCEIRO: Permissões mais granulares
-- =============================================================================

INSERT INTO permissions (name, slug) VALUES
('Visualizar financeiro (somente leitura)', 'finance.view')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- =============================================================================
-- 7. ELEGIBILIDADE DE ATENDIMENTOS
-- =============================================================================

CREATE TABLE IF NOT EXISTS attendance_eligibility_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    health_insurer_id INT UNSIGNED NULL COMMENT 'NULL = regra global para todas operadoras',
    specialty VARCHAR(120) NULL COMMENT 'NULL = regra para todas especialidades',
    frequency_code VARCHAR(30) NOT NULL COMMENT 'Referência à frequency_weekday_rules',
    max_sessions_per_month INT NULL COMMENT 'Máximo de sessões por mês (se aplicável)',
    min_interval_days INT NULL COMMENT 'Intervalo mínimo entre sessões (dias)',
    requires_authorization TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=Precisa autorização prévia',
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_eligibility_insurer (health_insurer_id),
    INDEX idx_eligibility_specialty (specialty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
