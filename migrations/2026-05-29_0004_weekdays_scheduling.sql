-- Migration: Adicionar dias da semana para agendamento de sessões
-- Data: 2026-05-29

-- Dias da semana selecionados para o atendimento (JSON array: [1,3,5] = seg,qua,sex)
-- 1=Segunda, 2=Terça, 3=Quarta, 4=Quinta, 5=Sexta, 6=Sábado, 7=Domingo
ALTER TABLE patient_assignments
ADD COLUMN weekdays VARCHAR(50) NULL COMMENT 'Dias da semana do atendimento (JSON: [1,3,5] = seg,qua,sex)' AFTER session_frequency;

-- Adicionar session_date nas sessões que ainda não têm (para poder preencher na geração)
-- A coluna já existe, só garantir que pode ser preenchida na criação
