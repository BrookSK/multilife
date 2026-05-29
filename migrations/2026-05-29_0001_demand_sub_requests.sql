-- Migration: Tabela para armazenar múltiplas solicitações identificadas em um único e-mail
-- Data: 2026-05-29
-- Descrição: Quando um e-mail contém mais de uma solicitação (ex: psicóloga + fisioterapeuta + fonoaudióloga),
--            cada solicitação é armazenada separadamente para permitir captação individual.

CREATE TABLE IF NOT EXISTS demand_sub_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  demand_id BIGINT UNSIGNED NOT NULL COMMENT 'Demanda pai (card criado a partir do e-mail)',
  specialty VARCHAR(120) NOT NULL COMMENT 'Especialidade desta sub-solicitação',
  description TEXT NULL COMMENT 'Descrição específica desta sub-solicitação',
  location_city VARCHAR(120) NULL,
  location_state CHAR(2) NULL,
  procedure_value DECIMAL(10,2) NULL COMMENT 'Valor específico desta sub-solicitação',
  urgency VARCHAR(20) NULL COMMENT 'urgente, normal, baixa',
  status ENUM('pendente','em_captacao','concluido','cancelado') NOT NULL DEFAULT 'pendente',
  dispatched_at DATETIME NULL COMMENT 'Quando a captação foi disparada para esta sub-solicitação',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_demand_sub_requests_demand_id (demand_id),
  KEY idx_demand_sub_requests_status (status),
  CONSTRAINT fk_demand_sub_requests_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flag na demanda para indicar que tem múltiplas solicitações
ALTER TABLE demands
ADD COLUMN has_multiple_requests TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 se o e-mail continha múltiplas solicitações' AFTER urgency;
