-- Tabela para armazenar mensagens do chat
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    remote_jid VARCHAR(100) NOT NULL COMMENT 'Número com @s.whatsapp.net ou @g.us',
    message_text TEXT NOT NULL,
    from_me TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = enviada por mim, 0 = recebida',
    message_timestamp INT UNSIGNED NOT NULL COMMENT 'Unix timestamp',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_remote_jid (remote_jid),
    INDEX idx_timestamp (message_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
