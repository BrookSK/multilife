-- Migration: Fila de COBRANÇA MANUAL de captação (follow-up) com envio espaçado
-- Data: 2026-09-18
--
-- Contexto (reunião 15/09): quando ninguém reage à captação no grupo, a equipe
-- seleciona profissionais e dispara uma COBRANÇA no privado — mas com envio
-- ESPAÇADO (lote pequeno + intervalo entre lotes) e mensagem gerada por IA
-- (uma variação por profissional) para reduzir risco de bloqueio do WhatsApp.
--
-- O envio NÃO ocorre no request do navegador: os itens entram numa FILA e um
-- cron (cron/demand_followup_dispatch.php) processa em lotes ao longo do tempo.

-- Lote de cobrança (um "disparo" de cobrança criado pela atendente).
CREATE TABLE IF NOT EXISTS demand_followup_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    demand_id BIGINT UNSIGNED NOT NULL,
    dispatch_log_id BIGINT UNSIGNED NULL COMMENT 'Disparo/grupo de origem, se aplicável',
    instance_name VARCHAR(100) NULL COMMENT 'Instância WhatsApp usada no envio',
    created_by_user_id INT UNSIGNED NULL,
    status ENUM('queued','processing','done','cancelled') NOT NULL DEFAULT 'queued',
    total_items INT UNSIGNED NOT NULL DEFAULT 0,
    sent_items INT UNSIGNED NOT NULL DEFAULT 0,
    failed_items INT UNSIGNED NOT NULL DEFAULT 0,
    last_batch_sent_at DATETIME NULL COMMENT 'Quando o último lote (2 msgs) foi enviado; base do intervalo',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dfb_demand (demand_id),
    KEY idx_dfb_status (status),
    CONSTRAINT fk_dfb_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item da fila = uma cobrança a um profissional específico.
CREATE TABLE IF NOT EXISTS demand_followup_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    demand_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL COMMENT 'Profissional no sistema, se identificado',
    phone VARCHAR(30) NOT NULL,
    phone_jid VARCHAR(100) NOT NULL COMMENT 'JID de envio (@s.whatsapp.net)',
    push_name VARCHAR(255) NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    generated_message TEXT NULL COMMENT 'Mensagem gerada por IA (ou fallback) efetivamente enviada',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(255) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dfi_batch (batch_id),
    KEY idx_dfi_status (status),
    -- Anti-repetição: não cobrar o mesmo profissional duas vezes na mesma demanda.
    UNIQUE KEY uk_dfi_demand_phone (demand_id, phone),
    CONSTRAINT fk_dfi_batch FOREIGN KEY (batch_id) REFERENCES demand_followup_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dfi_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de INDICAÇÃO de outro profissional (quando o cobrado indica alguém).
CREATE TABLE IF NOT EXISTS demand_referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    demand_id BIGINT UNSIGNED NOT NULL,
    referrer_user_id INT UNSIGNED NULL COMMENT 'Quem indicou (se identificado)',
    referrer_phone VARCHAR(30) NULL,
    referred_name VARCHAR(255) NULL,
    referred_phone VARCHAR(30) NULL,
    referred_specialty VARCHAR(120) NULL,
    note TEXT NULL,
    status ENUM('new','contacted','discarded') NOT NULL DEFAULT 'new',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dref_demand (demand_id),
    CONSTRAINT fk_dref_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurações padrão (anti-bloqueio). Ajustáveis depois em admin_settings.
INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (SELECT 'demand_followup.batch_size' AS k, '2' AS v) t
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'demand_followup.batch_size');

INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (SELECT 'demand_followup.interval_minutes' AS k, '5' AS v) t
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'demand_followup.interval_minutes');

INSERT INTO admin_settings (setting_key, setting_value)
SELECT * FROM (SELECT 'demand_followup.per_message_delay_ms' AS k, '1200' AS v) t
WHERE NOT EXISTS (SELECT 1 FROM admin_settings WHERE setting_key = 'demand_followup.per_message_delay_ms');
