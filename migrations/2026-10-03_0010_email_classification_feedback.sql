-- Migration: Aprendizado da classificação de e-mails (captação x não captação).
-- Data: 2026-10-03
--
-- A equipe marca cada e-mail/card como 'captation' (é captação/prospecção) ou
-- 'not_captation' (não é). O sistema APRENDE com isso:
--   1) email_classification_feedback: histórico de cada marcação (auditoria + treino).
--   2) email_classification_rules: regras consolidadas por remetente/domínio e por
--      palavra de assunto, usadas pelo classificador ANTES da IA.
--
-- Assim, e-mails de um remetente/domínio já marcado seguem a decisão aprendida,
-- sem depender de IA e sem repetir o erro.

CREATE TABLE IF NOT EXISTS email_classification_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inbound_email_id BIGINT UNSIGNED NULL,
    demand_id BIGINT UNSIGNED NULL,
    from_email VARCHAR(190) NULL,
    from_domain VARCHAR(190) NULL,
    subject VARCHAR(255) NULL,
    -- Rótulo dado pela equipe: 'captation' (é) ou 'not_captation' (não é).
    label ENUM('captation','not_captation') NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ecf_domain (from_domain),
    KEY idx_ecf_email (from_email),
    KEY idx_ecf_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_classification_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Tipo de regra: 'from_email', 'from_domain' ou 'subject_keyword'.
    rule_type ENUM('from_email','from_domain','subject_keyword') NOT NULL,
    rule_value VARCHAR(190) NOT NULL,
    -- Decisão associada: 'captation' ou 'not_captation'.
    label ENUM('captation','not_captation') NOT NULL,
    -- Contadores de reforço (quantas marcações concordaram com a regra).
    hits INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ecr_rule (rule_type, rule_value),
    KEY idx_ecr_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
