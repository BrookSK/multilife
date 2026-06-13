-- Migration: Suporte a confirmação via reação em mensagens de captação
-- Data: 2026-06-13

-- Adicionar coluna para armazenar o message_id retornado pela Evolution API
ALTER TABLE demand_dispatch_logs
ADD COLUMN IF NOT EXISTS external_message_id VARCHAR(255) DEFAULT NULL COMMENT 'ID da mensagem no WhatsApp (key.id da Evolution API)' AFTER capture_token;

-- Adicionar coluna para armazenar quem confirmou (reagiu)
ALTER TABLE demand_dispatch_logs
ADD COLUMN IF NOT EXISTS confirmed_by_phone VARCHAR(30) DEFAULT NULL COMMENT 'Telefone do profissional que reagiu/confirmou' AFTER external_message_id,
ADD COLUMN IF NOT EXISTS confirmed_at DATETIME DEFAULT NULL COMMENT 'Quando foi confirmado' AFTER confirmed_by_phone,
ADD COLUMN IF NOT EXISTS confirmed_by_user_id INT UNSIGNED DEFAULT NULL COMMENT 'ID do profissional que confirmou (se identificado)' AFTER confirmed_at;

-- Índice para buscar por external_message_id (para vincular reações)
CREATE INDEX IF NOT EXISTS idx_dispatch_external_msg ON demand_dispatch_logs(external_message_id);
