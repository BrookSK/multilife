-- Migration: Adicionar campo de frequência nas demandas e sub-solicitações
-- Data: 2026-05-29

ALTER TABLE demands
ADD COLUMN frequency VARCHAR(120) NULL COMMENT 'Frequência do atendimento (ex: 3x por semana)' AFTER urgency;

ALTER TABLE demand_sub_requests
ADD COLUMN frequency VARCHAR(120) NULL COMMENT 'Frequência do atendimento' AFTER urgency;
