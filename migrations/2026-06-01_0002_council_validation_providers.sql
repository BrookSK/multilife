-- Migration: Suporte a provedores de API para validação de registros profissionais
-- Data: 2026-06-01
-- Depende de: 2026-06-01_0001_council_validation.sql
-- Descrição: Adiciona colunas para rastrear provedor utilizado e tempo de resposta nos logs.

-- Novas colunas no log de validações
ALTER TABLE council_validation_logs
  ADD COLUMN IF NOT EXISTS provider_used VARCHAR(100) NULL
    COMMENT 'Nome do provedor utilizado (Consultar.IO, Infosimples, Portal Direto)'
    AFTER source,
  ADD COLUMN IF NOT EXISTS response_time_ms INT UNSIGNED NULL
    COMMENT 'Tempo de resposta em milissegundos'
    AFTER provider_used;

-- Índices para consultas por provedor e por candidatura
CREATE INDEX IF NOT EXISTS idx_cvl_provider ON council_validation_logs (provider_used);
CREATE INDEX IF NOT EXISTS idx_cvl_application ON council_validation_logs (triggered_by_application_id);
