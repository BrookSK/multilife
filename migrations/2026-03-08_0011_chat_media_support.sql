-- Migration: Adicionar suporte a mídias no chat
-- Data: 2026-03-08

-- Adicionar colunas para suporte a mídia na tabela chat_messages
ALTER TABLE chat_messages 
ADD COLUMN IF NOT EXISTS message_type VARCHAR(20) DEFAULT 'text' AFTER message_text,
ADD COLUMN IF NOT EXISTS media_url TEXT DEFAULT NULL AFTER message_type,
ADD COLUMN IF NOT EXISTS media_mime_type VARCHAR(100) DEFAULT NULL AFTER media_url,
ADD COLUMN IF NOT EXISTS media_filename VARCHAR(255) DEFAULT NULL AFTER media_mime_type,
ADD COLUMN IF NOT EXISTS media_size INT UNSIGNED DEFAULT NULL AFTER media_filename,
ADD COLUMN IF NOT EXISTS audio_transcription TEXT DEFAULT NULL AFTER media_size,
ADD COLUMN IF NOT EXISTS thumbnail_url TEXT DEFAULT NULL AFTER audio_transcription;

-- Adicionar coluna para última mensagem na tabela chat_contacts
ALTER TABLE chat_contacts
ADD COLUMN IF NOT EXISTS last_message_text TEXT DEFAULT NULL AFTER last_message_timestamp,
ADD COLUMN IF NOT EXISTS last_message_type VARCHAR(20) DEFAULT 'text' AFTER last_message_text;

-- Criar índice para tipo de mensagem
CREATE INDEX IF NOT EXISTS idx_message_type ON chat_messages(message_type);

-- Comentários
ALTER TABLE chat_messages 
MODIFY COLUMN message_type VARCHAR(20) DEFAULT 'text' COMMENT 'Tipos: text, audio, image, video, document';
