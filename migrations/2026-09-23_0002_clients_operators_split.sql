-- =====================================================================
-- REFACTOR: Separação Cliente x Operadora + dias de fechamento
-- =====================================================================
-- Contexto:
--   Hoje health_insurers = "Operadora / Cliente" (uma coisa só).
--   Passamos a ter:
--     - clients      : o CONTRATANTE/pagador (ex.: GLOBAL), com dia de entrega.
--     - health_insurers : a OPERADORA/convênio (ex.: BRADESCO), vinculada a um
--                         cliente (client_id) e com seu próprio dia.
--   Identificação SEMPRE por id (nomes podem repetir).
--
--   Competência = mês-calendário (dia 1 ao último). O closing_day é o
--   "dia de entrega/conferência ao financeiro" (prazo), não altera o período.
--
--   Fluxo: fecha operadora por operadora; quando todas as operadoras do
--   cliente estão fechadas, o cliente é consolidado e enviado ao financeiro.
-- =====================================================================

-- 1) Tabela de CLIENTES (contratante/pagador)
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    cnpj VARCHAR(18) NULL,
    contact_phone VARCHAR(20) NULL,
    contact_email VARCHAR(255) NULL,
    billing_email VARCHAR(255) NULL,
    email_domain VARCHAR(255) NULL COMMENT 'Domínio de e-mail para auto-detecção do cliente',
    closing_day TINYINT UNSIGNED NULL COMMENT 'Dia de entrega/conferência ao financeiro (1-31)',
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_clients_active (is_active),
    KEY idx_clients_email_domain (email_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) OPERADORA (health_insurers) passa a pertencer a um cliente + ter dia próprio
ALTER TABLE health_insurers
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL COMMENT 'Cliente/contratante ao qual a operadora pertence' AFTER id;
ALTER TABLE health_insurers
    ADD COLUMN IF NOT EXISTS closing_day TINYINT UNSIGNED NULL COMMENT 'Dia de entrega/conferência da operadora (1-31)';
ALTER TABLE health_insurers
    ADD INDEX IF NOT EXISTS idx_hi_client (client_id);

-- 3) MIGRAÇÃO: cada health_insurer atual vira também um CLIENTE, e a própria
--    operadora fica vinculada a esse cliente (retrocompatível: nada quebra,
--    o usuário reorganiza os cadastros depois).
INSERT INTO clients (name, cnpj, contact_phone, contact_email, billing_email, email_domain, is_active, created_at)
SELECT hi.name, hi.cnpj, hi.contact_phone, hi.contact_email, hi.billing_email, hi.email_domain,
       COALESCE(hi.is_active, 1), NOW()
FROM health_insurers hi
WHERE NOT EXISTS (
    -- evita duplicar se a migration rodar de novo (por nome + domínio)
    SELECT 1 FROM clients c WHERE c.name = hi.name
        AND (c.email_domain <=> hi.email_domain)
);

-- Vincular cada operadora ao cliente correspondente (match por nome+domínio).
UPDATE health_insurers hi
JOIN clients c ON c.name = hi.name AND (c.email_domain <=> hi.email_domain)
SET hi.client_id = c.id
WHERE hi.client_id IS NULL;

-- 4) Propagar a dimensão CLIENTE até a fonte do fechamento e financeiro.
ALTER TABLE patient_assignments
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL COMMENT 'Cliente/contratante' AFTER health_insurer_id;
ALTER TABLE patient_assignments
    ADD INDEX IF NOT EXISTS idx_pa_client (client_id);

ALTER TABLE demands
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL COMMENT 'Cliente detectado/atribuído';
ALTER TABLE demands
    ADD COLUMN IF NOT EXISTS health_insurer_id INT UNSIGNED NULL COMMENT 'Operadora detectada/atribuída';

ALTER TABLE financial_entries
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL;
ALTER TABLE financial_entries
    ADD COLUMN IF NOT EXISTS health_insurer_id INT UNSIGNED NULL;

ALTER TABLE billing_monthly_closure_items
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL;

-- Backfill: client_id em patient_assignments a partir da operadora já gravada.
UPDATE patient_assignments pa
JOIN health_insurers hi ON hi.id = pa.health_insurer_id
SET pa.client_id = hi.client_id
WHERE pa.client_id IS NULL AND hi.client_id IS NOT NULL;

-- 5) Evoluir billing_monthly_closures para fechamento por OPERADORA e por CLIENTE.
--    scope: 'global' (legado), 'operator' (uma operadora), 'client' (consolida cliente).
ALTER TABLE billing_monthly_closures
    ADD COLUMN IF NOT EXISTS scope ENUM('global','operator','client') NOT NULL DEFAULT 'global' AFTER reference_month;
ALTER TABLE billing_monthly_closures
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL AFTER scope;
ALTER TABLE billing_monthly_closures
    ADD COLUMN IF NOT EXISTS health_insurer_id INT UNSIGNED NULL AFTER client_id;
-- Status estendido do ciclo. Mantém compatibilidade com 'closed'/'reversed'.
ALTER TABLE billing_monthly_closures
    MODIFY COLUMN status ENUM('closed','reversed','operator_closed','client_closed','sent_to_finance') NOT NULL DEFAULT 'closed';
ALTER TABLE billing_monthly_closures
    ADD COLUMN IF NOT EXISTS sent_to_finance_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS sent_to_finance_by_user_id INT UNSIGNED NULL;

-- A UNIQUE antiga é por reference_month (competência única global). Como agora
-- pode haver vários fechamentos por mês (um por operadora + um por cliente),
-- removemos a unicidade global e criamos uma por (mês, escopo, cliente, operadora).
-- Observação: DROP INDEX pode falhar se o nome divergir; envolto em procedure segura.
DROP PROCEDURE IF EXISTS _mlife_fix_bmc_unique;
DELIMITER //
CREATE PROCEDURE _mlife_fix_bmc_unique()
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'billing_monthly_closures'
               AND index_name = 'uk_bmc_reference_month') THEN
        ALTER TABLE billing_monthly_closures DROP INDEX uk_bmc_reference_month;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'billing_monthly_closures'
               AND index_name = 'uk_bmc_scope') THEN
        ALTER TABLE billing_monthly_closures
            ADD UNIQUE KEY uk_bmc_scope (reference_month, scope, client_id, health_insurer_id);
    END IF;
END //
DELIMITER ;
CALL _mlife_fix_bmc_unique();
DROP PROCEDURE IF EXISTS _mlife_fix_bmc_unique;
