-- Migration: cadastro inicial de POLOS (cliente -> polo -> operadora).
-- Data: 2026-11-11
--
-- Polos encontrados nos dados do projeto:
--   - GLOBAL: orientações da operadora (migrations 2026-10-03_0009 e 2026-10-27_0003)
--     separam os polos "Rio Preto/SP/Prudente/Campinas" e "Salvador/Recife".
--   - CENEMED: contatos "Polo <cidade> - Cenemed" em docs/contatos.csv.
--
-- NÃO cadastrados (o contato não diz de qual cliente é): Brasília, Belo Horizonte,
-- Goiás. Cadastre pela tela Polos quando souber o cliente.
--
-- Idempotente: só insere se o cliente existir e o polo ainda não estiver
-- cadastrado nele. Não altera nem apaga nada. Requer 2026-11-11_0001_polos.sql.
-- A tela Polos (polos_config.php) tem um botão que faz o mesmo cadastro.
INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Rio Preto', 'São José do Rio Preto', 'SP', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'RIO PRETO')
ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'São Paulo', 'São Paulo', 'SP', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'SÃO PAULO')
ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Presidente Prudente', 'Presidente Prudente', 'SP', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'PRESIDENTE PRUDENTE')
ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Campinas', 'Campinas', 'SP', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'CAMPINAS')
ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Salvador', 'Salvador', 'BA', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'SALVADOR')
ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Recife', 'Recife', 'PE', 'Cadastro inicial. Fonte: Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'GLOBAL' OR UPPER(TRIM(c.name)) LIKE 'GLOBAL%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'RECIFE')
ORDER BY (UPPER(TRIM(c.name)) = 'GLOBAL') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Araçatuba', 'Araçatuba', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'ARAÇATUBA')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Bauru', 'Bauru', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'BAURU')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Campinas', 'Campinas', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'CAMPINAS')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Divinópolis', 'Divinópolis', 'MG', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'DIVINÓPOLIS')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Fernandópolis', 'Fernandópolis', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'FERNANDÓPOLIS')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Goiânia', 'Goiânia', 'GO', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'GOIÂNIA')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Marília', 'Marília', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'MARÍLIA')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Presidente Prudente', 'Presidente Prudente', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'PRESIDENTE PRUDENTE')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'Ribeirão Preto', 'Ribeirão Preto', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'RIBEIRÃO PRETO')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

INSERT INTO polos (client_id, name, city, state, notes, is_active)
SELECT c.id, 'São Paulo', 'São Paulo', 'SP', 'Cadastro inicial. Fonte: Contatos: "Polo ... - Cenemed"', 1
FROM clients c
WHERE (UPPER(TRIM(c.name)) = 'CENEMED' OR UPPER(TRIM(c.name)) LIKE 'CENEMED%')
  AND NOT EXISTS (SELECT 1 FROM polos p WHERE p.client_id = c.id AND UPPER(TRIM(p.name)) = 'SÃO PAULO')
ORDER BY (UPPER(TRIM(c.name)) = 'CENEMED') DESC, c.is_active DESC, c.id ASC
LIMIT 1;

-- Conferência:
-- SELECT c.name AS cliente, p.name AS polo, p.city, p.state FROM polos p LEFT JOIN clients c ON c.id = p.client_id ORDER BY c.name, p.name;
