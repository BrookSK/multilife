-- Migration: Adicionar campo de urgência na tabela demands
-- Data: 2026-03-04

-- Adicionar campo para urgência extraída pela IA
ALTER TABLE demands 
ADD COLUMN urgency VARCHAR(20) NULL COMMENT 'Nível de urgência: urgente, normal, baixa' AFTER ai_summary;

-- Criar índice para facilitar filtros por urgência
CREATE INDEX idx_demands_urgency ON demands(urgency);
