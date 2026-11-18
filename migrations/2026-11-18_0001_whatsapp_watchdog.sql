-- Migration: watchdog de conexão real da Evolution + trava de número duplicado
-- Data: 2026-11-18
--
-- O código também cria esta tabela sozinho (wa_watchdog_ensure_schema em
-- app/whatsapp_watchdog.php), então rodar este SQL é opcional.
--
-- whatsapp_instance_monitor guarda, por instância do sistema:
--  - o último veredito do watchdog (healthy, silent, awaiting_scan, dormant...)
--  - o controle do fluxo automático (1 reconexão automática, alerta humano)
--  - a marca de "QR Code gerado pelo sistema" usada para barrar o mesmo número
--    conectado em duas instâncias.
-- A linha "__watchdog__" guarda o estado do próprio watchdog (no ar / fora do ar).

CREATE TABLE IF NOT EXISTS whatsapp_instance_monitor (
    instance_name VARCHAR(100) NOT NULL,
    verdict VARCHAR(20) NULL COMMENT 'healthy | silent | warming_up | awaiting_scan | dormant | unknown | ok | down',
    evo_state VARCHAR(20) NULL COMMENT 'open | close | connecting (estado na Evolution)',
    last_event_seconds INT NULL COMMENT 'segundos desde o último evento (lastEventSecondsAgo)',
    last_heal_seconds INT NULL COMMENT 'segundos desde o último heal (lastHealSecondsAgo)',
    checked_at DATETIME NULL COMMENT 'quando o veredito foi lido do watchdog',
    verdict_since DATETIME NULL COMMENT 'desde quando está neste veredito',
    auto_heal_at DATETIME NULL COMMENT 'reconexão (heal) feita neste episódio',
    alert_kind VARCHAR(30) NULL COMMENT 'último alerta humano enviado neste episódio',
    alert_sent_at DATETIME NULL,
    actions_at DATETIME NULL COMMENT 'linha __watchdog__: última rodada do fluxo automático',
    fail_at DATETIME NULL COMMENT 'linha __watchdog__: última falha ao consultar o watchdog',
    pairing_requested_at DATETIME NULL COMMENT 'QR Code gerado pelo sistema (confere número duplicado ao conectar)',
    duplicate_note VARCHAR(500) NULL COMMENT 'motivo da última conexão desfeita por número duplicado',
    duplicate_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (instance_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Endereço do watchdog e reconexão automática ligada. O token é preenchido em
-- Configurações → WhatsApp Conexão → Credenciais e nunca vai para o Git.
-- (Sem ponto e vírgula nos comentários: run_pending_migrations.php divide o
-- arquivo por esse caractere.)
INSERT INTO admin_settings (setting_key, setting_value)
VALUES ('evolution.watchdog_url', 'http://177.104.185.7:8083')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

INSERT INTO admin_settings (setting_key, setting_value)
VALUES ('evolution.watchdog_auto_heal', '1')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Conferência:
-- SELECT * FROM whatsapp_instance_monitor ORDER BY instance_name
