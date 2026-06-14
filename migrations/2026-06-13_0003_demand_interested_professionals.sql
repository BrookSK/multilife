-- Migration: Tabela de profissionais interessados (lista de espera via reação WhatsApp)
-- Data: 2026-06-13

-- Tabela para armazenar profissionais que reagiram às mensagens de captação
CREATE TABLE IF NOT EXISTS demand_interested_professionals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    demand_id BIGINT UNSIGNED NOT NULL,
    dispatch_log_id BIGINT UNSIGNED NOT NULL,
    phone VARCHAR(30) NOT NULL COMMENT 'Telefone do profissional que reagiu',
    phone_jid VARCHAR(100) NOT NULL COMMENT 'JID completo para envio (@s.whatsapp.net)',
    push_name VARCHAR(255) DEFAULT NULL COMMENT 'Nome exibido no WhatsApp',
    user_id INT UNSIGNED DEFAULT NULL COMMENT 'ID do profissional no sistema (se identificado)',
    emoji VARCHAR(20) DEFAULT NULL COMMENT 'Emoji usado na reação',
    status ENUM('interested', 'selected', 'rejected') NOT NULL DEFAULT 'interested' COMMENT 'interested=reagiu, selected=foi o escolhido, rejected=não foi escolhido',
    notified_rejection TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=já foi notificado que não foi selecionado',
    reacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    selected_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_dip_demand (demand_id),
    INDEX idx_dip_phone (phone),
    INDEX idx_dip_status (status),
    UNIQUE INDEX idx_dip_unique (demand_id, phone),
    CONSTRAINT fk_dip_demand FOREIGN KEY (demand_id) REFERENCES demands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
