-- Migration: cadastro COMPLETO de polos (cliente -> polo -> operadora).
-- Data: 2026-11-17
--
-- Inclui os 16 polos de 2026-11-11_0002 (não duplica) e acrescenta:
--   - UNIMED: Rio Preto, Andradina, Bebedouro, Birigui, Londrina, Três Lagoas,
--     Lins, Catalão e Tatuí (planilha MONITORAMENTO (MAJU) e contatos "Unimed …").
--   - GLOBAL: Brasília (contato "Ricardo / Global Brasilia").
--   - CENEMED: Rio Preto (matriz), Belo Horizonte e Brasília. BH e Brasília vêm dos
--     contatos "Adm. … / Polo …", o mesmo padrão dos administrativos da Cenemed
--     (Polo Ribeirão Preto, Campinas, Divinópolis, Goiás). Se não forem da Cenemed,
--     edite o cliente do polo na tela Polos.
--
-- Clientes sem polo nos dados do projeto (PRONEP, SENIOR MAIS, GERIATRICS,
-- PLENO SAÚDE, MASTERMED, ANERY, APAS, AUSTA, DAY HOME CARE, GANEP LAR, LIFE CARE,
-- VIP CARE, Particular): nenhum contato ou planilha diz a unidade. A consulta do
-- final lista os clientes que continuam sem polo para cadastrar pela tela Polos.
--
-- Idempotente: pode rodar mais de uma vez. Não altera nem apaga polos existentes.
-- A tela Polos (polos_config.php) tem o botão "Cadastrar estes polos" com a mesma lista.

-- 0) Estrutura (caso 2026-11-11_0001_polos.sql não tenha rodado).
CREATE TABLE IF NOT EXISTS polos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  city VARCHAR(120) NULL,
  state CHAR(2) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_polos_client (client_id),
  KEY idx_polos_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1) Cliente "Unimed" (pai dos polos da Unimed), só se ainda não existir com esse nome exato.
INSERT INTO clients (name, notes, is_active)
SELECT 'Unimed', 'Cliente criado para agrupar os polos da Unimed (migration 2026-11-17_0001).', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM clients WHERE UPPER(TRIM(name)) = 'UNIMED');

-- 2) Polos (um INSERT por polo; só entra se o cliente existir e o polo ainda não).
--    UNIMED usa nome EXATO (não pode cair em "UNIMED BEBEDOURO"); GLOBAL/CENEMED
--    aceitam nome começando com eles (ex.: "GLOBAL CARE").
-- ---------------- GLOBAL ----------------
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Rio Preto', 'São José do Rio Preto', 'SP', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%'
      ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'RIO PRETO');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'São Paulo', 'São Paulo', 'SP', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%'
      ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'SÃO PAULO');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Presidente Prudente', 'Presidente Prudente', 'SP', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%'
      ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'PRESIDENTE PRUDENTE');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Campinas', 'Campinas', 'SP', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%'
      ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'CAMPINAS');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Salvador', 'Salvador', 'BA', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%'
      ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'SALVADOR');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Recife', 'Recife', 'PE', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%'
      ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'RECIFE');

-- ---------------- CENEMED ----------------
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Araçatuba', 'Araçatuba', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'ARAÇATUBA');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Bauru', 'Bauru', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'BAURU');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Campinas', 'Campinas', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'CAMPINAS');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Divinópolis', 'Divinópolis', 'MG', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'DIVINÓPOLIS');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Fernandópolis', 'Fernandópolis', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'FERNANDÓPOLIS');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Goiânia', 'Goiânia', 'GO', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'GOIÂNIA');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Marília', 'Marília', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'MARÍLIA');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Presidente Prudente', 'Presidente Prudente', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'PRESIDENTE PRUDENTE');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Ribeirão Preto', 'Ribeirão Preto', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'RIBEIRÃO PRETO');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'São Paulo', 'São Paulo', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'SÃO PAULO');

-- ---------------- GLOBAL ----------------
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Brasília', 'Brasília', 'DF', 'Cadastro inicial. Fonte: Contatos: "Ricardo / Global Brasilia"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%'
      ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'BRASÍLIA');

-- ---------------- CENEMED ----------------
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Rio Preto', 'São José do Rio Preto', 'SP', 'Cadastro inicial. Fonte: Contatos: "Cenemed Rio Preto" e "Cenemed - Matriz"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'RIO PRETO');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Belo Horizonte', 'Belo Horizonte', 'MG', 'Cadastro inicial. Fonte: Contatos: "Adm. Fabiana / Polo Belo Horizonte" (mesmo padrão dos administrativos da Cenemed). Confirme o cliente.', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'BELO HORIZONTE');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Brasília', 'Brasília', 'DF', 'Cadastro inicial. Fonte: Contatos: "Adm. Claudineia / Polo Brasília" (mesmo padrão dos administrativos da Cenemed). Confirme o cliente.', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%'
      ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'BRASÍLIA');

-- ---------------- UNIMED ----------------
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Rio Preto', 'São José do Rio Preto', 'SP', 'Cadastro inicial. Fonte: Contatos: "Unimed Rio Preto"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'RIO PRETO');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Andradina', 'Andradina', 'SP', 'Cadastro inicial. Fonte: Monitoramento (cliente UNIMED ANDRADINA) e contatos', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'ANDRADINA');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Bebedouro', 'Bebedouro', 'SP', 'Cadastro inicial. Fonte: Monitoramento (cliente UNIMED BEBEDOURO) e contatos', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'BEBEDOURO');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Birigui', 'Birigui', 'SP', 'Cadastro inicial. Fonte: Monitoramento (cliente UNIMED BIRIGUI) e contatos', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'BIRIGUI');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Londrina', 'Londrina', 'PR', 'Cadastro inicial. Fonte: Monitoramento (cliente UNIMED LONDRINA) e contatos', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'LONDRINA');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Três Lagoas', 'Três Lagoas', 'MS', 'Cadastro inicial. Fonte: Monitoramento (cliente UNIMED TRÊS LAGOAS) e contatos', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'TRÊS LAGOAS');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Lins', 'Lins', 'SP', 'Cadastro inicial. Fonte: Contatos: "Unimed Lins"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'LINS');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Catalão', 'Catalão', 'GO', 'Cadastro inicial. Fonte: Contatos: "Unimed Catalão"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'CATALÃO');
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT x.id, 'Tatuí', 'Tatuí', 'SP', 'Cadastro inicial. Fonte: Contatos: "Unimed Tatuí"', 1
FROM (SELECT c.id FROM clients c WHERE UPPER(TRIM(c.name)) = 'UNIMED'
      ORDER BY (UPPER(TRIM(c.name)) = 'UNIMED') DESC, c.is_active DESC, c.id ASC LIMIT 1) x
WHERE NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = x.id AND UPPER(TRIM(p.name)) = 'TATUÍ');

-- 3) Conferência: todos os polos por cliente.
SELECT c.name AS cliente, p.name AS polo, p.city AS cidade, p.state AS uf, IF(p.is_active = 1, 'ativo', 'inativo') AS status
FROM polos p LEFT JOIN clients c ON c.id = p.client_id
ORDER BY c.name, p.name;

-- 4) Clientes ativos que continuam SEM polo (cadastre pela tela Polos quando souber a unidade).
SELECT c.id, c.name AS cliente_sem_polo
FROM clients c
WHERE c.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id)
ORDER BY c.name;
