-- Migration: Adicionar campos de valor do procedimento e resumo da IA na tabela demands
-- Data: 2026-03-04

-- Adicionar campo para valor do procedimento
ALTER TABLE demands 
ADD COLUMN procedure_value DECIMAL(10,2) NULL COMMENT 'Valor do procedimento extraído pela IA' AFTER description;

-- Adicionar campo para resumo gerado pela IA
ALTER TABLE demands 
ADD COLUMN ai_summary TEXT NULL COMMENT 'Resumo da necessidade gerado pela IA' AFTER procedure_value;
