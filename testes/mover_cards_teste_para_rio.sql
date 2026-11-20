-- =============================================================================
-- MUDA OS 40 CARDS DE TESTE PARA O RIO DE JANEIRO/RJ (grupos novos, só da equipe)
-- Gerado em 2026-11-20. Rodar de uma vez no phpMyAdmin (aba SQL) do banco certo.
--
-- POR QUÊ: em São Paulo já existem grupos criados no teste anterior (pelo WhatsApp
-- da Grace/MultiLife), com todo mundo dentro. A captação reaproveita o grupo que
-- tem a mesma especialidade + cidade + zona, e a mensagem sai pelo WhatsApp dono
-- do grupo. Mudando os cards para uma cidade SEM grupo, a captação cria grupos
-- novos pelo WhatsApp de quem capta (LRV Web) só com os profissionais de teste.
--
-- O QUE FAZ, NESTA ORDEM:
--   0) TRAVA: se algum card de teste fosse cair num grupo do Rio que JÁ EXISTE
--      (mesma especialidade + zona), PARA com erro mostrando o grupo e não muda
--      nenhum card. Hoje só existe "Enfermagem - Rio de Janeiro/RJ - Zona Sul - 1",
--      e nenhum card com Enfermagem vai para a Zona Sul.
--   1) BACKUP dos cards de teste, das especialidades deles e dos profissionais de
--      teste em tabelas zbkp1120_* (dá para voltar).
--   2) Endereço de cada card [TESTE nn] vira um endereço do Rio, na zona equivalente
--      (Zona Leste de SP vira Zona Norte do Rio, que não tem Zona Leste; os cards
--      23, 28 e 38, que têm Enfermagem, saem da Zona Sul). Número e complemento
--      continuam. O ENDEREÇO do texto do e-mail também é trocado.
--   3) Cards de teste que já foram captados voltam para Recebimento de E-mail e as
--      especialidades deles para "pendente", para poder captar de novo.
--   4) Profissionais de teste da equipe (finais 0528, 3062, Isaac, Juan, Bianca)
--      passam para o Rio, na mesma especialidade e zona equivalente.
--      O final 9485 (Lucas Campagna) vai para o standby: é o número conectado no
--      LRV Web, que CRIA os grupos e manda a mensagem (já entra como admin).
--      As meninas do standby (Terapia Ocupacional em Oiapoque/AP) não são tocadas.
--   5) Mostra o resultado.
--
-- NÃO RODE DE NOVO o reset_cards_e_seed_testes_captacao.sql depois deste: ele
-- recriaria os profissionais de teste em São Paulo.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- ENDEREÇOS NO RIO DE JANEIRO (um por card, pelo número do [TESTE nn])
-- Já existe "Enfermagem - Rio de Janeiro/RJ - Zona Sul - 1" (WhatsApp da Grace):
-- por isso nenhum card com Enfermagem/Técnico de Enfermagem vai para a Zona Sul.
-- -----------------------------------------------------------------------------
-- (as colunas copiam o tipo/collation das colunas de demands, para não dar
--  "Illegal mix of collations" em bancos com collation diferente)
DROP TABLE IF EXISTS zz_rio_addr;
CREATE TABLE zz_rio_addr AS
SELECT CAST(0 AS UNSIGNED) AS num, location_street AS street, location_neighborhood AS neighborhood,
       location_zip AS zip, location_zone AS zone
FROM demands WHERE 1 = 0;

INSERT INTO zz_rio_addr (num, street, neighborhood, zip, zone) VALUES
  ('01', 'Rua do Riachuelo', 'Centro', '20230014', 'Centro'),
  ('02', 'Rua Conde de Bonfim', 'Tijuca', '20520050', 'Zona Norte'),
  ('03', 'Rua Voluntários da Pátria', 'Botafogo', '22270010', 'Zona Sul'),
  ('04', 'Rua Leopoldina Rego', 'Olaria', '21021520', 'Zona Norte'),
  ('05', 'Avenida das Américas', 'Barra da Tijuca', '22640100', 'Zona Oeste'),
  ('06', 'Rua Almirante Alexandrino', 'Santa Teresa', '20241262', 'Centro'),
  ('07', 'Rua Uruguai', 'Andaraí', '20510060', 'Zona Norte'),
  ('08', 'Avenida Nossa Senhora de Copacabana', 'Copacabana', '22020001', 'Zona Sul'),
  ('09', 'Avenida Brás de Pina', 'Vila da Penha', '21221000', 'Zona Norte'),
  ('10', 'Avenida Genaro de Carvalho', 'Recreio dos Bandeirantes', '22795077', 'Zona Oeste'),
  ('11', 'Rua do Bispo', 'Rio Comprido', '20261064', 'Centro'),
  ('12', 'Boulevard 28 de Setembro', 'Vila Isabel', '20551030', 'Zona Norte'),
  ('13', 'Rua Visconde de Pirajá', 'Ipanema', '22410002', 'Zona Sul'),
  ('14', 'Rua Uranos', 'Ramos', '21060070', 'Zona Norte'),
  ('15', 'Estrada dos Três Rios', 'Freguesia de Jacarepaguá', '22745005', 'Zona Oeste'),
  ('16', 'Rua Santo Cristo', 'Santo Cristo', '20220300', 'Centro'),
  ('17', 'Rua Dias da Cruz', 'Méier', '20720011', 'Zona Norte'),
  ('18', 'Avenida Ataulfo de Paiva', 'Leblon', '22440033', 'Zona Sul'),
  ('19', 'Avenida Vicente de Carvalho', 'Vicente de Carvalho', '21210000', 'Zona Norte'),
  ('20', 'Rua Cândido Benício', 'Praça Seca', '21321000', 'Zona Oeste'),
  ('21', 'Rua São Luiz Gonzaga', 'São Cristóvão', '20910062', 'Centro'),
  ('22', 'Rua Engenheiro Richard', 'Grajaú', '20561090', 'Zona Norte'),
  ('23', 'Estrada do Rio Grande', 'Taquara', '22720010', 'Zona Oeste'),
  ('24', 'Avenida Monsenhor Félix', 'Irajá', '21235110', 'Zona Norte'),
  ('25', 'Rua Coronel Agostinho', 'Campo Grande', '23050280', 'Zona Oeste'),
  ('26', 'Rua Joaquim Silva', 'Lapa', '20241110', 'Centro'),
  ('27', 'Estrada do Portela', 'Madureira', '21351050', 'Zona Norte'),
  ('28', 'Rua da Gamboa', 'Gamboa', '20220323', 'Centro'),
  ('29', 'Rua Cuba', 'Penha', '21020160', 'Zona Norte'),
  ('30', 'Rua Francisco Real', 'Bangu', '21810042', 'Zona Oeste'),
  ('31', 'Rua Frei Caneca', 'Estácio', '20211010', 'Centro'),
  ('32', 'Avenida Ernani Cardoso', 'Cascadura', '21310310', 'Zona Norte'),
  ('33', 'Rua Jardim Botânico', 'Jardim Botânico', '22461000', 'Zona Sul'),
  ('34', 'Avenida Dom Hélder Câmara', 'Del Castilho', '20771001', 'Zona Norte'),
  ('35', 'Avenida Santa Cruz', 'Realengo', '21710232', 'Zona Oeste'),
  ('36', 'Rua do Catumbi', 'Catumbi', '20251120', 'Centro'),
  ('37', 'Rua Cardoso de Morais', 'Bonsucesso', '21032000', 'Zona Norte'),
  ('38', 'Rua Haddock Lobo', 'Tijuca', '20260132', 'Zona Norte'),
  ('39', 'Rua Aquidabã', 'Lins de Vasconcelos', '20720300', 'Zona Norte'),
  ('40', 'Estrada dos Bandeirantes', 'Vargem Grande', '22783115', 'Zona Oeste');

-- -----------------------------------------------------------------------------
-- 0) TRAVA: se algum card de teste fosse cair num grupo que JÁ EXISTE no Rio
--    (mesma especialidade + mesma zona), PARA aqui sem mudar nenhum card.
--    A comparação usa a mesma regra da captação: "Enfermagem", "Técnico de
--    Enfermagem" e "Enfermagem Domiciliar" contam como a mesma especialidade.
-- -----------------------------------------------------------------------------
DROP TABLE IF EXISTS zz_rio_plan, zz_rio_grp;
CREATE TABLE zz_rio_plan (spec_key VARCHAR(120), zone VARCHAR(40)) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
CREATE TABLE zz_rio_grp (name VARCHAR(255), spec_key VARCHAR(120), zone VARCHAR(40)) DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO zz_rio_plan (spec_key, zone)
SELECT DISTINCT
       CASE WHEN p.spec LIKE '%psicoped%' THEN 'psicopedagogia'
            WHEN p.spec LIKE '%enferm%' THEN 'enfermagem'
            WHEN p.spec LIKE '%fisio%' THEN 'fisioterapia'
            WHEN p.spec LIKE '%fono%' THEN 'fonoaudiologia'
            WHEN p.spec LIKE '%nutri%' THEN 'nutricao'
            WHEN p.spec LIKE '%psic%' THEN 'psicologia'
            WHEN p.spec LIKE '%ocupacional%' THEN 'terapia_ocupacional'
            ELSE LOWER(TRIM(p.spec)) END,
       p.zone
FROM (
  SELECT CONVERT(d.specialty USING utf8mb4) AS spec, CONVERT(a.zone USING utf8mb4) AS zone FROM demands d
  INNER JOIN zz_rio_addr a ON a.num = CAST(SUBSTRING(d.title, 8, 2) AS UNSIGNED)
  WHERE d.title LIKE '[TESTE %'
  UNION ALL
  SELECT CONVERT(s.specialty USING utf8mb4), CONVERT(a.zone USING utf8mb4) FROM demand_sub_requests s
  INNER JOIN demands d ON d.id = s.demand_id
  INNER JOIN zz_rio_addr a ON a.num = CAST(SUBSTRING(d.title, 8, 2) AS UNSIGNED)
  WHERE d.title LIKE '[TESTE %'
) p
WHERE COALESCE(p.spec, '') <> '';

INSERT INTO zz_rio_grp (name, spec_key, zone)
SELECT g.name,
       CASE WHEN g.k LIKE '%psicoped%' THEN 'psicopedagogia'
            WHEN g.k LIKE '%enferm%' THEN 'enfermagem'
            WHEN g.k LIKE '%fisio%' THEN 'fisioterapia'
            WHEN g.k LIKE '%fono%' THEN 'fonoaudiologia'
            WHEN g.k LIKE '%nutri%' THEN 'nutricao'
            WHEN g.k LIKE '%psic%' THEN 'psicologia'
            WHEN g.k LIKE '%ocupacional%' THEN 'terapia_ocupacional'
            ELSE LOWER(TRIM(g.k)) END,
       g.zone
FROM (
  SELECT name, COALESCE(NULLIF(specialty, ''), name) AS k, zone
  FROM whatsapp_groups
  WHERE (state = 'RJ' AND city LIKE 'Rio de Janeiro%') OR name LIKE '%Rio de Janeiro%'
) g;

DROP PROCEDURE IF EXISTS zz_rio_guard;
DELIMITER $$
CREATE PROCEDURE zz_rio_guard()
BEGIN
  DECLARE v_conf TEXT DEFAULT NULL;
  DECLARE v_msg VARCHAR(255);
  SELECT GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ' | ') INTO v_conf
  FROM zz_rio_plan p
  INNER JOIN zz_rio_grp g ON g.spec_key = p.spec_key
                         AND (g.zone = p.zone OR g.name LIKE CONCAT('%', p.zone, '%'));
  IF v_conf IS NOT NULL THEN
    SET v_msg = LEFT(CONCAT('PARADO, nada foi alterado. Grupo ja existe no Rio: ', v_conf), 255);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;
END$$
DELIMITER ;
CALL zz_rio_guard();
DROP PROCEDURE IF EXISTS zz_rio_guard;
DROP TABLE IF EXISTS zz_rio_plan, zz_rio_grp;

-- -----------------------------------------------------------------------------
-- 1) BACKUP (se rodar de novo, mantém a cópia original)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zbkp1120_demands LIKE demands;
INSERT IGNORE INTO zbkp1120_demands SELECT * FROM demands WHERE title LIKE '[TESTE %';

CREATE TABLE IF NOT EXISTS zbkp1120_demand_sub_requests LIKE demand_sub_requests;
INSERT IGNORE INTO zbkp1120_demand_sub_requests
SELECT s.* FROM demand_sub_requests s INNER JOIN demands d ON d.id = s.demand_id WHERE d.title LIKE '[TESTE %';

CREATE TABLE IF NOT EXISTS zbkp1120_users LIKE users;
INSERT IGNORE INTO zbkp1120_users SELECT * FROM users WHERE is_test_professional = 1;

-- -----------------------------------------------------------------------------
-- 2) TROCA O ENDEREÇO DOS CARDS
-- -----------------------------------------------------------------------------

-- Endereço completo novo de cada card (mesmo formato do location_raw do seed)
DROP TABLE IF EXISTS zz_rio_cards;
CREATE TABLE zz_rio_cards AS
SELECT d.id AS demand_id, d.location_raw AS old_raw,
       CONCAT(a.street, ', ', COALESCE(NULLIF(d.location_number, ''), 's/n'),
              IF(COALESCE(d.location_complement, '') <> '', CONCAT(' - ', d.location_complement), ''),
              ' - ', a.neighborhood, ' - Rio de Janeiro/RJ - CEP ', LEFT(a.zip, 5), '-', RIGHT(a.zip, 3)) AS new_raw
FROM demands d
INNER JOIN zz_rio_addr a ON a.num = CAST(SUBSTRING(d.title, 8, 2) AS UNSIGNED)
WHERE d.title LIKE '[TESTE %';

-- Texto do e-mail (linha ENDEREÇO) primeiro, enquanto o endereço antigo ainda está no card
UPDATE demands d
INNER JOIN zz_rio_cards c ON c.demand_id = d.id
SET d.description = REPLACE(d.description, c.old_raw, c.new_raw)
WHERE c.old_raw IS NOT NULL AND c.old_raw <> '';

UPDATE demands d
INNER JOIN zz_rio_cards c ON c.demand_id = d.id
INNER JOIN zz_rio_addr a ON a.num = CAST(SUBSTRING(d.title, 8, 2) AS UNSIGNED)
SET d.location_city = 'Rio de Janeiro',
    d.location_state = 'RJ',
    d.location_street = a.street,
    d.location_neighborhood = a.neighborhood,
    d.location_zip = a.zip,
    d.location_zone = a.zone,
    d.location_raw = c.new_raw,
    d.updated_at = NOW();

-- Especialidades do card (multidisciplinares): mesmo endereço do card
UPDATE demand_sub_requests s
INNER JOIN demands d ON d.id = s.demand_id
INNER JOIN zz_rio_cards c ON c.demand_id = d.id
SET s.location_city = d.location_city,
    s.location_state = d.location_state,
    s.location_street = d.location_street,
    s.location_neighborhood = d.location_neighborhood;

-- -----------------------------------------------------------------------------
-- 3) CARDS DE TESTE JÁ CAPTADOS VOLTAM PARA RECEBIMENTO DE E-MAIL
-- -----------------------------------------------------------------------------
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
SELECT d.id, d.status, 'aguardando_captacao', NULL, 'card de teste movido para o Rio de Janeiro/RJ para captar em grupo novo (2026-11-20)'
FROM demands d
INNER JOIN zz_rio_cards c ON c.demand_id = d.id
WHERE d.status <> 'aguardando_captacao';

UPDATE demands d
INNER JOIN zz_rio_cards c ON c.demand_id = d.id
SET d.status = 'aguardando_captacao', d.assumed_by_user_id = NULL, d.assumed_at = NULL
WHERE d.status <> 'aguardando_captacao';

UPDATE demand_sub_requests s
INNER JOIN zz_rio_cards c ON c.demand_id = s.demand_id
SET s.status = 'pendente', s.dispatched_at = NULL
WHERE s.status <> 'pendente';

-- -----------------------------------------------------------------------------
-- 4) PROFISSIONAIS DE TESTE
-- -----------------------------------------------------------------------------
-- 4.1) Final 9485 (número do LRV Web, que cria os grupos): standby
UPDATE users
SET specialty = 'Terapia Ocupacional', city = 'Oiapoque', state = 'AP', neighborhood = 'Centro', zone = NULL
WHERE is_test_professional = 1
  AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '') LIKE '%991429485';

-- 4.2) Cadastros por especialidade x zona (e-mails @teste.multilife.local) que estão em São Paulo
UPDATE users
SET zone = CASE zone WHEN 'Zona Leste' THEN 'Zona Norte' ELSE zone END,
    neighborhood = CASE zone
                     WHEN 'Centro' THEN 'Centro'
                     WHEN 'Zona Sul' THEN 'Botafogo'
                     WHEN 'Zona Oeste' THEN 'Barra da Tijuca'
                     ELSE 'Tijuca'
                   END,
    city = 'Rio de Janeiro',
    state = 'RJ'
WHERE is_test_professional = 1
  AND email LIKE '%@teste.multilife.local'
  AND city = 'São Paulo';

-- 4.3) Cadastros antigos da equipe (finais 0528 e 3062) que estavam em Fisioterapia/SP
UPDATE users
SET city = 'Rio de Janeiro', state = 'RJ', neighborhood = 'Botafogo', zone = 'Zona Sul'
WHERE id IN (254, 257, 258, 260, 433)
  AND is_test_professional = 1
  AND city = 'São Paulo';

-- Limpeza do apoio
DROP TABLE IF EXISTS zz_rio_addr, zz_rio_cards, zz_rio_plan, zz_rio_grp;

-- -----------------------------------------------------------------------------
-- 5) RESULTADO
-- -----------------------------------------------------------------------------
-- Profissionais de teste por cidade/especialidade/zona (Rio = equipe; Oiapoque = standby)
SELECT u.city AS cidade, u.state AS uf, u.specialty AS especialidade, u.zone AS zona,
       COUNT(*) AS cadastros,
       GROUP_CONCAT(DISTINCT RIGHT(u.phone, 4) ORDER BY RIGHT(u.phone, 4) SEPARATOR ', ') AS finais
FROM users u
WHERE u.is_test_professional = 1 AND u.status = 'active'
GROUP BY u.city, u.state, u.specialty, u.zone
ORDER BY u.city, u.specialty, u.zone;

-- Cards de teste
SELECT d.id, d.title AS titulo, d.status, d.location_neighborhood AS bairro, d.location_zone AS zona,
       d.location_city AS cidade, d.location_state AS uf,
       (SELECT GROUP_CONCAT(CONCAT(s.specialty, ' (', s.status, ')') ORDER BY s.id SEPARATOR ', ')
          FROM demand_sub_requests s WHERE s.demand_id = d.id) AS especialidades
FROM demands d
WHERE d.title LIKE '[TESTE %'
ORDER BY d.id;

-- =============================================================================
-- PARA VOLTAR TUDO COMO ESTAVA (rodar separado, só se precisar):
--   UPDATE demands d INNER JOIN zbkp1120_demands b ON b.id = d.id
--     SET d.location_city = b.location_city, d.location_state = b.location_state,
--         d.location_street = b.location_street, d.location_neighborhood = b.location_neighborhood,
--         d.location_zip = b.location_zip, d.location_zone = b.location_zone,
--         d.location_raw = b.location_raw, d.description = b.description;
--   UPDATE demand_sub_requests s INNER JOIN zbkp1120_demand_sub_requests b ON b.id = s.id
--     SET s.location_city = b.location_city, s.location_state = b.location_state,
--         s.location_street = b.location_street, s.location_neighborhood = b.location_neighborhood;
--   UPDATE users u INNER JOIN zbkp1120_users b ON b.id = u.id
--     SET u.specialty = b.specialty, u.city = b.city, u.state = b.state,
--         u.neighborhood = b.neighborhood, u.zone = b.zone;
--
-- APAGAR OS BACKUPS depois de conferir:
--   DROP TABLE zbkp1120_demands, zbkp1120_demand_sub_requests, zbkp1120_users;
-- =============================================================================
