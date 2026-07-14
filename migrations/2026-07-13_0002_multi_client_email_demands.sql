-- Migration: Suporte a múltiplos clientes/pacientes no mesmo e-mail
-- Data: 2026-07-13
-- Descrição: Quando um e-mail contém vários pacientes (cada um com suas especialidades),
--            o sistema cria um card (demand) separado para cada paciente.
--            Adiciona campo patient_name à tabela demands.

-- Campo para armazenar o nome do paciente extraído pelo GPT
ALTER TABLE demands
ADD COLUMN IF NOT EXISTS patient_name VARCHAR(200) NULL COMMENT 'Nome do paciente/cliente extraído do e-mail' AFTER title;

-- Campo para vincular múltiplas demands ao mesmo e-mail de origem
ALTER TABLE demands
ADD COLUMN IF NOT EXISTS source_email_id BIGINT UNSIGNED NULL COMMENT 'ID do e-mail de origem (inbound_emails)' AFTER origin_email;

-- Índice para buscar todas as demands criadas a partir do mesmo e-mail
ALTER TABLE demands
ADD INDEX IF NOT EXISTS idx_demands_source_email_id (source_email_id);

-- FK para inbound_emails (opcional, SET NULL se e-mail for deletado)
ALTER TABLE demands
ADD CONSTRAINT fk_demands_source_email FOREIGN KEY (source_email_id) REFERENCES inbound_emails(id) ON DELETE SET NULL;
