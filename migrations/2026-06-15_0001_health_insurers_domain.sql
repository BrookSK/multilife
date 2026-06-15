-- Migration: Adicionar domínio de e-mail às operadoras para auto-detecção
-- Data: 2026-06-15

-- Adicionar coluna de domínio de e-mail (ex: unimed.com.br, amil.com.br)
ALTER TABLE health_insurers
ADD COLUMN IF NOT EXISTS email_domain VARCHAR(255) DEFAULT NULL COMMENT 'Domínio de e-mail da operadora (ex: unimed.com.br)' AFTER billing_email;

-- Índice para busca por domínio
CREATE INDEX IF NOT EXISTS idx_hi_email_domain ON health_insurers(email_domain);

-- Preencher domínios automaticamente a partir dos e-mails existentes
UPDATE health_insurers 
SET email_domain = SUBSTRING_INDEX(contact_email, '@', -1)
WHERE contact_email IS NOT NULL AND contact_email != '' AND email_domain IS NULL AND contact_email LIKE '%@%';

UPDATE health_insurers 
SET email_domain = SUBSTRING_INDEX(billing_email, '@', -1)
WHERE billing_email IS NOT NULL AND billing_email != '' AND email_domain IS NULL AND billing_email LIKE '%@%';
