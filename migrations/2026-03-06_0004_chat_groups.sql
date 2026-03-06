-- Tabela para armazenar metadados dos grupos
CREATE TABLE IF NOT EXISTS chat_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_jid VARCHAR(100) NOT NULL UNIQUE COMMENT 'ID do grupo com @g.us',
    group_name VARCHAR(255) NOT NULL COMMENT 'Nome do grupo',
    group_description TEXT DEFAULT NULL COMMENT 'Descrição do grupo',
    group_picture_url TEXT DEFAULT NULL COMMENT 'URL da foto do grupo',
    specialty VARCHAR(100) DEFAULT NULL COMMENT 'Especialidade médica do grupo',
    region VARCHAR(100) DEFAULT NULL COMMENT 'Região do grupo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_group_jid (group_jid),
    INDEX idx_specialty (specialty),
    INDEX idx_region (region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para armazenar participantes dos grupos
CREATE TABLE IF NOT EXISTS chat_group_participants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_jid VARCHAR(100) NOT NULL COMMENT 'ID do grupo',
    participant_jid VARCHAR(100) NOT NULL COMMENT 'ID do participante',
    participant_name VARCHAR(255) DEFAULT NULL COMMENT 'Nome do participante',
    is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = admin, 0 = membro',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_group_participant (group_jid, participant_jid),
    INDEX idx_group (group_jid),
    INDEX idx_participant (participant_jid),
    FOREIGN KEY (group_jid) REFERENCES chat_groups(group_jid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
