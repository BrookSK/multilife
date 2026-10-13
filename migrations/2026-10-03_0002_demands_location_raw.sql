-- Migration: Endereço como campo ÚNICO no card de captação.
-- Data: 2026-10-03
--
-- Objetivo (Ajuste 4): no card manual a equipe informa o endereço em um único
-- campo (a separação em rua/número/bairro/cidade/UF é feita depois pela IA).
-- Adicionamos a coluna location_raw em demands para guardar esse texto bruto.

ALTER TABLE demands
    ADD COLUMN IF NOT EXISTS location_raw TEXT NULL AFTER location_complement;
