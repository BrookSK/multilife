-- Migration: POLO (cliente -> polo -> operadora) + dados editáveis do atendimento.
-- Data: 2026-11-11
--
-- Modelo: o CLIENTE (ex.: GLOBAL) tem vários POLOS (ex.: Rio Preto). A OPERADORA
-- (ex.: Unimed) pertence ao cliente e pode ter um polo padrão, mas o polo é
-- gravado em cada demanda/atendimento (a mesma operadora pode atender vários polos).
--
-- Também:
--   - patient_assignments.internal_notes : observação interna (não vai ao profissional)
--   - patient_assignments.weaning_at     : data do desmame
--
-- Idempotente (MariaDB com ADD COLUMN IF NOT EXISTS). O código também cria essas
-- estruturas sozinho (clients_ensure_schema) se a migration não for rodada.

CREATE TABLE IF NOT EXISTS polos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  city VARCHAR(120) NULL,
  state CHAR(2) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_polos_client (client_id),
  KEY idx_polos_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE health_insurers
  ADD COLUMN IF NOT EXISTS polo_id INT UNSIGNED NULL COMMENT 'Polo padrão da operadora';

ALTER TABLE demands
  ADD COLUMN IF NOT EXISTS polo_id INT UNSIGNED NULL;

ALTER TABLE patient_assignments
  ADD COLUMN IF NOT EXISTS polo_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS internal_notes TEXT NULL COMMENT 'Observação interna do atendimento',
  ADD COLUMN IF NOT EXISTS weaning_at DATE NULL COMMENT 'Data do desmame';

ALTER TABLE financial_entries
  ADD COLUMN IF NOT EXISTS polo_id INT UNSIGNED NULL;

ALTER TABLE billing_monthly_closure_items
  ADD COLUMN IF NOT EXISTS polo_id INT UNSIGNED NULL;

CREATE INDEX IF NOT EXISTS idx_pa_polo ON patient_assignments (polo_id);
CREATE INDEX IF NOT EXISTS idx_demands_polo ON demands (polo_id);

-- Conferência:
-- SELECT c.name AS cliente, p.name AS polo, p.city, p.state FROM polos p LEFT JOIN clients c ON c.id = p.client_id ORDER BY c.name, p.name;
