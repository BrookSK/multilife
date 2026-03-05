-- Adicionar coluna specialty_id na tabela whatsapp_groups
ALTER TABLE whatsapp_groups 
ADD COLUMN specialty_id INT NULL AFTER contacts_count,
ADD INDEX idx_specialty_id (specialty_id);
