-- Migration: Recaptação sigilosa (substituição discreta).
-- Data: 2026-10-03
--
-- Objetivo (Ajuste 8): permitir recapturar profissionais para SUBSTITUIR o atual
-- sem que ele saiba. A recaptação sigilosa NÃO é disparada em grupo (o profissional
-- atual poderia estar no grupo); é enviada por mensagem direta aos elegíveis,
-- excluindo o profissional atual.
--
-- is_confidential marca a demanda em recaptação discreta.
-- confidential_exclude_user_id guarda o profissional que NÃO pode ser avisado.

ALTER TABLE demands
    ADD COLUMN IF NOT EXISTS is_confidential TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS confidential_exclude_user_id INT UNSIGNED NULL;
