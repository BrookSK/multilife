-- Tabela para armazenar contatos/chats ativos
CREATE TABLE IF NOT EXISTS chat_contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    remote_jid VARCHAR(100) NOT NULL UNIQUE COMMENT 'Número com @s.whatsapp.net ou @g.us',
    contact_name VARCHAR(255) DEFAULT NULL COMMENT 'Nome do contato',
    profile_picture_url TEXT DEFAULT NULL COMMENT 'URL da foto de perfil',
    is_group TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = grupo, 0 = conversa privada',
    last_message_timestamp INT UNSIGNED DEFAULT NULL COMMENT 'Timestamp da última mensagem',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_remote_jid (remote_jid),
    INDEX idx_last_message (last_message_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
