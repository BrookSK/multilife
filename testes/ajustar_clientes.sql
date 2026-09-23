-- =====================================================================
-- Ajuste da tabela `clients` para a lista oficial (print enviado).
-- =====================================================================
-- Estratégia SEGURA (não apaga nada):
--   1) Garante que a coluna/tabela existem.
--   2) INSERE os clientes da lista que ainda não existem (match por nome).
--   3) REATIVA (is_active=1) os que estão na lista.
--   4) DESATIVA (is_active=0) os clientes que NÃO estão na lista
--      (não deleta, para preservar vínculos/histórico). Ajuste depois se quiser.
--
-- ATENÇÃO: dois nomes vieram truncados no print. Deixei um valor provisório:
--   - 'RESIDENCIAL...'      -> confirme o nome completo e troque nos 2 lugares.
--   - 'SUAIDE SOLUÇÕES EM...'-> idem.
-- =====================================================================

-- 0) Garantir estrutura mínima (idempotente).
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    cnpj VARCHAR(18) NULL,
    contact_phone VARCHAR(20) NULL,
    contact_email VARCHAR(255) NULL,
    billing_email VARCHAR(255) NULL,
    email_domain VARCHAR(255) NULL,
    closing_day TINYINT UNSIGNED NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_clients_active (is_active),
    KEY idx_clients_email_domain (email_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Tabela temporária com a lista oficial de clientes.
DROP TEMPORARY TABLE IF EXISTS _clientes_oficiais;
CREATE TEMPORARY TABLE _clientes_oficiais (name VARCHAR(255) NOT NULL);
INSERT INTO _clientes_oficiais (name) VALUES
  ('ANERY'),
  ('APAS'),
  ('ARQUIVOS ANTIGOS'),
  ('AUSTA'),
  ('CENEMED'),
  ('DAY HOME CARE'),
  ('GANEP LAR'),
  ('GLOBAL'),
  ('LIFE CARE'),
  ('MASTERMED'),
  ('PARTICULAR'),
  ('PRONEP'),
  ('RESIDENCIAL...'),            -- TODO: nome completo
  ('SENIOR MAIS'),
  ('SUAIDE SOLUÇÕES EM...'),     -- TODO: nome completo
  ('UNIMED ANDRADINA'),
  ('UNIMED BEBEDOURO'),
  ('UNIMED BIRIGUI'),
  ('UNIMED LONDRINA'),
  ('VIP CARE');

-- 2) Inserir os que faltam (não existe cliente com esse nome ainda).
INSERT INTO clients (name, is_active, created_at)
SELECT o.name, 1, NOW()
FROM _clientes_oficiais o
WHERE NOT EXISTS (SELECT 1 FROM clients c WHERE c.name = o.name);

-- 3) Reativar os que estão na lista (caso estivessem inativos).
UPDATE clients c
JOIN _clientes_oficiais o ON o.name = c.name
SET c.is_active = 1;

-- 4) Desativar (NÃO apagar) os clientes que não estão na lista oficial.
UPDATE clients c
SET c.is_active = 0
WHERE NOT EXISTS (SELECT 1 FROM _clientes_oficiais o WHERE o.name = c.name);

DROP TEMPORARY TABLE IF EXISTS _clientes_oficiais;

-- 5) Conferência: veja como ficou.
SELECT id, name, closing_day, is_active
FROM clients
ORDER BY is_active DESC, name ASC;
