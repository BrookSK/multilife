-- Migration: Suporte a reações, respostas (quoted), menções e stickers no chat
-- Data: 2026-06-13

-- Adicionar colunas para mensagens citadas (replies/quoted)
ALTER TABLE chat_messages
ADD COLUMN IF NOT EXISTS quoted_message_id VARCHAR(255) DEFAULT NULL COMMENT 'ID da mensagem original que está sendo respondida' AFTER thumbnail_url,
ADD COLUMN IF NOT EXISTS quoted_message_text TEXT DEFAULT NULL COMMENT 'Texto da mensagem original citada' AFTER quoted_message_id,
ADD COLUMN IF NOT EXISTS quoted_message_sender VARCHAR(100) DEFAULT NULL COMMENT 'JID de quem enviou a mensagem original' AFTER quoted_message_text;

-- Adicionar coluna para menções (@)
ALTER TABLE chat_messages
ADD COLUMN IF NOT EXISTS mentioned_jids TEXT DEFAULT NULL COMMENT 'JSON array com JIDs mencionados na mensagem' AFTER quoted_message_sender;

-- Adicionar coluna para nome do remetente em grupos
ALTER TABLE chat_messages
ADD COLUMN IF NOT EXISTS sender_name VARCHAR(255) DEFAULT NULL COMMENT 'Nome/pushName do remetente (útil em grupos)' AFTER mentioned_jids,
ADD COLUMN IF NOT EXISTS participant_jid VARCHAR(100) DEFAULT NULL COMMENT 'JID do participante real em grupos' AFTER sender_name;

-- Adicionar coluna para ID externo da mensagem (para vincular reações)
ALTER TABLE chat_messages
ADD COLUMN IF NOT EXISTS external_message_id VARCHAR(255) DEFAULT NULL COMMENT 'ID da mensagem no WhatsApp (key.id)' AFTER participant_jid;

-- Criar tabela de reações
CREATE TABLE IF NOT EXISTS chat_reactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    remote_jid VARCHAR(100) NOT NULL COMMENT 'Chat onde a reação ocorreu',
    message_id VARCHAR(255) NOT NULL COMMENT 'ID da mensagem que recebeu reação',
    reactor_jid VARCHAR(100) NOT NULL COMMENT 'Quem reagiu',
    emoji VARCHAR(20) NOT NULL COMMENT 'Emoji da reação',
    reaction_timestamp INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_message_id (message_id),
    INDEX idx_remote_jid (remote_jid),
    UNIQUE INDEX idx_unique_reaction (message_id, reactor_jid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índice para buscar mensagem por external_message_id
CREATE INDEX IF NOT EXISTS idx_external_message_id ON chat_messages(external_message_id);

-- Suporte a sticker como tipo de mensagem
-- (já usa message_type VARCHAR(20), basta aceitar 'sticker' como valor)
