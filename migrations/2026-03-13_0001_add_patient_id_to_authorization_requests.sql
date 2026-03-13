-- Adicionar coluna patient_id à tabela authorization_requests
-- O patient_id é selecionado no formulário de proposta junto com o profissional

ALTER TABLE authorization_requests 
ADD COLUMN patient_id INT UNSIGNED NOT NULL COMMENT 'Paciente selecionado no formulário de proposta' AFTER demand_id;

-- Adicionar índice para patient_id
ALTER TABLE authorization_requests 
ADD INDEX idx_patient_id (patient_id);
