-- Migration: Adicionar campo de observação de captação na demanda
-- Data: 2026-07-23
-- Descrição: Campo para observação interna que também é exibida na mensagem de captação via WhatsApp

ALTER TABLE demands
ADD COLUMN IF NOT EXISTS captation_note TEXT NULL COMMENT 'Observação interna da captação (exibida na mensagem WhatsApp)' AFTER ai_summary;
