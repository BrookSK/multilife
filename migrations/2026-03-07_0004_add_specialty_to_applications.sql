-- Adicionar campo de especialidade principal nas candidaturas de profissionais

ALTER TABLE professional_applications
ADD COLUMN specialty VARCHAR(120) NULL AFTER years_of_experience;
