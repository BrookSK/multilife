-- Adicionar campo de custo interno nas especialidades

ALTER TABLE specialties
ADD COLUMN internal_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER minimum_value;
