-- Migration: Adicionar campo instance_name nas tabelas de chat para multi-instância
-- Data: 2026-07-13
-- Descrição: Permite filtrar conversas e mensagens por instância WhatsApp.
--            Cada usuário vê apenas as conversas da sua instância.

-- Adicionar campo na tabela de contatos/conversas
ALTER TABLE chat_contacts
ADD COLUMN IF NOT EXISTS instance_name VARCHAR(100) NULL COMMENT 'Nome da instância Evolution que originou este contato' AFTER remote_jid;

ALTER TABLE chat_contacts
ADD INDEX IF NOT EXISTS idx_chat_contacts_instance (instance_name);

-- Adicionar campo na tabela de mensagens
ALTER TABLE chat_messages
ADD COLUMN IF NOT EXISTS instance_name VARCHAR(100) NULL COMMENT 'Nome da instância Evolution que enviou/recebeu esta mensagem' AFTER remote_jid;

ALTER TABLE chat_messages
ADD INDEX IF NOT EXISTS idx_chat_messages_instance (instance_name);

-- Preencher registros existentes com a instância padrão
UPDATE chat_contacts SET instance_name = (SELECT setting_value FROM admin_settings WHERE setting_key = 'evolution.instance' LIMIT 1) WHERE instance_name IS NULL;
UPDATE chat_messages SET instance_name = (SELECT setting_value FROM admin_settings WHERE setting_key = 'evolution.instance' LIMIT 1) WHERE instance_name IS NULL;
