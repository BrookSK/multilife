-- Adicionar campo de especialidade na tabela users

ALTER TABLE users
ADD COLUMN specialty VARCHAR(120) NULL AFTER phone;
