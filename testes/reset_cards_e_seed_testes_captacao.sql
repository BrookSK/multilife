-- =============================================================================
-- RESET DOS CARDS DE TESTE + PROFISSIONAIS DE TESTE + 40 CARDS DE TESTE
-- Gerado em 2026-11-19. Rodar de uma vez no phpMyAdmin (aba SQL) do banco certo.
--
-- O QUE FAZ, NESTA ORDEM:
--   0) Mostra quantos cards há por status ANTES do reset.
--   1) BACKUP: copia tudo que vai ser apagado/alterado para tabelas zbkp1119_*
--      (dá para restaurar). Depois de conferir, apague com o SQL do final.
--   2) APAGA os cards em Recebimento de E-mail (aguardando_captacao), Tratamento
--      Manual, Admitido e Cancelado, com tudo ligado a eles: especialidades do
--      card, histórico, disparos, interessados, autorizações, atendimentos,
--      sessões, lançamentos financeiros, pendências etc. Os cards EM CAPTAÇÃO
--      (em_captacao) e de qualquer outro status ficam como estão.
--      Pacientes, profissionais e e-mails recebidos NÃO são apagados.
--   3) PROFISSIONAIS DE TESTE:
--      - Maria Júlia, Mania, Lidiane, Guetie, Grace, Ana Cláudia e Ana Carla ficam
--        em STANDBY: Terapia Ocupacional em Oiapoque/AP (nenhum card de teste usa).
--      - Finais 0528, 3062 e 9485 (Lucas Vacari, Lucas Narciso, Lucas Campagna) +
--        Isaac, Juan e Bianca Narciso: 1 cadastro por especialidade e zona de São
--        Paulo usada nos cards (6 especialidades x 5 zonas), todos ativos, perfil
--        profissional e marcados como profissional de teste.
--        Senha de todos os novos: Teste@2026. E-mails @teste.multilife.local.
--   4) Liga o MODO DE TESTE DA CAPTAÇÃO (só profissionais de teste entram nos grupos).
--   5) Cria 40 cards completos em Recebimento de E-mail, todos em São Paulo/SP:
--      20 com uma especialidade e 20 multidisciplinares, nas 5 zonas.
--      Título começa com [TESTE nn]. E-mail de origem: lucas.vacari+operadora@lrvweb.com.br
--   6) Mostra o resultado (contagens, profissionais e cards).
-- =============================================================================

-- 0) ANTES
SELECT status, COUNT(*) AS cards_antes FROM demands GROUP BY status ORDER BY status;

-- -----------------------------------------------------------------------------
-- Apoio: adiciona coluna que faltar (alguns bancos foram criados antes delas)
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS zz_seed_addcol;
DELIMITER $$
CREATE PROCEDURE zz_seed_addcol(IN p_tbl VARCHAR(64), IN p_col VARCHAR(64), IN p_def VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tbl AND COLUMN_NAME = p_col) THEN
    SET @zz_sql = CONCAT('ALTER TABLE `', p_tbl, '` ADD COLUMN `', p_col, '` ', p_def);
    PREPARE zz_st FROM @zz_sql;
    EXECUTE zz_st;
    DEALLOCATE PREPARE zz_st;
  END IF;
END$$
DELIMITER ;

CALL zz_seed_addcol('demands', 'patient_name', 'VARCHAR(200) NULL');
CALL zz_seed_addcol('demands', 'location_street', 'VARCHAR(200) NULL');
CALL zz_seed_addcol('demands', 'location_neighborhood', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('demands', 'location_number', 'VARCHAR(20) NULL');
CALL zz_seed_addcol('demands', 'location_complement', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('demands', 'location_zip', 'VARCHAR(10) NULL');
CALL zz_seed_addcol('demands', 'location_zone', 'VARCHAR(40) NULL');
CALL zz_seed_addcol('demands', 'location_raw', 'TEXT NULL');
CALL zz_seed_addcol('demands', 'frequency', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('demands', 'urgency', 'VARCHAR(20) NULL');
CALL zz_seed_addcol('demands', 'procedure_value', 'DECIMAL(10,2) NULL');
CALL zz_seed_addcol('demands', 'ai_summary', 'TEXT NULL');
CALL zz_seed_addcol('demands', 'captation_note', 'TEXT NULL');
CALL zz_seed_addcol('demands', 'has_multiple_requests', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL zz_seed_addcol('demands', 'client_id', 'INT UNSIGNED NULL');
CALL zz_seed_addcol('demands', 'health_insurer_id', 'INT UNSIGNED NULL');
CALL zz_seed_addcol('demand_sub_requests', 'frequency', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('demand_sub_requests', 'location_street', 'VARCHAR(200) NULL');
CALL zz_seed_addcol('demand_sub_requests', 'location_neighborhood', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('demand_sub_requests', 'location_number', 'VARCHAR(20) NULL');
CALL zz_seed_addcol('demand_sub_requests', 'location_complement', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('users', 'phone', 'VARCHAR(30) NULL');
CALL zz_seed_addcol('users', 'specialty', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('users', 'city', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('users', 'state', 'CHAR(2) NULL');
CALL zz_seed_addcol('users', 'neighborhood', 'VARCHAR(120) NULL');
CALL zz_seed_addcol('users', 'zone', 'VARCHAR(40) NULL');
CALL zz_seed_addcol('users', 'is_test_professional', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL zz_seed_addcol('users', 'professional_type', "VARCHAR(20) NOT NULL DEFAULT 'new'");
CALL zz_seed_addcol('users', 'is_pre_registration', 'TINYINT(1) NOT NULL DEFAULT 0');

-- =============================================================================
-- 1) e 2) BACKUP + RESET DOS CARDS
-- =============================================================================
DROP TABLE IF EXISTS zz_reset_demands, zz_reset_assignments, zz_reset_auths, zz_reset_targets, zz_reset_report;
CREATE TABLE zz_reset_demands (id BIGINT UNSIGNED NOT NULL PRIMARY KEY);
CREATE TABLE zz_reset_assignments (id BIGINT UNSIGNED NOT NULL PRIMARY KEY);
CREATE TABLE zz_reset_auths (id BIGINT UNSIGNED NOT NULL PRIMARY KEY);
CREATE TABLE zz_reset_report (tabela VARCHAR(64), coluna VARCHAR(64), acao VARCHAR(20), linhas INT);

INSERT INTO zz_reset_demands (id)
SELECT id FROM demands WHERE status IN ('aguardando_captacao', 'tratamento_manual', 'admitido', 'cancelado');

INSERT INTO zz_reset_assignments (id)
SELECT id FROM patient_assignments WHERE demand_id IN (SELECT id FROM zz_reset_demands);

INSERT INTO zz_reset_auths (id)
SELECT id FROM authorization_requests WHERE demand_id IN (SELECT id FROM zz_reset_demands);

-- Toda tabela que aponta para esses cards, atendimentos ou autorizações.
-- Referência obrigatória (NOT NULL) = linha apagada; opcional = só desvincula (NULL).
-- Atendimentos e autorizações: tudo ligado a eles é apagado.
CREATE TABLE zz_reset_targets AS
SELECT c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col,
       CASE WHEN c.COLUMN_NAME IN ('assignment_id', 'patient_assignment_id') THEN 'zz_reset_assignments'
            WHEN c.COLUMN_NAME = 'authorization_request_id' THEN 'zz_reset_auths'
            ELSE 'zz_reset_demands' END AS id_set,
       CASE WHEN c.COLUMN_NAME IN ('assignment_id', 'patient_assignment_id', 'authorization_request_id') THEN 'delete'
            WHEN c.TABLE_NAME IN ('patient_assignments', 'authorization_requests', 'demand_sub_requests') AND c.COLUMN_NAME = 'demand_id' THEN 'delete'
            WHEN c.IS_NULLABLE = 'YES' THEN 'null'
            ELSE 'delete' END AS action
FROM information_schema.COLUMNS c
INNER JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.COLUMN_NAME IN ('demand_id', 'linked_demand_id', 'resumed_to_demand_id', 'assignment_id', 'patient_assignment_id', 'authorization_request_id')
  AND c.TABLE_NAME <> 'demands'
  AND c.TABLE_NAME NOT LIKE 'zbkp1119\_%'
  AND c.TABLE_NAME NOT LIKE 'zz\_%';

DROP PROCEDURE IF EXISTS zz_reset_run;
DELIMITER $$
CREATE PROCEDURE zz_reset_run()
BEGIN
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_tbl VARCHAR(64);
  DECLARE v_col VARCHAR(64);
  DECLARE v_set VARCHAR(64);
  DECLARE v_act VARCHAR(20);
  DECLARE cur CURSOR FOR SELECT tbl, col, id_set, action FROM zz_reset_targets ORDER BY tbl, col;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  OPEN cur;
  lp: LOOP
    FETCH cur INTO v_tbl, v_col, v_set, v_act;
    IF v_done = 1 THEN
      LEAVE lp;
    END IF;
    SET @zz_where = CONCAT('`', v_col, '` IN (SELECT id FROM `', v_set, '`)');
    -- backup das linhas antes de mexer
    SET @zz_sql = CONCAT('CREATE TABLE IF NOT EXISTS `zbkp1119_', v_tbl, '` LIKE `', v_tbl, '`');
    PREPARE zz_st FROM @zz_sql; EXECUTE zz_st; DEALLOCATE PREPARE zz_st;
    SET @zz_sql = CONCAT('INSERT IGNORE INTO `zbkp1119_', v_tbl, '` SELECT * FROM `', v_tbl, '` WHERE ', @zz_where);
    PREPARE zz_st FROM @zz_sql; EXECUTE zz_st; DEALLOCATE PREPARE zz_st;
    IF v_act = 'delete' THEN
      SET @zz_sql = CONCAT('DELETE FROM `', v_tbl, '` WHERE ', @zz_where);
    ELSE
      SET @zz_sql = CONCAT('UPDATE `', v_tbl, '` SET `', v_col, '` = NULL WHERE ', @zz_where);
    END IF;
    PREPARE zz_st FROM @zz_sql; EXECUTE zz_st;
    INSERT INTO zz_reset_report VALUES (v_tbl, v_col, v_act, ROW_COUNT());
    DEALLOCATE PREPARE zz_st;
  END LOOP;
  CLOSE cur;

  -- Pendências ligadas aos cards (ex.: "Pré-cadastrar paciente").
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pending_items') THEN
    CREATE TABLE IF NOT EXISTS zbkp1119_pending_items LIKE pending_items;
    INSERT IGNORE INTO zbkp1119_pending_items
      SELECT * FROM pending_items WHERE related_table = 'demands' AND related_id IN (SELECT id FROM zz_reset_demands);
    DELETE FROM pending_items WHERE related_table = 'demands' AND related_id IN (SELECT id FROM zz_reset_demands);
    INSERT INTO zz_reset_report VALUES ('pending_items', 'related_id', 'delete', ROW_COUNT());
  END IF;

  -- Os cards
  CREATE TABLE IF NOT EXISTS zbkp1119_demands LIKE demands;
  INSERT IGNORE INTO zbkp1119_demands SELECT * FROM demands WHERE id IN (SELECT id FROM zz_reset_demands);
  DELETE FROM demands WHERE id IN (SELECT id FROM zz_reset_demands);
  INSERT INTO zz_reset_report VALUES ('demands', 'id', 'delete', ROW_COUNT());
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 0;
CALL zz_reset_run();
SET FOREIGN_KEY_CHECKS = 1;

SELECT tabela, coluna, acao, linhas FROM zz_reset_report WHERE linhas > 0 ORDER BY tabela;

-- =============================================================================
-- 3) PROFISSIONAIS DE TESTE
-- =============================================================================

-- 3.1) Standby: Ana Carla, Ana Cláudia, Grace, Guetie, Lidiane, Mania, Maria Júlia
UPDATE users
SET specialty = 'Terapia Ocupacional', city = 'Oiapoque', state = 'AP', neighborhood = 'Centro', zone = NULL
WHERE id IN (431, 432, 434, 435, 436, 437, 438) AND is_test_professional = 1;

-- 3.2) Os que já existem com finais 0528, 3062 e 9485: Fisioterapia, Zona Sul
UPDATE users
SET specialty = 'Fisioterapia', city = 'São Paulo', state = 'SP', neighborhood = 'Vila Mariana', zone = 'Zona Sul', status = 'active'
WHERE id IN (254, 257, 258, 260, 433) AND is_test_professional = 1;

-- 3.3) 1 cadastro por pessoa x especialidade x zona (só cria o que ainda não existe)
DROP TABLE IF EXISTS zz_seed_people, zz_seed_combos;
CREATE TABLE zz_seed_people (slug VARCHAR(40), name VARCHAR(120), phone VARCHAR(30));
CREATE TABLE zz_seed_combos (specialty VARCHAR(120), spec_slug VARCHAR(20), zone VARCHAR(40), zone_slug VARCHAR(10), neighborhood VARCHAR(120));
INSERT INTO zz_seed_people (slug, name, phone) VALUES
  ('lucas.vacari', 'Lucas Vacari Teste', '5517991190528'),
  ('lucas.narciso', 'Lucas Narciso', '5517991253062'),
  ('lucas.campagna', 'Lucas Campagna', '5517991429485'),
  ('isaac', 'Isaac', '5517992738467'),
  ('juan', 'Juan', '556181284804'),
  ('bianca.narciso', 'Bianca Narciso', '5517981628213');
INSERT INTO zz_seed_combos (specialty, spec_slug, zone, zone_slug, neighborhood) VALUES
  ('Fisioterapia', 'fisio', 'Centro', 'centro', 'Bela Vista'),
  ('Fisioterapia', 'fisio', 'Zona Norte', 'zn', 'Santana'),
  ('Fisioterapia', 'fisio', 'Zona Sul', 'zs', 'Vila Mariana'),
  ('Fisioterapia', 'fisio', 'Zona Leste', 'zl', 'Tatuapé'),
  ('Fisioterapia', 'fisio', 'Zona Oeste', 'zo', 'Pinheiros'),
  ('Fonoaudiologia', 'fono', 'Centro', 'centro', 'Bela Vista'),
  ('Fonoaudiologia', 'fono', 'Zona Norte', 'zn', 'Santana'),
  ('Fonoaudiologia', 'fono', 'Zona Sul', 'zs', 'Vila Mariana'),
  ('Fonoaudiologia', 'fono', 'Zona Leste', 'zl', 'Tatuapé'),
  ('Fonoaudiologia', 'fono', 'Zona Oeste', 'zo', 'Pinheiros'),
  ('Enfermagem', 'enf', 'Centro', 'centro', 'Bela Vista'),
  ('Enfermagem', 'enf', 'Zona Norte', 'zn', 'Santana'),
  ('Enfermagem', 'enf', 'Zona Sul', 'zs', 'Vila Mariana'),
  ('Enfermagem', 'enf', 'Zona Leste', 'zl', 'Tatuapé'),
  ('Enfermagem', 'enf', 'Zona Oeste', 'zo', 'Pinheiros'),
  ('Técnico de Enfermagem', 'tecenf', 'Centro', 'centro', 'Bela Vista'),
  ('Técnico de Enfermagem', 'tecenf', 'Zona Norte', 'zn', 'Santana'),
  ('Técnico de Enfermagem', 'tecenf', 'Zona Sul', 'zs', 'Vila Mariana'),
  ('Técnico de Enfermagem', 'tecenf', 'Zona Leste', 'zl', 'Tatuapé'),
  ('Técnico de Enfermagem', 'tecenf', 'Zona Oeste', 'zo', 'Pinheiros'),
  ('Nutrição', 'nutri', 'Centro', 'centro', 'Bela Vista'),
  ('Nutrição', 'nutri', 'Zona Norte', 'zn', 'Santana'),
  ('Nutrição', 'nutri', 'Zona Sul', 'zs', 'Vila Mariana'),
  ('Nutrição', 'nutri', 'Zona Leste', 'zl', 'Tatuapé'),
  ('Nutrição', 'nutri', 'Zona Oeste', 'zo', 'Pinheiros'),
  ('Psicologia', 'psico', 'Centro', 'centro', 'Bela Vista'),
  ('Psicologia', 'psico', 'Zona Norte', 'zn', 'Santana'),
  ('Psicologia', 'psico', 'Zona Sul', 'zs', 'Vila Mariana'),
  ('Psicologia', 'psico', 'Zona Leste', 'zl', 'Tatuapé'),
  ('Psicologia', 'psico', 'Zona Oeste', 'zo', 'Pinheiros');

INSERT INTO users (name, email, phone, password_hash, status, specialty, city, state, neighborhood, zone,
                   is_test_professional, professional_type, is_pre_registration)
SELECT p.name,
       CONCAT(p.slug, '.', c.spec_slug, '.', c.zone_slug, '@teste.multilife.local'),
       p.phone, '$2y$10$q5mGL1tBdqaoS3bW16DHnO0X/DnmfRkzfqwYMizvR/E5y299FAbni', 'active',
       c.specialty, 'São Paulo', 'SP', c.neighborhood, c.zone, 1, 'new', 0
FROM zz_seed_people p
CROSS JOIN zz_seed_combos c
WHERE NOT EXISTS (
        SELECT 1 FROM users u
        WHERE u.phone = p.phone AND u.specialty = c.specialty AND u.city = 'São Paulo' AND u.zone = c.zone AND u.status = 'active')
  AND NOT EXISTS (
        SELECT 1 FROM users u2 WHERE u2.email = CONCAT(p.slug, '.', c.spec_slug, '.', c.zone_slug, '@teste.multilife.local'));

-- Perfil "profissional" para os novos
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
INNER JOIN roles r ON r.slug = 'profissional'
WHERE u.email LIKE '%@teste.multilife.local'
  AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id);

DROP TABLE IF EXISTS zz_seed_people, zz_seed_combos;

-- =============================================================================
-- 4) MODO DE TESTE DA CAPTAÇÃO LIGADO (só profissionais de teste nos grupos).
--    Para desligar depois: Implantação > Funcionalidades > "Modo de teste da captação".
-- =============================================================================
INSERT INTO admin_settings (setting_key, setting_value) VALUES ('feature.captacao_test_mode', '1')
ON DUPLICATE KEY UPDATE setting_value = '1';

-- =============================================================================
-- 5) 40 CARDS DE TESTE (Recebimento de E-mail, São Paulo/SP)
-- =============================================================================
-- [TESTE 01] Antônio Carlos Ferreira - Fisioterapia - Bela Vista (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 01] Fisioterapia - Antônio Carlos Ferreira',
  'Antônio Carlos Ferreira',
  'Fisioterapia',
  '3x_semana',
  'normal',
  1800.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Treze de Maio',
  '50',
  'Apto 42',
  'Bela Vista',
  '01327000',
  'Centro',
  'Rua Treze de Maio, 50 - Apto 42 - Bela Vista - São Paulo/SP - CEP 01327-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 72 anos, pós-AVC isquêmico há 40 dias, hemiparesia à direita, marcha com andador e afasia leve. Lúcido, colaborativo, alta hospitalar recente.

Serviços: Fisioterapia 3x por semana.

Dispositivos: nenhum. Convênio Unimed.',
  'Família solicita início imediato. Paciente reside com a esposa, casa térrea com rampa de acesso.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 01]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 22/11/2026
HORA: 08h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Antônio Carlos Ferreira

DATA DE NASCIMENTO: 19/11/1954

IDADE: 72 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua Treze de Maio, 50 - Apto 42 - Bela Vista - São Paulo/SP - CEP 01327-000

TELEFONE: (11) 90000-1000 - Patrícia (filha)

HD: AVC isquêmico com hemiparesia à direita.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família solicita início imediato. Paciente reside com a esposa, casa térrea com rampa de acesso.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação motora: treino de marcha, equilíbrio e fortalecimento do hemicorpo direito.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 02] Maria Helena Duarte - Fisioterapia - Santana (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 02] Fisioterapia - Maria Helena Duarte',
  'Maria Helena Duarte',
  'Fisioterapia',
  '5x_semana',
  'normal',
  3200.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Voluntários da Pátria',
  '123',
  'Casa 2',
  'Santana',
  '02011000',
  'Zona Norte',
  'Rua Voluntários da Pátria, 123 - Casa 2 - Santana - São Paulo/SP - CEP 02011-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 68 anos, pós-operatório de artroplastia total de quadril (15 dias), carga parcial com andador, dor controlada com analgesia oral.

Serviços: Fisioterapia 5x por semana.

Dispositivos: nenhum. Convênio Bradesco Saúde.',
  'Cirurgia realizada no Hospital Sírio-Libanês. Ortopedista libera carga progressiva.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 02]

PAD: Fisioterapia 5x por semana.

POSSÍVEL DATA: 23/11/2026
HORA: 09h30

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Maria Helena Duarte

DATA DE NASCIMENTO: 13/10/1958

IDADE: 68 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Voluntários da Pátria, 123 - Casa 2 - Santana - São Paulo/SP - CEP 02011-000

TELEFONE: (11) 90000-1001 - Ricardo (filho)

HD: Pós-operatório de artroplastia total de quadril esquerdo.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cirurgia realizada no Hospital Sírio-Libanês. Ortopedista libera carga progressiva.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia motora pós-artroplastia: ganho de amplitude, fortalecimento e treino de marcha.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 03] José Roberto Almeida - Fisioterapia - Vila Mariana (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 03] Fisioterapia - José Roberto Almeida',
  'José Roberto Almeida',
  'Fisioterapia',
  '3x_semana',
  'normal',
  1800.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Domingos de Morais',
  '196',
  'Apto 101, Bloco B',
  'Vila Mariana',
  '04010100',
  'Zona Sul',
  'Rua Domingos de Morais, 196 - Apto 101, Bloco B - Vila Mariana - São Paulo/SP - CEP 04010-100',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 79 anos, DPOC grave, em uso de oxigênio domiciliar 2 L/min por concentrador, dispneia aos médios esforços, internação recente por exacerbação.

Serviços: Fisioterapia 3x por semana.

Dispositivos: Concentrador de O2. Convênio SulAmérica Saúde.',
  'Paciente ex-tabagista. Necessita de orientação para técnicas de higiene brônquica.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 03]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 24/11/2026
HORA: 10h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: José Roberto Almeida

DATA DE NASCIMENTO: 06/09/1947

IDADE: 79 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Rua Domingos de Morais, 196 - Apto 101, Bloco B - Vila Mariana - São Paulo/SP - CEP 04010-100

TELEFONE: (11) 90000-1002 - Sandra (esposa)

HD: DPOC exacerbado com dependência de O2 domiciliar.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente ex-tabagista. Necessita de orientação para técnicas de higiene brônquica.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia respiratória: higiene brônquica, reexpansão pulmonar e condicionamento.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 04] Luiza Fernandes Rocha - Fisioterapia - Tatuapé (Zona Leste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 04] Fisioterapia - Luiza Fernandes Rocha',
  'Luiza Fernandes Rocha',
  'Fisioterapia',
  '2x_semana',
  'baixa',
  1200.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Tuiuti',
  '269',
  'Fundos',
  'Tatuapé',
  '03081000',
  'Zona Leste',
  'Rua Tuiuti, 269 - Fundos - Tatuapé - São Paulo/SP - CEP 03081-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 74 anos, Parkinson há 8 anos, bradicinesia, rigidez e episódios de congelamento da marcha. Duas quedas no último mês.

Serviços: Fisioterapia 2x por semana.

Dispositivos: nenhum. Convênio Amil.',
  'Família relata piora do equilíbrio. Paciente usa bengala dentro de casa.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 04]

PAD: Fisioterapia 2x por semana.

POSSÍVEL DATA: 25/11/2026
HORA: 13h30

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Luiza Fernandes Rocha

DATA DE NASCIMENTO: 31/07/1952

IDADE: 74 anos

CONVÊNIO: Amil

ENDEREÇO: Rua Tuiuti, 269 - Fundos - Tatuapé - São Paulo/SP - CEP 03081-000

TELEFONE: (11) 90000-1003 - Marcelo (marido)

HD: Doença de Parkinson estágio 3 (Hoehn & Yahr).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família relata piora do equilíbrio. Paciente usa bengala dentro de casa.

SERVIÇOS SOLICITADOS:
- Fisioterapia (2x por semana): Fisioterapia neurofuncional: prevenção de quedas, estratégias para congelamento e treino de marcha.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 05] Pedro Henrique Moraes - Fisioterapia - Pinheiros (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 05] Fisioterapia - Pedro Henrique Moraes',
  'Pedro Henrique Moraes',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2040.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua dos Pinheiros',
  '342',
  'Apto 73',
  'Pinheiros',
  '05422000',
  'Zona Oeste',
  'Rua dos Pinheiros, 342 - Apto 73 - Pinheiros - São Paulo/SP - CEP 05422-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Criança de 7 anos, criança com paralisia cerebral tetraparética espástica, sem controle de tronco, alimentação por gastrostomia, crises convulsivas controladas.

Serviços: Fisioterapia 3x por semana.

Dispositivos: GTT. Convênio Porto Seguro Saúde.',
  'Mãe é a cuidadora principal. Paciente frequenta escola especial no período da manhã: atendimentos à tarde.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 05]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 26/11/2026
HORA: 14h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Pedro Henrique Moraes

DATA DE NASCIMENTO: 24/06/2019

IDADE: 7 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua dos Pinheiros, 342 - Apto 73 - Pinheiros - São Paulo/SP - CEP 05422-000

TELEFONE: (11) 90000-1004 - Adriana (mãe)

HD: Paralisia cerebral tetraparética espástica.

DISPOSITIVO: (X) SIM = QUAL? GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Mãe é a cuidadora principal. Paciente frequenta escola especial no período da manhã: atendimentos à tarde.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia neuropediátrica: controle de tronco, alongamentos e posicionamento.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 06] Sebastião Lima Costa - Fisioterapia - Consolação (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 06] Fisioterapia - Sebastião Lima Costa',
  'Sebastião Lima Costa',
  'Fisioterapia',
  '7x_semana',
  'urgente',
  5400.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua da Consolação',
  '415',
  NULL,
  'Consolação',
  '01301000',
  'Centro',
  'Rua da Consolação, 415 - Consolação - São Paulo/SP - CEP 01301-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 58 anos, ELA com evolução de 3 anos, traqueostomizado, ventilação mecânica noturna, alimentação por gastrostomia. Comunica-se por prancha.

Serviços: Fisioterapia diariamente.

Dispositivos: TQT + VM noturna + GTT. Convênio Prevent Senior.',
  'Paciente com home care há 1 ano por outra empresa: troca de prestador.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 06]

PAD: Fisioterapia diariamente.

POSSÍVEL DATA: 27/11/2026
HORA: 15h30

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Sebastião Lima Costa

DATA DE NASCIMENTO: 18/05/1968

IDADE: 58 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua da Consolação, 415 - Consolação - São Paulo/SP - CEP 01301-000

TELEFONE: (11) 90000-1005 - Eduardo (neta)

HD: Esclerose lateral amiotrófica (ELA).

DISPOSITIVO: (X) SIM = QUAL? TQT + VM noturna + GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente com home care há 1 ano por outra empresa: troca de prestador.

SERVIÇOS SOLICITADOS:
- Fisioterapia (diariamente): Fisioterapia respiratória diária: aspiração, manejo da VM e mobilização.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 07] Aparecida dos Santos Nogueira - Fisioterapia - Tucuruvi (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 07] Fisioterapia - Aparecida dos Santos Nogueira',
  'Aparecida dos Santos Nogueira',
  'Fisioterapia',
  '3x_semana',
  'normal',
  1800.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Avenida Tucuruvi',
  '488',
  'Sobrado',
  'Tucuruvi',
  '02304000',
  'Zona Norte',
  'Avenida Tucuruvi, 488 - Sobrado - Tucuruvi - São Paulo/SP - CEP 02304-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 83 anos, fratura transtrocantérica de fêmur direito após queda, osteossíntese há 3 semanas. Carga parcial liberada, medo de cair.

Serviços: Fisioterapia 3x por semana.

Dispositivos: nenhum. Convênio NotreDame Intermédica.',
  'Paciente mora sozinha, filha visita diariamente. Avaliar adaptação do banheiro.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 07]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 28/11/2026
HORA: 16h00

SOLICITANTE: Renata Moura

NOME DO PACIENTE: Aparecida dos Santos Nogueira

DATA DE NASCIMENTO: 11/04/1943

IDADE: 83 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Avenida Tucuruvi, 488 - Sobrado - Tucuruvi - São Paulo/SP - CEP 02304-000

TELEFONE: (11) 90000-1006 - Cristina (irmã)

HD: Fratura de fêmur proximal com osteossíntese.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente mora sozinha, filha visita diariamente. Avaliar adaptação do banheiro.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia motora: carga progressiva, fortalecimento e treino de transferências.

Atenciosamente,
Renata Moura
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'NotreDame%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'NotreDame%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 08] Rafael Augusto Teixeira - Fisioterapia - Moema (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 08] Fisioterapia - Rafael Augusto Teixeira',
  'Rafael Augusto Teixeira',
  'Fisioterapia',
  '5x_semana',
  'normal',
  3400.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Alameda dos Maracatins',
  '561',
  'Apto 15',
  'Moema',
  '04089000',
  'Zona Sul',
  'Alameda dos Maracatins, 561 - Apto 15 - Moema - São Paulo/SP - CEP 04089-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 29 anos, tetraplegia incompleta (C6) após acidente de moto há 6 meses, cadeirante, sonda vesical de demora, lesão por pressão sacral em cicatrização.

Serviços: Fisioterapia 5x por semana.

Dispositivos: SVD. Convênio Hapvida.',
  'Paciente jovem e motivado. Família solicita foco em independência para atividades de vida diária.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 08]

PAD: Fisioterapia 5x por semana.

POSSÍVEL DATA: 29/11/2026
HORA: 17h00

SOLICITANTE: Carla Menezes

NOME DO PACIENTE: Rafael Augusto Teixeira

DATA DE NASCIMENTO: 05/03/1997

IDADE: 29 anos

CONVÊNIO: Hapvida

ENDEREÇO: Alameda dos Maracatins, 561 - Apto 15 - Moema - São Paulo/SP - CEP 04089-000

TELEFONE: (11) 90000-1007 - Fernando (nora)

HD: Lesão medular cervical C6 pós-trauma.

DISPOSITIVO: (X) SIM = QUAL? SVD  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente jovem e motivado. Família solicita foco em independência para atividades de vida diária.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia neurofuncional: fortalecimento de membros superiores, transferências e manejo da cadeira de rodas.

Atenciosamente,
Carla Menezes
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 09] Iracema Batista Leal - Fonoaudiologia - Mooca (Zona Leste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 09] Fonoaudiologia - Iracema Batista Leal',
  'Iracema Batista Leal',
  'Fonoaudiologia',
  '2x_semana',
  'normal',
  1120.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua da Mooca',
  '634',
  'Apto 42',
  'Mooca',
  '03104000',
  'Zona Leste',
  'Rua da Mooca, 634 - Apto 42 - Mooca - São Paulo/SP - CEP 03104-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 86 anos, Alzheimer moderado, desorientado, tosse durante as refeições e perda de peso de 6 kg em 3 meses.

Serviços: Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Unimed.',
  'Cuidadora contratada pela família durante o dia. Atendimentos preferencialmente pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 09]

PAD: Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 30/11/2026
HORA: 08h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Iracema Batista Leal

DATA DE NASCIMENTO: 28/01/1940

IDADE: 86 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua da Mooca, 634 - Apto 42 - Mooca - São Paulo/SP - CEP 03104-000

TELEFONE: (11) 90000-1008 - Patrícia (filha)

HD: Doença de Alzheimer moderada com disfagia.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cuidadora contratada pela família durante o dia. Atendimentos preferencialmente pela manhã.

SERVIÇOS SOLICITADOS:
- Fonoaudiologia (2x por semana): Avaliação e reabilitação da deglutição, adaptação de consistências.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 10] Cláudio Mendes Barros - Fisioterapia - Perdizes (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 10] Fisioterapia - Cláudio Mendes Barros',
  'Cláudio Mendes Barros',
  'Fisioterapia',
  '5x_semana',
  'normal',
  3000.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Cardoso de Almeida',
  '707',
  'Casa 2',
  'Perdizes',
  '05013000',
  'Zona Oeste',
  'Rua Cardoso de Almeida, 707 - Casa 2 - Perdizes - São Paulo/SP - CEP 05013-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 61 anos, pós-COVID com internação em UTI por 20 dias, fraqueza muscular global e dispneia aos pequenos esforços. Deambula com auxílio.

Serviços: Fisioterapia 5x por semana.

Dispositivos: nenhum. Convênio Bradesco Saúde.',
  'Paciente retornou do hospital há 1 semana. Saturação estável em ar ambiente.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 10]

PAD: Fisioterapia 5x por semana.

POSSÍVEL DATA: 01/12/2026
HORA: 09h30

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Cláudio Mendes Barros

DATA DE NASCIMENTO: 17/10/1965

IDADE: 61 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Cardoso de Almeida, 707 - Casa 2 - Perdizes - São Paulo/SP - CEP 05013-000

TELEFONE: (11) 90000-1009 - Ricardo (filho)

HD: Síndrome pós-COVID com fraqueza muscular.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente retornou do hospital há 1 semana. Saturação estável em ar ambiente.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia motora e respiratória: recondicionamento e treino de marcha.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 11] Yolanda Ribeiro Prado - Fisioterapia - Liberdade (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 11] Fisioterapia - Yolanda Ribeiro Prado',
  'Yolanda Ribeiro Prado',
  'Fisioterapia',
  '3x_semana',
  'urgente',
  1920.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Galvão Bueno',
  '780',
  'Apto 101, Bloco B',
  'Liberdade',
  '01506000',
  'Centro',
  'Rua Galvão Bueno, 780 - Apto 101, Bloco B - Liberdade - São Paulo/SP - CEP 01506-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 70 anos, câncer de pulmão metastático em cuidados paliativos exclusivos, dispneia, dor controlada com morfina, acamado na maior parte do dia.

Serviços: Fisioterapia 3x por semana.

Dispositivos: Concentrador de O2. Convênio SulAmérica Saúde.',
  'Foco em conforto. Família muito presente, solicita profissionais com experiência em paliativos.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 11]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 02/12/2026
HORA: 10h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Yolanda Ribeiro Prado

DATA DE NASCIMENTO: 10/09/1956

IDADE: 70 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Rua Galvão Bueno, 780 - Apto 101, Bloco B - Liberdade - São Paulo/SP - CEP 01506-000

TELEFONE: (11) 90000-1010 - Sandra (esposa)

HD: Neoplasia de pulmão em cuidados paliativos.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Foco em conforto. Família muito presente, solicita profissionais com experiência em paliativos.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia paliativa: conforto respiratório, posicionamento e prevenção de lesões.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 12] Valdir Gonçalves Pinto - Fisioterapia - Casa Verde (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 12] Fisioterapia - Valdir Gonçalves Pinto',
  'Valdir Gonçalves Pinto',
  'Fisioterapia',
  '3x_semana',
  'normal',
  1800.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Avenida Casa Verde',
  '853',
  'Fundos',
  'Casa Verde',
  '02519000',
  'Zona Norte',
  'Avenida Casa Verde, 853 - Fundos - Casa Verde - São Paulo/SP - CEP 02519-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 64 anos, amputação transtibial esquerda há 2 meses por pé diabético, coto cicatrizado, aguardando prótese. Diabetes tipo 2 insulinodependente.

Serviços: Fisioterapia 3x por semana.

Dispositivos: nenhum. Convênio Amil.',
  'Paciente em fase pré-protetização. Ortopedista solicita preparo do coto.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 12]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 03/12/2026
HORA: 13h30

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Valdir Gonçalves Pinto

DATA DE NASCIMENTO: 04/08/1962

IDADE: 64 anos

CONVÊNIO: Amil

ENDEREÇO: Avenida Casa Verde, 853 - Fundos - Casa Verde - São Paulo/SP - CEP 02519-000

TELEFONE: (11) 90000-1011 - Marcelo (marido)

HD: Amputação transtibial esquerda (pé diabético).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente em fase pré-protetização. Ortopedista solicita preparo do coto.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia pré-protetização: enfaixamento do coto, fortalecimento e treino de transferências.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 13] Sofia Martins Araújo - Fisioterapia - Saúde (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 13] Fisioterapia - Sofia Martins Araújo',
  'Sofia Martins Araújo',
  'Fisioterapia',
  '2x_semana',
  'normal',
  1280.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Avenida Jabaquara',
  '926',
  'Apto 73',
  'Saúde',
  '04046000',
  'Zona Sul',
  'Avenida Jabaquara, 926 - Apto 73 - Saúde - São Paulo/SP - CEP 04046-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Criança de 3 anos, criança com síndrome de Down, hipotonia global, ainda não anda sem apoio. Cardiopatia corrigida no primeiro ano.

Serviços: Fisioterapia 2x por semana.

Dispositivos: nenhum. Convênio Porto Seguro Saúde.',
  'Família solicita atendimento em casa pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 13]

PAD: Fisioterapia 2x por semana.

POSSÍVEL DATA: 22/11/2026
HORA: 14h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Sofia Martins Araújo

DATA DE NASCIMENTO: 28/06/2023

IDADE: 3 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Avenida Jabaquara, 926 - Apto 73 - Saúde - São Paulo/SP - CEP 04046-000

TELEFONE: (11) 90000-1012 - Adriana (mãe)

HD: Síndrome de Down com atraso do desenvolvimento motor.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família solicita atendimento em casa pela manhã.

SERVIÇOS SOLICITADOS:
- Fisioterapia (2x por semana): Fisioterapia neuropediátrica: estimulação motora e aquisição de marcha.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 14] Benedito Carvalho Reis - Fisioterapia - Penha (Zona Leste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 14] Fisioterapia - Benedito Carvalho Reis',
  'Benedito Carvalho Reis',
  'Fisioterapia',
  '3x_semana',
  'normal',
  1920.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Doutor João Ribeiro',
  '999',
  NULL,
  'Penha',
  '03634000',
  'Zona Leste',
  'Rua Doutor João Ribeiro, 999 - Penha - São Paulo/SP - CEP 03634-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 77 anos, insuficiência cardíaca com fração de ejeção de 30%, edema de membros inferiores, cansaço aos pequenos esforços.

Serviços: Fisioterapia 3x por semana.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Cardiologista solicita reabilitação cardiovascular leve e controle de peso diário.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 14]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 23/11/2026
HORA: 15h30

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Benedito Carvalho Reis

DATA DE NASCIMENTO: 22/05/1949

IDADE: 77 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Doutor João Ribeiro, 999 - Penha - São Paulo/SP - CEP 03634-000

TELEFONE: (11) 90000-1013 - Eduardo (neta)

HD: Insuficiência cardíaca classe funcional III.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cardiologista solicita reabilitação cardiovascular leve e controle de peso diário.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação cardiovascular leve com monitorização de sinais vitais.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 15] Odete Machado Silveira - Enfermagem - Lapa (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 15] Enfermagem - Odete Machado Silveira',
  'Odete Machado Silveira',
  'Enfermagem',
  '3x_semana',
  'normal',
  2280.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Doze de Outubro',
  '1072',
  'Sobrado',
  'Lapa',
  '05073000',
  'Zona Oeste',
  'Rua Doze de Outubro, 1072 - Sobrado - Lapa - São Paulo/SP - CEP 05073-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 81 anos, acamado, lesão por pressão sacral grau 3 com tecido de granulação, sem sinais de infecção. Mobilidade muito reduzida.

Serviços: Enfermagem 3x por semana.

Dispositivos: nenhum. Convênio NotreDame Intermédica.',
  'Curativo com cobertura especial fornecida pelo convênio.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 15]

PAD: Enfermagem 3x por semana.

POSSÍVEL DATA: 24/11/2026
HORA: 16h00

SOLICITANTE: Renata Moura

NOME DO PACIENTE: Odete Machado Silveira

DATA DE NASCIMENTO: 15/04/1945

IDADE: 81 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Rua Doze de Outubro, 1072 - Sobrado - Lapa - São Paulo/SP - CEP 05073-000

TELEFONE: (11) 90000-1014 - Cristina (irmã)

HD: Lesão por pressão sacral grau 3.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Curativo com cobertura especial fornecida pelo convênio.

SERVIÇOS SOLICITADOS:
- Enfermagem (3x por semana): Curativo especial da lesão por pressão e orientação de mudança de decúbito.

Atenciosamente,
Renata Moura
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'NotreDame%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'NotreDame%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 16] Thiago Rodrigues Paiva - Fisioterapia - Santa Cecília (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 16] Fisioterapia - Thiago Rodrigues Paiva',
  'Thiago Rodrigues Paiva',
  'Fisioterapia',
  '7x_semana',
  'urgente',
  5400.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua das Palmeiras',
  '1145',
  'Apto 15',
  'Santa Cecília',
  '01226010',
  'Centro',
  'Rua das Palmeiras, 1145 - Apto 15 - Santa Cecília - São Paulo/SP - CEP 01226-010',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 34 anos, TCE grave após atropelamento, estado de mínima consciência, traqueostomizado e com gastrostomia. Espasticidade em membros.

Serviços: Fisioterapia diariamente.

Dispositivos: TQT + GTT. Convênio Hapvida.',
  'Desospitalização programada para a próxima semana.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 16]

PAD: Fisioterapia diariamente.

POSSÍVEL DATA: 25/11/2026
HORA: 17h00

SOLICITANTE: Carla Menezes

NOME DO PACIENTE: Thiago Rodrigues Paiva

DATA DE NASCIMENTO: 09/03/1992

IDADE: 34 anos

CONVÊNIO: Hapvida

ENDEREÇO: Rua das Palmeiras, 1145 - Apto 15 - Santa Cecília - São Paulo/SP - CEP 01226-010

TELEFONE: (11) 90000-1015 - Fernando (nora)

HD: Traumatismo cranioencefálico grave.

DISPOSITIVO: (X) SIM = QUAL? TQT + GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Desospitalização programada para a próxima semana.

SERVIÇOS SOLICITADOS:
- Fisioterapia (diariamente): Fisioterapia respiratória e motora diária: aspiração, posicionamento e alongamentos.

Atenciosamente,
Carla Menezes
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 17] Renata Cristina Lopes - Fisioterapia - Vila Guilherme (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 17] Fisioterapia - Renata Cristina Lopes',
  'Renata Cristina Lopes',
  'Fisioterapia',
  '2x_semana',
  'normal',
  1200.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Chico Pontes',
  '1218',
  'Apto 42',
  'Vila Guilherme',
  '02067000',
  'Zona Norte',
  'Rua Chico Pontes, 1218 - Apto 42 - Vila Guilherme - São Paulo/SP - CEP 02067-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 41 anos, esclerose múltipla após surto recente, fraqueza em membros inferiores e fadiga. Marcha com apoio bilateral.

Serviços: Fisioterapia 2x por semana.

Dispositivos: nenhum. Convênio Unimed.',
  'Paciente trabalha em home office: preferência por atendimentos no fim da tarde.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 17]

PAD: Fisioterapia 2x por semana.

POSSÍVEL DATA: 26/11/2026
HORA: 08h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Renata Cristina Lopes

DATA DE NASCIMENTO: 31/01/1985

IDADE: 41 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua Chico Pontes, 1218 - Apto 42 - Vila Guilherme - São Paulo/SP - CEP 02067-000

TELEFONE: (11) 90000-1016 - Patrícia (filha)

HD: Esclerose múltipla forma remitente-recorrente.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente trabalha em home office: preferência por atendimentos no fim da tarde.

SERVIÇOS SOLICITADOS:
- Fisioterapia (2x por semana): Fisioterapia neurofuncional: fortalecimento, equilíbrio e manejo da fadiga.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 18] Geraldo Antunes Faria - Fisioterapia - Santo Amaro (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 18] Fisioterapia - Geraldo Antunes Faria',
  'Geraldo Antunes Faria',
  'Fisioterapia',
  '3x_semana',
  'baixa',
  1920.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Padre José de Anchieta',
  '1291',
  'Casa 2',
  'Santo Amaro',
  '04742000',
  'Zona Sul',
  'Rua Padre José de Anchieta, 1291 - Casa 2 - Santo Amaro - São Paulo/SP - CEP 04742-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 66 anos, pós-operatório de revascularização do miocárdio (3 pontes) há 12 dias, esternotomia em cicatrização, deambula com supervisão.

Serviços: Fisioterapia 3x por semana.

Dispositivos: nenhum. Convênio Bradesco Saúde.',
  'Cardiologista orienta reabilitação fase II domiciliar até liberação para academia.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 18]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 27/11/2026
HORA: 09h30

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Geraldo Antunes Faria

DATA DE NASCIMENTO: 21/10/1960

IDADE: 66 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Padre José de Anchieta, 1291 - Casa 2 - Santo Amaro - São Paulo/SP - CEP 04742-000

TELEFONE: (11) 90000-1017 - Ricardo (filho)

HD: Pós-operatório de revascularização do miocárdio.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cardiologista orienta reabilitação fase II domiciliar até liberação para academia.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação cardíaca fase II e fisioterapia respiratória.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 19] Neusa Aparecida Campos - Fisioterapia - Vila Formosa (Zona Leste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 19] Fisioterapia - Neusa Aparecida Campos',
  'Neusa Aparecida Campos',
  'Fisioterapia',
  '2x_semana',
  'normal',
  1200.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Avenida Renata',
  '1364',
  'Apto 101, Bloco B',
  'Vila Formosa',
  '03377000',
  'Zona Leste',
  'Avenida Renata, 1364 - Apto 101, Bloco B - Vila Formosa - São Paulo/SP - CEP 03377-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 69 anos, artrite reumatoide de longa data, rigidez matinal, deformidades em mãos e dor em joelhos. Dificuldade para levantar da cadeira.

Serviços: Fisioterapia 2x por semana.

Dispositivos: nenhum. Convênio SulAmérica Saúde.',
  'Reumatologista solicita fisioterapia para manutenção funcional.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 19]

PAD: Fisioterapia 2x por semana.

POSSÍVEL DATA: 28/11/2026
HORA: 10h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Neusa Aparecida Campos

DATA DE NASCIMENTO: 14/09/1957

IDADE: 69 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Avenida Renata, 1364 - Apto 101, Bloco B - Vila Formosa - São Paulo/SP - CEP 03377-000

TELEFONE: (11) 90000-1018 - Sandra (esposa)

HD: Artrite reumatoide com rigidez articular.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Reumatologista solicita fisioterapia para manutenção funcional.

SERVIÇOS SOLICITADOS:
- Fisioterapia (2x por semana): Fisioterapia para manutenção da amplitude articular, fortalecimento e analgesia.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 20] Miguel Souza Brandão - Fisioterapia - Butantã (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 20] Fisioterapia - Miguel Souza Brandão',
  'Miguel Souza Brandão',
  'Fisioterapia',
  '3x_semana',
  'urgente',
  2160.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Alvarenga',
  '1437',
  'Fundos',
  'Butantã',
  '05509000',
  'Zona Oeste',
  'Rua Alvarenga, 1437 - Fundos - Butantã - São Paulo/SP - CEP 05509-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Lactente de 3 meses, lactente nascido com 28 semanas, displasia broncopulmonar, em uso de O2 por cateter nasal 0,5 L/min. Ganho de peso lento.

Serviços: Fisioterapia 3x por semana.

Dispositivos: O2 por cateter nasal. Convênio Amil.',
  'Alta da UTI neonatal há 10 dias. Mãe e avó revezam nos cuidados.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 20]

PAD: Fisioterapia 3x por semana.

POSSÍVEL DATA: 29/11/2026
HORA: 13h30

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Miguel Souza Brandão

DATA DE NASCIMENTO: 08/08/2026

IDADE: 3 meses

CONVÊNIO: Amil

ENDEREÇO: Rua Alvarenga, 1437 - Fundos - Butantã - São Paulo/SP - CEP 05509-000

TELEFONE: (11) 90000-1019 - Marcelo (marido)

HD: Prematuridade com displasia broncopulmonar.

DISPOSITIVO: (X) SIM = QUAL? O2 por cateter nasal  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Alta da UTI neonatal há 10 dias. Mãe e avó revezam nos cuidados.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia respiratória pediátrica e estimulação motora.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  0,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 21] Hélio Batista Monteiro - 3 especialidades - Higienópolis (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 21] Multidisciplinar - Hélio Batista Monteiro',
  'Hélio Batista Monteiro',
  'Fisioterapia',
  '3x_semana',
  'normal',
  3440.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Maranhão',
  '1510',
  'Apto 73',
  'Higienópolis',
  '01240000',
  'Centro',
  'Rua Maranhão, 1510 - Apto 73 - Higienópolis - São Paulo/SP - CEP 01240-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 72 anos, pós-AVC isquêmico há 40 dias, hemiparesia à direita, marcha com andador e afasia leve. Lúcido, colaborativo, alta hospitalar recente.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana, Psicologia 1x por semana.

Dispositivos: nenhum. Convênio Porto Seguro Saúde.',
  'Família solicita início imediato. Paciente reside com a esposa, casa térrea com rampa de acesso.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 21]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana + Psicologia 1x por semana.

POSSÍVEL DATA: 30/11/2026
HORA: 14h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Hélio Batista Monteiro

DATA DE NASCIMENTO: 02/07/1954

IDADE: 72 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua Maranhão, 1510 - Apto 73 - Higienópolis - São Paulo/SP - CEP 01240-000

TELEFONE: (11) 90000-1020 - Adriana (mãe)

HD: AVC isquêmico com hemiparesia à direita.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família solicita início imediato. Paciente reside com a esposa, casa térrea com rampa de acesso.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação motora: treino de marcha, equilíbrio e fortalecimento do hemicorpo direito.
- Fonoaudiologia (2x por semana): Terapia para afasia leve e avaliação de deglutição.
- Psicologia (1x por semana): Suporte emocional ao paciente e à esposa (cuidadora).

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Reabilitação motora: treino de marcha, equilíbrio e fortalecimento do hemicorpo direito.', 'São Paulo', 'SP', 'Rua Maranhão', 'Higienópolis', '1510', 'Apto 73', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Terapia para afasia leve e avaliação de deglutição.', 'São Paulo', 'SP', 'Rua Maranhão', 'Higienópolis', '1510', 'Apto 73', 1120.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Suporte emocional ao paciente e à esposa (cuidadora).', 'São Paulo', 'SP', 'Rua Maranhão', 'Higienópolis', '1510', 'Apto 73', 520.00, 'normal', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 22] Terezinha Alves Correia - 2 especialidades - Mandaqui (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 22] Multidisciplinar - Terezinha Alves Correia',
  'Terezinha Alves Correia',
  'Fisioterapia',
  '5x_semana',
  'normal',
  4640.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Conselheiro Moreira de Barros',
  '1583',
  NULL,
  'Mandaqui',
  '02430000',
  'Zona Norte',
  'Rua Conselheiro Moreira de Barros, 1583 - Mandaqui - São Paulo/SP - CEP 02430-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 68 anos, pós-operatório de artroplastia total de quadril (15 dias), carga parcial com andador, dor controlada com analgesia oral.

Serviços: Fisioterapia 5x por semana, Enfermagem 2x por semana.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Cirurgia realizada no Hospital Sírio-Libanês. Ortopedista libera carga progressiva.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 22]

PAD: Fisioterapia 5x por semana + Enfermagem 2x por semana.

POSSÍVEL DATA: 01/12/2026
HORA: 15h30

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Terezinha Alves Correia

DATA DE NASCIMENTO: 26/05/1958

IDADE: 68 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Conselheiro Moreira de Barros, 1583 - Mandaqui - São Paulo/SP - CEP 02430-000

TELEFONE: (11) 90000-1021 - Eduardo (neta)

HD: Pós-operatório de artroplastia total de quadril esquerdo.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cirurgia realizada no Hospital Sírio-Libanês. Ortopedista libera carga progressiva.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia motora pós-artroplastia: ganho de amplitude, fortalecimento e treino de marcha.
- Enfermagem (2x por semana): Curativo da ferida operatória e retirada de pontos.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia motora pós-artroplastia: ganho de amplitude, fortalecimento e treino de marcha.', 'São Paulo', 'SP', 'Rua Conselheiro Moreira de Barros', 'Mandaqui', '1583', NULL, 3200.00, 'normal', '5x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Curativo da ferida operatória e retirada de pontos.', 'São Paulo', 'SP', 'Rua Conselheiro Moreira de Barros', 'Mandaqui', '1583', NULL, 1440.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 23] Osvaldo Pereira Cunha - 3 especialidades - Campo Belo (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 23] Multidisciplinar - Osvaldo Pereira Cunha',
  'Osvaldo Pereira Cunha',
  'Fisioterapia',
  '5x_semana',
  'normal',
  3920.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Vieira de Morais',
  '1656',
  'Sobrado',
  'Campo Belo',
  '04617000',
  'Zona Sul',
  'Rua Vieira de Morais, 1656 - Sobrado - Campo Belo - São Paulo/SP - CEP 04617-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 79 anos, DPOC grave, em uso de oxigênio domiciliar 2 L/min por concentrador, dispneia aos médios esforços, internação recente por exacerbação.

Serviços: Fisioterapia 5x por semana, Enfermagem 1x por semana, Nutrição 1x por mês.

Dispositivos: Concentrador de O2. Convênio NotreDame Intermédica.',
  'Paciente ex-tabagista. Necessita de orientação para técnicas de higiene brônquica.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 23]

PAD: Fisioterapia 5x por semana + Enfermagem 1x por semana + Nutrição 1x por mês.

POSSÍVEL DATA: 02/12/2026
HORA: 16h00

SOLICITANTE: Renata Moura

NOME DO PACIENTE: Osvaldo Pereira Cunha

DATA DE NASCIMENTO: 19/04/1947

IDADE: 79 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Rua Vieira de Morais, 1656 - Sobrado - Campo Belo - São Paulo/SP - CEP 04617-000

TELEFONE: (11) 90000-1022 - Cristina (irmã)

HD: DPOC exacerbado com dependência de O2 domiciliar.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente ex-tabagista. Necessita de orientação para técnicas de higiene brônquica.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia respiratória e motora: higiene brônquica e condicionamento.
- Enfermagem (1x por semana): Monitorização de sinais vitais, saturação e orientação sobre O2.
- Nutrição (1x por mês): Avaliação nutricional: perda de peso recente e baixa ingesta.

Atenciosamente,
Renata Moura
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'NotreDame%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'NotreDame%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória e motora: higiene brônquica e condicionamento.', 'São Paulo', 'SP', 'Rua Vieira de Morais', 'Campo Belo', '1656', 'Sobrado', 3000.00, 'normal', '5x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Monitorização de sinais vitais, saturação e orientação sobre O2.', 'São Paulo', 'SP', 'Rua Vieira de Morais', 'Campo Belo', '1656', 'Sobrado', 720.00, 'normal', '1x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Avaliação nutricional: perda de peso recente e baixa ingesta.', 'São Paulo', 'SP', 'Rua Vieira de Morais', 'Campo Belo', '1656', 'Sobrado', 200.00, 'normal', 'mensal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 24] Marlene Dias Fontes - 2 especialidades - Itaquera (Zona Leste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 24] Multidisciplinar - Marlene Dias Fontes',
  'Marlene Dias Fontes',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2920.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Avenida Itaquera',
  '1729',
  'Apto 15',
  'Itaquera',
  '08210000',
  'Zona Leste',
  'Avenida Itaquera, 1729 - Apto 15 - Itaquera - São Paulo/SP - CEP 08210-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 74 anos, Parkinson há 8 anos, bradicinesia, rigidez e episódios de congelamento da marcha. Duas quedas no último mês.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Hapvida.',
  'Família relata piora do equilíbrio. Paciente usa bengala dentro de casa.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 24]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 03/12/2026
HORA: 17h00

SOLICITANTE: Carla Menezes

NOME DO PACIENTE: Marlene Dias Fontes

DATA DE NASCIMENTO: 13/03/1952

IDADE: 74 anos

CONVÊNIO: Hapvida

ENDEREÇO: Avenida Itaquera, 1729 - Apto 15 - Itaquera - São Paulo/SP - CEP 08210-000

TELEFONE: (11) 90000-1023 - Fernando (nora)

HD: Doença de Parkinson estágio 3 (Hoehn & Yahr).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família relata piora do equilíbrio. Paciente usa bengala dentro de casa.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia neurofuncional: prevenção de quedas e treino de marcha.
- Fonoaudiologia (2x por semana): Disfagia leve e hipofonia: terapia de voz e deglutição.

Atenciosamente,
Carla Menezes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia neurofuncional: prevenção de quedas e treino de marcha.', 'São Paulo', 'SP', 'Avenida Itaquera', 'Itaquera', '1729', 'Apto 15', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Disfagia leve e hipofonia: terapia de voz e deglutição.', 'São Paulo', 'SP', 'Avenida Itaquera', 'Itaquera', '1729', 'Apto 15', 1120.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 25] Gabriel Martins Costa - 4 especialidades - Vila Leopoldina (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 25] Multidisciplinar - Gabriel Martins Costa',
  'Gabriel Martins Costa',
  'Fisioterapia',
  '3x_semana',
  'baixa',
  13040.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Schilling',
  '1802',
  'Apto 42',
  'Vila Leopoldina',
  '05302000',
  'Zona Oeste',
  'Rua Schilling, 1802 - Apto 42 - Vila Leopoldina - São Paulo/SP - CEP 05302-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Criança de 7 anos, criança com paralisia cerebral tetraparética espástica, sem controle de tronco, alimentação por gastrostomia, crises convulsivas controladas.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana, Técnico de Enfermagem diariamente, Nutrição 1x por mês.

Dispositivos: GTT. Convênio Unimed.',
  'Mãe é a cuidadora principal. Paciente frequenta escola especial no período da manhã: atendimentos à tarde.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 25]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana + Técnico de Enfermagem diariamente + Nutrição 1x por mês.

POSSÍVEL DATA: 22/11/2026
HORA: 08h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Gabriel Martins Costa

DATA DE NASCIMENTO: 04/02/2019

IDADE: 7 anos

CONVÊNIO: Unimed

ENDEREÇO: Rua Schilling, 1802 - Apto 42 - Vila Leopoldina - São Paulo/SP - CEP 05302-000

TELEFONE: (11) 90000-1024 - Patrícia (filha)

HD: Paralisia cerebral tetraparética espástica.

DISPOSITIVO: (X) SIM = QUAL? GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Mãe é a cuidadora principal. Paciente frequenta escola especial no período da manhã: atendimentos à tarde.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia neuropediátrica: controle de tronco, alongamentos e posicionamento.
- Fonoaudiologia (2x por semana): Estimulação oral e orientação de manejo da GTT.
- Técnico de Enfermagem (diariamente): Técnico de enfermagem 12h diurno: cuidados com GTT, medicações e higiene.
- Nutrição (1x por mês): Ajuste de dieta enteral pela GTT.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia neuropediátrica: controle de tronco, alongamentos e posicionamento.', 'São Paulo', 'SP', 'Rua Schilling', 'Vila Leopoldina', '1802', 'Apto 42', 2040.00, 'baixa', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Estimulação oral e orientação de manejo da GTT.', 'São Paulo', 'SP', 'Rua Schilling', 'Vila Leopoldina', '1802', 'Apto 42', 1200.00, 'baixa', '2x_semana', 'pendente'),
  (@zz_d, 'Técnico de Enfermagem', 'Técnico de enfermagem 12h diurno: cuidados com GTT, medicações e higiene.', 'São Paulo', 'SP', 'Rua Schilling', 'Vila Leopoldina', '1802', 'Apto 42', 9600.00, 'baixa', '7x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Ajuste de dieta enteral pela GTT.', 'São Paulo', 'SP', 'Rua Schilling', 'Vila Leopoldina', '1802', 'Apto 42', 200.00, 'baixa', 'mensal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 26] Ernesto Vieira Sales - 5 especialidades - Aclimação (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 26] Multidisciplinar - Ernesto Vieira Sales',
  'Ernesto Vieira Sales',
  'Fisioterapia',
  '7x_semana',
  'urgente',
  17820.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Pires da Mota',
  '1875',
  'Casa 2',
  'Aclimação',
  '01529000',
  'Centro',
  'Rua Pires da Mota, 1875 - Casa 2 - Aclimação - São Paulo/SP - CEP 01529-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 58 anos, ELA com evolução de 3 anos, traqueostomizado, ventilação mecânica noturna, alimentação por gastrostomia. Comunica-se por prancha.

Serviços: Fisioterapia diariamente, Técnico de Enfermagem diariamente, Enfermagem 1x por semana, Fonoaudiologia 1x por semana, Psicologia 1x por semana.

Dispositivos: TQT + VM noturna + GTT. Convênio Bradesco Saúde.',
  'Paciente com home care há 1 ano por outra empresa: troca de prestador.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 26]

PAD: Fisioterapia diariamente + Técnico de Enfermagem diariamente + Enfermagem 1x por semana + Fonoaudiologia 1x por semana + Psicologia 1x por semana.

POSSÍVEL DATA: 23/11/2026
HORA: 09h30

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Ernesto Vieira Sales

DATA DE NASCIMENTO: 25/10/1968

IDADE: 58 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Pires da Mota, 1875 - Casa 2 - Aclimação - São Paulo/SP - CEP 01529-000

TELEFONE: (11) 90000-1025 - Ricardo (filho)

HD: Esclerose lateral amiotrófica (ELA).

DISPOSITIVO: (X) SIM = QUAL? TQT + VM noturna + GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente com home care há 1 ano por outra empresa: troca de prestador.

SERVIÇOS SOLICITADOS:
- Fisioterapia (diariamente): Fisioterapia respiratória diária: aspiração, manejo da VM e mobilização.
- Técnico de Enfermagem (diariamente): Técnico de enfermagem 24h (plantões 12x36).
- Enfermagem (1x por semana): Visita do enfermeiro: troca de cânula, curativo de TQT e GTT.
- Fonoaudiologia (1x por semana): Comunicação alternativa e avaliação de deglutição de saliva.
- Psicologia (1x por semana): Suporte psicológico ao paciente e à família.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória diária: aspiração, manejo da VM e mobilização.', 'São Paulo', 'SP', 'Rua Pires da Mota', 'Aclimação', '1875', 'Casa 2', 5400.00, 'urgente', '7x_semana', 'pendente'),
  (@zz_d, 'Técnico de Enfermagem', 'Técnico de enfermagem 24h (plantões 12x36).', 'São Paulo', 'SP', 'Rua Pires da Mota', 'Aclimação', '1875', 'Casa 2', 10500.00, 'urgente', '7x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Visita do enfermeiro: troca de cânula, curativo de TQT e GTT.', 'São Paulo', 'SP', 'Rua Pires da Mota', 'Aclimação', '1875', 'Casa 2', 800.00, 'urgente', '1x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Comunicação alternativa e avaliação de deglutição de saliva.', 'São Paulo', 'SP', 'Rua Pires da Mota', 'Aclimação', '1875', 'Casa 2', 600.00, 'urgente', '1x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Suporte psicológico ao paciente e à família.', 'São Paulo', 'SP', 'Rua Pires da Mota', 'Aclimação', '1875', 'Casa 2', 520.00, 'urgente', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 27] Conceição Ramos Tavares - 2 especialidades - Freguesia do Ó (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 27] Multidisciplinar - Conceição Ramos Tavares',
  'Conceição Ramos Tavares',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2520.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Itaiquara',
  '1948',
  'Apto 101, Bloco B',
  'Freguesia do Ó',
  '02929000',
  'Zona Norte',
  'Rua Itaiquara, 1948 - Apto 101, Bloco B - Freguesia do Ó - São Paulo/SP - CEP 02929-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 83 anos, fratura transtrocantérica de fêmur direito após queda, osteossíntese há 3 semanas. Carga parcial liberada, medo de cair.

Serviços: Fisioterapia 3x por semana, Enfermagem 1x por semana.

Dispositivos: nenhum. Convênio SulAmérica Saúde.',
  'Paciente mora sozinha, filha visita diariamente. Avaliar adaptação do banheiro.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 27]

PAD: Fisioterapia 3x por semana + Enfermagem 1x por semana.

POSSÍVEL DATA: 24/11/2026
HORA: 10h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Conceição Ramos Tavares

DATA DE NASCIMENTO: 18/09/1943

IDADE: 83 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Rua Itaiquara, 1948 - Apto 101, Bloco B - Freguesia do Ó - São Paulo/SP - CEP 02929-000

TELEFONE: (11) 90000-1026 - Sandra (esposa)

HD: Fratura de fêmur proximal com osteossíntese.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente mora sozinha, filha visita diariamente. Avaliar adaptação do banheiro.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia motora: carga progressiva, fortalecimento e treino de transferências.
- Enfermagem (1x por semana): Acompanhamento da ferida operatória e controle de anticoagulante.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia motora: carga progressiva, fortalecimento e treino de transferências.', 'São Paulo', 'SP', 'Rua Itaiquara', 'Freguesia do Ó', '1948', 'Apto 101, Bloco B', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Acompanhamento da ferida operatória e controle de anticoagulante.', 'São Paulo', 'SP', 'Rua Itaiquara', 'Freguesia do Ó', '1948', 'Apto 101, Bloco B', 720.00, 'normal', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 28] Lucas Eduardo Pacheco - 3 especialidades - Ipiranga (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 28] Multidisciplinar - Lucas Eduardo Pacheco',
  'Lucas Eduardo Pacheco',
  'Fisioterapia',
  '5x_semana',
  'normal',
  5440.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Silva Bueno',
  '121',
  'Fundos',
  'Ipiranga',
  '04208000',
  'Zona Sul',
  'Rua Silva Bueno, 121 - Fundos - Ipiranga - São Paulo/SP - CEP 04208-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 29 anos, tetraplegia incompleta (C6) após acidente de moto há 6 meses, cadeirante, sonda vesical de demora, lesão por pressão sacral em cicatrização.

Serviços: Fisioterapia 5x por semana, Enfermagem 2x por semana, Psicologia 1x por semana.

Dispositivos: SVD. Convênio Amil.',
  'Paciente jovem e motivado. Família solicita foco em independência para atividades de vida diária.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 28]

PAD: Fisioterapia 5x por semana + Enfermagem 2x por semana + Psicologia 1x por semana.

POSSÍVEL DATA: 25/11/2026
HORA: 13h30

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Lucas Eduardo Pacheco

DATA DE NASCIMENTO: 12/08/1997

IDADE: 29 anos

CONVÊNIO: Amil

ENDEREÇO: Rua Silva Bueno, 121 - Fundos - Ipiranga - São Paulo/SP - CEP 04208-000

TELEFONE: (11) 90000-1027 - Marcelo (marido)

HD: Lesão medular cervical C6 pós-trauma.

DISPOSITIVO: (X) SIM = QUAL? SVD  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente jovem e motivado. Família solicita foco em independência para atividades de vida diária.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia neurofuncional: fortalecimento, transferências e cadeira de rodas.
- Enfermagem (2x por semana): Curativo de lesão por pressão sacral e troca da SVD.
- Psicologia (1x por semana): Suporte para adaptação à nova condição.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia neurofuncional: fortalecimento, transferências e cadeira de rodas.', 'São Paulo', 'SP', 'Rua Silva Bueno', 'Ipiranga', '121', 'Fundos', 3400.00, 'normal', '5x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Curativo de lesão por pressão sacral e troca da SVD.', 'São Paulo', 'SP', 'Rua Silva Bueno', 'Ipiranga', '121', 'Fundos', 1520.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Suporte para adaptação à nova condição.', 'São Paulo', 'SP', 'Rua Silva Bueno', 'Ipiranga', '121', 'Fundos', 520.00, 'normal', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 29] Dirce Moreira Castro - 3 especialidades - Vila Prudente (Zona Leste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 29] Multidisciplinar - Dirce Moreira Castro',
  'Dirce Moreira Castro',
  'Fonoaudiologia',
  '2x_semana',
  'normal',
  2720.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Ibitirama',
  '194',
  'Apto 73',
  'Vila Prudente',
  '03134000',
  'Zona Leste',
  'Rua Ibitirama, 194 - Apto 73 - Vila Prudente - São Paulo/SP - CEP 03134-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 86 anos, Alzheimer moderado, desorientado, tosse durante as refeições e perda de peso de 6 kg em 3 meses.

Serviços: Fonoaudiologia 2x por semana, Fisioterapia 2x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio Porto Seguro Saúde.',
  'Cuidadora contratada pela família durante o dia. Atendimentos preferencialmente pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 29]

PAD: Fonoaudiologia 2x por semana + Fisioterapia 2x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 26/11/2026
HORA: 14h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Dirce Moreira Castro

DATA DE NASCIMENTO: 06/07/1940

IDADE: 86 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua Ibitirama, 194 - Apto 73 - Vila Prudente - São Paulo/SP - CEP 03134-000

TELEFONE: (11) 90000-1028 - Adriana (mãe)

HD: Doença de Alzheimer moderada com disfagia.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cuidadora contratada pela família durante o dia. Atendimentos preferencialmente pela manhã.

SERVIÇOS SOLICITADOS:
- Fonoaudiologia (2x por semana): Avaliação e reabilitação da deglutição, adaptação de consistências.
- Fisioterapia (2x por semana): Fisioterapia motora para manutenção da mobilidade e prevenção de quedas.
- Nutrição (quinzenal): Plano alimentar para recuperação de peso com consistência adaptada.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fonoaudiologia', 'Avaliação e reabilitação da deglutição, adaptação de consistências.', 'São Paulo', 'SP', 'Rua Ibitirama', 'Vila Prudente', '194', 'Apto 73', 1120.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Fisioterapia motora para manutenção da mobilidade e prevenção de quedas.', 'São Paulo', 'SP', 'Rua Ibitirama', 'Vila Prudente', '194', 'Apto 73', 1200.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Plano alimentar para recuperação de peso com consistência adaptada.', 'São Paulo', 'SP', 'Rua Ibitirama', 'Vila Prudente', '194', 'Apto 73', 400.00, 'normal', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 30] Fábio Henrique Lacerda - 2 especialidades - Vila Madalena (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 30] Multidisciplinar - Fábio Henrique Lacerda',
  'Fábio Henrique Lacerda',
  'Fisioterapia',
  '5x_semana',
  'normal',
  3200.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Harmonia',
  '267',
  NULL,
  'Vila Madalena',
  '05435000',
  'Zona Oeste',
  'Rua Harmonia, 267 - Vila Madalena - São Paulo/SP - CEP 05435-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 61 anos, pós-COVID com internação em UTI por 20 dias, fraqueza muscular global e dispneia aos pequenos esforços. Deambula com auxílio.

Serviços: Fisioterapia 5x por semana, Nutrição 1x por mês.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Paciente retornou do hospital há 1 semana. Saturação estável em ar ambiente.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 30]

PAD: Fisioterapia 5x por semana + Nutrição 1x por mês.

POSSÍVEL DATA: 27/11/2026
HORA: 15h30

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Fábio Henrique Lacerda

DATA DE NASCIMENTO: 30/05/1965

IDADE: 61 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Harmonia, 267 - Vila Madalena - São Paulo/SP - CEP 05435-000

TELEFONE: (11) 90000-1029 - Eduardo (neta)

HD: Síndrome pós-COVID com fraqueza muscular.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente retornou do hospital há 1 semana. Saturação estável em ar ambiente.

SERVIÇOS SOLICITADOS:
- Fisioterapia (5x por semana): Fisioterapia motora e respiratória: recondicionamento e treino de marcha.
- Nutrição (1x por mês): Avaliação nutricional para ganho de massa muscular.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia motora e respiratória: recondicionamento e treino de marcha.', 'São Paulo', 'SP', 'Rua Harmonia', 'Vila Madalena', '267', NULL, 3000.00, 'normal', '5x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Avaliação nutricional para ganho de massa muscular.', 'São Paulo', 'SP', 'Rua Harmonia', 'Vila Madalena', '267', NULL, 200.00, 'normal', 'mensal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 31] Joana D'Arc Siqueira - 3 especialidades - Bom Retiro (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 31] Multidisciplinar - Joana D''Arc Siqueira',
  'Joana D''Arc Siqueira',
  'Fisioterapia',
  '3x_semana',
  'urgente',
  4080.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua José Paulino',
  '340',
  'Sobrado',
  'Bom Retiro',
  '01120000',
  'Centro',
  'Rua José Paulino, 340 - Sobrado - Bom Retiro - São Paulo/SP - CEP 01120-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 70 anos, câncer de pulmão metastático em cuidados paliativos exclusivos, dispneia, dor controlada com morfina, acamado na maior parte do dia.

Serviços: Fisioterapia 3x por semana, Enfermagem 2x por semana, Psicologia 1x por semana.

Dispositivos: Concentrador de O2. Convênio NotreDame Intermédica.',
  'Foco em conforto. Família muito presente, solicita profissionais com experiência em paliativos.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 31]

PAD: Fisioterapia 3x por semana + Enfermagem 2x por semana + Psicologia 1x por semana.

POSSÍVEL DATA: 28/11/2026
HORA: 16h00

SOLICITANTE: Renata Moura

NOME DO PACIENTE: Joana D''Arc Siqueira

DATA DE NASCIMENTO: 23/04/1956

IDADE: 70 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Rua José Paulino, 340 - Sobrado - Bom Retiro - São Paulo/SP - CEP 01120-000

TELEFONE: (11) 90000-1030 - Cristina (irmã)

HD: Neoplasia de pulmão em cuidados paliativos.

DISPOSITIVO: (X) SIM = QUAL? Concentrador de O2  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Foco em conforto. Família muito presente, solicita profissionais com experiência em paliativos.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia paliativa: conforto respiratório e posicionamento.
- Enfermagem (2x por semana): Controle de dor, curativos e orientação à família.
- Psicologia (1x por semana): Suporte ao paciente e à família no cuidado paliativo.

Atenciosamente,
Renata Moura
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'NotreDame%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'NotreDame%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia paliativa: conforto respiratório e posicionamento.', 'São Paulo', 'SP', 'Rua José Paulino', 'Bom Retiro', '340', 'Sobrado', 1920.00, 'urgente', '3x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Controle de dor, curativos e orientação à família.', 'São Paulo', 'SP', 'Rua José Paulino', 'Bom Retiro', '340', 'Sobrado', 1600.00, 'urgente', '2x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Suporte ao paciente e à família no cuidado paliativo.', 'São Paulo', 'SP', 'Rua José Paulino', 'Bom Retiro', '340', 'Sobrado', 560.00, 'urgente', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 32] Wilson Barbosa Freitas - 3 especialidades - Tremembé (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 32] Multidisciplinar - Wilson Barbosa Freitas',
  'Wilson Barbosa Freitas',
  'Fisioterapia',
  '3x_semana',
  'baixa',
  2920.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Avenida Maria Amália Lopes de Azevedo',
  '413',
  'Apto 15',
  'Tremembé',
  '02350000',
  'Zona Norte',
  'Avenida Maria Amália Lopes de Azevedo, 413 - Apto 15 - Tremembé - São Paulo/SP - CEP 02350-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 64 anos, amputação transtibial esquerda há 2 meses por pé diabético, coto cicatrizado, aguardando prótese. Diabetes tipo 2 insulinodependente.

Serviços: Fisioterapia 3x por semana, Enfermagem 1x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio Hapvida.',
  'Paciente em fase pré-protetização. Ortopedista solicita preparo do coto.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 32]

PAD: Fisioterapia 3x por semana + Enfermagem 1x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 29/11/2026
HORA: 17h00

SOLICITANTE: Carla Menezes

NOME DO PACIENTE: Wilson Barbosa Freitas

DATA DE NASCIMENTO: 17/03/1962

IDADE: 64 anos

CONVÊNIO: Hapvida

ENDEREÇO: Avenida Maria Amália Lopes de Azevedo, 413 - Apto 15 - Tremembé - São Paulo/SP - CEP 02350-000

TELEFONE: (11) 90000-1031 - Fernando (nora)

HD: Amputação transtibial esquerda (pé diabético).

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente em fase pré-protetização. Ortopedista solicita preparo do coto.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia pré-protetização: enfaixamento do coto e fortalecimento.
- Enfermagem (1x por semana): Controle glicêmico e orientação sobre insulina.
- Nutrição (quinzenal): Dieta para controle do diabetes.

Atenciosamente,
Carla Menezes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia pré-protetização: enfaixamento do coto e fortalecimento.', 'São Paulo', 'SP', 'Avenida Maria Amália Lopes de Azevedo', 'Tremembé', '413', 'Apto 15', 1800.00, 'baixa', '3x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Controle glicêmico e orientação sobre insulina.', 'São Paulo', 'SP', 'Avenida Maria Amália Lopes de Azevedo', 'Tremembé', '413', 'Apto 15', 720.00, 'baixa', '1x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Dieta para controle do diabetes.', 'São Paulo', 'SP', 'Avenida Maria Amália Lopes de Azevedo', 'Tremembé', '413', 'Apto 15', 400.00, 'baixa', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 33] Valentina Oliveira Rezende - 2 especialidades - Jabaquara (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 33] Multidisciplinar - Valentina Oliveira Rezende',
  'Valentina Oliveira Rezende',
  'Fisioterapia',
  '2x_semana',
  'normal',
  2480.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Avenida Engenheiro Armando de Arruda Pereira',
  '486',
  'Apto 42',
  'Jabaquara',
  '04309010',
  'Zona Sul',
  'Avenida Engenheiro Armando de Arruda Pereira, 486 - Apto 42 - Jabaquara - São Paulo/SP - CEP 04309-010',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Criança de 3 anos, criança com síndrome de Down, hipotonia global, ainda não anda sem apoio. Cardiopatia corrigida no primeiro ano.

Serviços: Fisioterapia 2x por semana, Fonoaudiologia 2x por semana.

Dispositivos: nenhum. Convênio Unimed.',
  'Família solicita atendimento em casa pela manhã.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 33]

PAD: Fisioterapia 2x por semana + Fonoaudiologia 2x por semana.

POSSÍVEL DATA: 30/11/2026
HORA: 08h00

SOLICITANTE: Mariana Oliveira

NOME DO PACIENTE: Valentina Oliveira Rezende

DATA DE NASCIMENTO: 08/02/2023

IDADE: 3 anos

CONVÊNIO: Unimed

ENDEREÇO: Avenida Engenheiro Armando de Arruda Pereira, 486 - Apto 42 - Jabaquara - São Paulo/SP - CEP 04309-010

TELEFONE: (11) 90000-1032 - Patrícia (filha)

HD: Síndrome de Down com atraso do desenvolvimento motor.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Família solicita atendimento em casa pela manhã.

SERVIÇOS SOLICITADOS:
- Fisioterapia (2x por semana): Fisioterapia neuropediátrica: estimulação motora e aquisição de marcha.
- Fonoaudiologia (2x por semana): Estimulação de linguagem e motricidade orofacial.

Atenciosamente,
Mariana Oliveira
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Unimed%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Unimed%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia neuropediátrica: estimulação motora e aquisição de marcha.', 'São Paulo', 'SP', 'Avenida Engenheiro Armando de Arruda Pereira', 'Jabaquara', '486', 'Apto 42', 1280.00, 'normal', '2x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Estimulação de linguagem e motricidade orofacial.', 'São Paulo', 'SP', 'Avenida Engenheiro Armando de Arruda Pereira', 'Jabaquara', '486', 'Apto 42', 1200.00, 'normal', '2x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 34] Rubens Toledo Amaral - 3 especialidades - Carrão (Zona Leste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 34] Multidisciplinar - Rubens Toledo Amaral',
  'Rubens Toledo Amaral',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2840.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Apucarana',
  '559',
  'Casa 2',
  'Carrão',
  '03311000',
  'Zona Leste',
  'Rua Apucarana, 559 - Casa 2 - Carrão - São Paulo/SP - CEP 03311-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 77 anos, insuficiência cardíaca com fração de ejeção de 30%, edema de membros inferiores, cansaço aos pequenos esforços.

Serviços: Fisioterapia 3x por semana, Enfermagem 1x por semana, Nutrição 1x por mês.

Dispositivos: nenhum. Convênio Bradesco Saúde.',
  'Cardiologista solicita reabilitação cardiovascular leve e controle de peso diário.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 34]

PAD: Fisioterapia 3x por semana + Enfermagem 1x por semana + Nutrição 1x por mês.

POSSÍVEL DATA: 01/12/2026
HORA: 09h30

SOLICITANTE: Fernanda Ribeiro Santos

NOME DO PACIENTE: Rubens Toledo Amaral

DATA DE NASCIMENTO: 29/10/1949

IDADE: 77 anos

CONVÊNIO: Bradesco Saúde

ENDEREÇO: Rua Apucarana, 559 - Casa 2 - Carrão - São Paulo/SP - CEP 03311-000

TELEFONE: (11) 90000-1033 - Ricardo (filho)

HD: Insuficiência cardíaca classe funcional III.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cardiologista solicita reabilitação cardiovascular leve e controle de peso diário.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação cardiovascular leve com monitorização.
- Enfermagem (1x por semana): Controle de sinais vitais, peso e adesão aos medicamentos.
- Nutrição (1x por mês): Dieta hipossódica e controle hídrico.

Atenciosamente,
Fernanda Ribeiro Santos
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Bradesco%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Bradesco%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Reabilitação cardiovascular leve com monitorização.', 'São Paulo', 'SP', 'Rua Apucarana', 'Carrão', '559', 'Casa 2', 1920.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Controle de sinais vitais, peso e adesão aos medicamentos.', 'São Paulo', 'SP', 'Rua Apucarana', 'Carrão', '559', 'Casa 2', 720.00, 'normal', '1x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Dieta hipossódica e controle hídrico.', 'São Paulo', 'SP', 'Rua Apucarana', 'Carrão', '559', 'Casa 2', 200.00, 'normal', 'mensal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 35] Elza Fonseca Brito - 3 especialidades - Pompeia (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 35] Multidisciplinar - Elza Fonseca Brito',
  'Elza Fonseca Brito',
  'Enfermagem',
  '3x_semana',
  'normal',
  4480.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Tavares Bastos',
  '632',
  'Apto 101, Bloco B',
  'Pompeia',
  '05012000',
  'Zona Oeste',
  'Rua Tavares Bastos, 632 - Apto 101, Bloco B - Pompeia - São Paulo/SP - CEP 05012-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 81 anos, acamado, lesão por pressão sacral grau 3 com tecido de granulação, sem sinais de infecção. Mobilidade muito reduzida.

Serviços: Enfermagem 3x por semana, Fisioterapia 3x por semana, Nutrição quinzenal.

Dispositivos: nenhum. Convênio SulAmérica Saúde.',
  'Curativo com cobertura especial fornecida pelo convênio.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 35]

PAD: Enfermagem 3x por semana + Fisioterapia 3x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 02/12/2026
HORA: 10h00

SOLICITANTE: Daniela de Assis Silva

NOME DO PACIENTE: Elza Fonseca Brito

DATA DE NASCIMENTO: 22/09/1945

IDADE: 81 anos

CONVÊNIO: SulAmérica Saúde

ENDEREÇO: Rua Tavares Bastos, 632 - Apto 101, Bloco B - Pompeia - São Paulo/SP - CEP 05012-000

TELEFONE: (11) 90000-1034 - Sandra (esposa)

HD: Lesão por pressão sacral grau 3.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Curativo com cobertura especial fornecida pelo convênio.

SERVIÇOS SOLICITADOS:
- Enfermagem (3x por semana): Curativo especial da lesão por pressão.
- Fisioterapia (3x por semana): Mobilização no leito e posicionamento para alívio de pressão.
- Nutrição (quinzenal): Suplementação proteica para cicatrização.

Atenciosamente,
Daniela de Assis Silva
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'SulAm%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'SulAm%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Enfermagem', 'Curativo especial da lesão por pressão.', 'São Paulo', 'SP', 'Rua Tavares Bastos', 'Pompeia', '632', 'Apto 101, Bloco B', 2280.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Fisioterapia', 'Mobilização no leito e posicionamento para alívio de pressão.', 'São Paulo', 'SP', 'Rua Tavares Bastos', 'Pompeia', '632', 'Apto 101, Bloco B', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Suplementação proteica para cicatrização.', 'São Paulo', 'SP', 'Rua Tavares Bastos', 'Pompeia', '632', 'Apto 101, Bloco B', 400.00, 'normal', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 36] Leonardo Assis Quintana - 4 especialidades - Cambuci (Centro)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 36] Multidisciplinar - Leonardo Assis Quintana',
  'Leonardo Assis Quintana',
  'Fisioterapia',
  '7x_semana',
  'urgente',
  17900.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Lavapés',
  '705',
  'Fundos',
  'Cambuci',
  '01519000',
  'Centro',
  'Rua Lavapés, 705 - Fundos - Cambuci - São Paulo/SP - CEP 01519-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 34 anos, TCE grave após atropelamento, estado de mínima consciência, traqueostomizado e com gastrostomia. Espasticidade em membros.

Serviços: Fisioterapia diariamente, Técnico de Enfermagem diariamente, Fonoaudiologia 3x por semana, Nutrição 1x por mês.

Dispositivos: TQT + GTT. Convênio Amil.',
  'Desospitalização programada para a próxima semana.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 36]

PAD: Fisioterapia diariamente + Técnico de Enfermagem diariamente + Fonoaudiologia 3x por semana + Nutrição 1x por mês.

POSSÍVEL DATA: 03/12/2026
HORA: 13h30

SOLICITANTE: Camila Duarte

NOME DO PACIENTE: Leonardo Assis Quintana

DATA DE NASCIMENTO: 16/08/1992

IDADE: 34 anos

CONVÊNIO: Amil

ENDEREÇO: Rua Lavapés, 705 - Fundos - Cambuci - São Paulo/SP - CEP 01519-000

TELEFONE: (11) 90000-1035 - Marcelo (marido)

HD: Traumatismo cranioencefálico grave.

DISPOSITIVO: (X) SIM = QUAL? TQT + GTT  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Desospitalização programada para a próxima semana.

SERVIÇOS SOLICITADOS:
- Fisioterapia (diariamente): Fisioterapia respiratória e motora diária.
- Técnico de Enfermagem (diariamente): Técnico de enfermagem 24h.
- Fonoaudiologia (3x por semana): Estimulação sensorial e avaliação para decanulação.
- Nutrição (1x por mês): Dieta enteral pela GTT.

Atenciosamente,
Camila Duarte
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Amil%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Amil%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória e motora diária.', 'São Paulo', 'SP', 'Rua Lavapés', 'Cambuci', '705', 'Fundos', 5400.00, 'urgente', '7x_semana', 'pendente'),
  (@zz_d, 'Técnico de Enfermagem', 'Técnico de enfermagem 24h.', 'São Paulo', 'SP', 'Rua Lavapés', 'Cambuci', '705', 'Fundos', 10500.00, 'urgente', '7x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Estimulação sensorial e avaliação para decanulação.', 'São Paulo', 'SP', 'Rua Lavapés', 'Cambuci', '705', 'Fundos', 1800.00, 'urgente', '3x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Dieta enteral pela GTT.', 'São Paulo', 'SP', 'Rua Lavapés', 'Cambuci', '705', 'Fundos', 200.00, 'urgente', 'mensal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 37] Marta Regina Cardoso - 2 especialidades - Vila Maria (Zona Norte)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 37] Multidisciplinar - Marta Regina Cardoso',
  'Marta Regina Cardoso',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2320.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Araritaguaba',
  '778',
  'Apto 73',
  'Vila Maria',
  '02122000',
  'Zona Norte',
  'Rua Araritaguaba, 778 - Apto 73 - Vila Maria - São Paulo/SP - CEP 02122-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 41 anos, esclerose múltipla após surto recente, fraqueza em membros inferiores e fadiga. Marcha com apoio bilateral.

Serviços: Fisioterapia 3x por semana, Psicologia 1x por semana.

Dispositivos: nenhum. Convênio Porto Seguro Saúde.',
  'Paciente trabalha em home office: preferência por atendimentos no fim da tarde.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 37]

PAD: Fisioterapia 3x por semana + Psicologia 1x por semana.

POSSÍVEL DATA: 22/11/2026
HORA: 14h00

SOLICITANTE: Patrícia Gomes

NOME DO PACIENTE: Marta Regina Cardoso

DATA DE NASCIMENTO: 10/07/1985

IDADE: 41 anos

CONVÊNIO: Porto Seguro Saúde

ENDEREÇO: Rua Araritaguaba, 778 - Apto 73 - Vila Maria - São Paulo/SP - CEP 02122-000

TELEFONE: (11) 90000-1036 - Adriana (mãe)

HD: Esclerose múltipla forma remitente-recorrente.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Paciente trabalha em home office: preferência por atendimentos no fim da tarde.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia neurofuncional: fortalecimento e equilíbrio.
- Psicologia (1x por semana): Acompanhamento psicológico após o surto.

Atenciosamente,
Patrícia Gomes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Porto%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Porto%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia neurofuncional: fortalecimento e equilíbrio.', 'São Paulo', 'SP', 'Rua Araritaguaba', 'Vila Maria', '778', 'Apto 73', 1800.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Psicologia', 'Acompanhamento psicológico após o surto.', 'São Paulo', 'SP', 'Rua Araritaguaba', 'Vila Maria', '778', 'Apto 73', 520.00, 'normal', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 38] Armando Silva Peixoto - 2 especialidades - Brooklin (Zona Sul)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 38] Multidisciplinar - Armando Silva Peixoto',
  'Armando Silva Peixoto',
  'Fisioterapia',
  '3x_semana',
  'normal',
  2640.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Joaquim Nabuco',
  '851',
  NULL,
  'Brooklin',
  '04621000',
  'Zona Sul',
  'Rua Joaquim Nabuco, 851 - Brooklin - São Paulo/SP - CEP 04621-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 66 anos, pós-operatório de revascularização do miocárdio (3 pontes) há 12 dias, esternotomia em cicatrização, deambula com supervisão.

Serviços: Fisioterapia 3x por semana, Enfermagem 1x por semana.

Dispositivos: nenhum. Convênio Prevent Senior.',
  'Cardiologista orienta reabilitação fase II domiciliar até liberação para academia.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 38]

PAD: Fisioterapia 3x por semana + Enfermagem 1x por semana.

POSSÍVEL DATA: 23/11/2026
HORA: 15h30

SOLICITANTE: Juliana Prado

NOME DO PACIENTE: Armando Silva Peixoto

DATA DE NASCIMENTO: 03/06/1960

IDADE: 66 anos

CONVÊNIO: Prevent Senior

ENDEREÇO: Rua Joaquim Nabuco, 851 - Brooklin - São Paulo/SP - CEP 04621-000

TELEFONE: (11) 90000-1037 - Eduardo (neta)

HD: Pós-operatório de revascularização do miocárdio.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Cardiologista orienta reabilitação fase II domiciliar até liberação para academia.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Reabilitação cardíaca fase II e fisioterapia respiratória.
- Enfermagem (1x por semana): Curativo da esternotomia e da safenectomia.

Atenciosamente,
Juliana Prado
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Prevent%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Prevent%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Reabilitação cardíaca fase II e fisioterapia respiratória.', 'São Paulo', 'SP', 'Rua Joaquim Nabuco', 'Brooklin', '851', NULL, 1920.00, 'normal', '3x_semana', 'pendente'),
  (@zz_d, 'Enfermagem', 'Curativo da esternotomia e da safenectomia.', 'São Paulo', 'SP', 'Rua Joaquim Nabuco', 'Brooklin', '851', NULL, 720.00, 'normal', '1x_semana', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 39] Rosângela Prates Viana - 2 especialidades - Vila Matilde (Zona Leste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 39] Multidisciplinar - Rosângela Prates Viana',
  'Rosângela Prates Viana',
  'Fisioterapia',
  '2x_semana',
  'baixa',
  1400.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Rua Doutor Luís Ayres',
  '924',
  'Sobrado',
  'Vila Matilde',
  '03513000',
  'Zona Leste',
  'Rua Doutor Luís Ayres, 924 - Sobrado - Vila Matilde - São Paulo/SP - CEP 03513-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Paciente de 69 anos, artrite reumatoide de longa data, rigidez matinal, deformidades em mãos e dor em joelhos. Dificuldade para levantar da cadeira.

Serviços: Fisioterapia 2x por semana, Nutrição 1x por mês.

Dispositivos: nenhum. Convênio NotreDame Intermédica.',
  'Reumatologista solicita fisioterapia para manutenção funcional.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 39]

PAD: Fisioterapia 2x por semana + Nutrição 1x por mês.

POSSÍVEL DATA: 24/11/2026
HORA: 16h00

SOLICITANTE: Renata Moura

NOME DO PACIENTE: Rosângela Prates Viana

DATA DE NASCIMENTO: 27/04/1957

IDADE: 69 anos

CONVÊNIO: NotreDame Intermédica

ENDEREÇO: Rua Doutor Luís Ayres, 924 - Sobrado - Vila Matilde - São Paulo/SP - CEP 03513-000

TELEFONE: (11) 90000-1038 - Cristina (irmã)

HD: Artrite reumatoide com rigidez articular.

DISPOSITIVO: ( ) SIM  (X) NÃO

INFORMAÇÕES COMPLEMENTARES: Reumatologista solicita fisioterapia para manutenção funcional.

SERVIÇOS SOLICITADOS:
- Fisioterapia (2x por semana): Fisioterapia para manutenção da amplitude articular e fortalecimento.
- Nutrição (1x por mês): Dieta anti-inflamatória e controle de peso.

Atenciosamente,
Renata Moura
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'NotreDame%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'NotreDame%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia para manutenção da amplitude articular e fortalecimento.', 'São Paulo', 'SP', 'Rua Doutor Luís Ayres', 'Vila Matilde', '924', 'Sobrado', 1200.00, 'baixa', '2x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Dieta anti-inflamatória e controle de peso.', 'São Paulo', 'SP', 'Rua Doutor Luís Ayres', 'Vila Matilde', '924', 'Sobrado', 200.00, 'baixa', 'mensal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- [TESTE 40] Heloísa Gomes Barreto - 3 especialidades - Jaguaré (Zona Oeste)
INSERT INTO demands (title, patient_name, specialty, frequency, urgency, procedure_value, status,
  location_city, location_state, location_street, location_number, location_complement, location_neighborhood,
  location_zip, location_zone, location_raw, origin_email, ai_summary, captation_note, description,
  has_multiple_requests, health_insurer_id, client_id, created_at, updated_at)
VALUES (
  '[TESTE 40] Multidisciplinar - Heloísa Gomes Barreto',
  'Heloísa Gomes Barreto',
  'Fisioterapia',
  '3x_semana',
  'urgente',
  3840.00,
  'aguardando_captacao',
  'São Paulo',
  'SP',
  'Avenida Jaguaré',
  '997',
  'Apto 15',
  'Jaguaré',
  '05346000',
  'Zona Oeste',
  'Avenida Jaguaré, 997 - Apto 15 - Jaguaré - São Paulo/SP - CEP 05346-000',
  'lucas.vacari+operadora@lrvweb.com.br',
  'Lactente de 3 meses, lactente nascido com 28 semanas, displasia broncopulmonar, em uso de O2 por cateter nasal 0,5 L/min. Ganho de peso lento.

Serviços: Fisioterapia 3x por semana, Fonoaudiologia 2x por semana, Nutrição quinzenal.

Dispositivos: O2 por cateter nasal. Convênio Hapvida.',
  'Alta da UTI neonatal há 10 dias. Mãe e avó revezam nos cuidados.',
  'COMUNICADO DE PROSPECÇÃO [CARD DE TESTE 40]

PAD: Fisioterapia 3x por semana + Fonoaudiologia 2x por semana + Nutrição quinzenal.

POSSÍVEL DATA: 25/11/2026
HORA: 17h00

SOLICITANTE: Carla Menezes

NOME DO PACIENTE: Heloísa Gomes Barreto

DATA DE NASCIMENTO: 21/03/2026

IDADE: 3 meses

CONVÊNIO: Hapvida

ENDEREÇO: Avenida Jaguaré, 997 - Apto 15 - Jaguaré - São Paulo/SP - CEP 05346-000

TELEFONE: (11) 90000-1039 - Fernando (nora)

HD: Prematuridade com displasia broncopulmonar.

DISPOSITIVO: (X) SIM = QUAL? O2 por cateter nasal  ( ) NÃO

INFORMAÇÕES COMPLEMENTARES: Alta da UTI neonatal há 10 dias. Mãe e avó revezam nos cuidados.

SERVIÇOS SOLICITADOS:
- Fisioterapia (3x por semana): Fisioterapia respiratória pediátrica e estimulação motora.
- Fonoaudiologia (2x por semana): Coordenação sucção-deglutição-respiração na amamentação.
- Nutrição (quinzenal): Acompanhamento do ganho de peso.

Atenciosamente,
Carla Menezes
Assistente de Operações',
  1,
  (SELECT hi.id FROM health_insurers hi WHERE hi.name LIKE 'Hapvida%' ORDER BY hi.is_active DESC, hi.id LIMIT 1),
  (SELECT hi2.client_id FROM health_insurers hi2 WHERE hi2.name LIKE 'Hapvida%' ORDER BY hi2.is_active DESC, hi2.id LIMIT 1),
  NOW(),
  NOW()
);
SET @zz_d = LAST_INSERT_ID();
INSERT INTO demand_sub_requests (demand_id, specialty, description, location_city, location_state, location_street,
  location_neighborhood, location_number, location_complement, procedure_value, urgency, frequency, status) VALUES
  (@zz_d, 'Fisioterapia', 'Fisioterapia respiratória pediátrica e estimulação motora.', 'São Paulo', 'SP', 'Avenida Jaguaré', 'Jaguaré', '997', 'Apto 15', 2160.00, 'urgente', '3x_semana', 'pendente'),
  (@zz_d, 'Fonoaudiologia', 'Coordenação sucção-deglutição-respiração na amamentação.', 'São Paulo', 'SP', 'Avenida Jaguaré', 'Jaguaré', '997', 'Apto 15', 1280.00, 'urgente', '2x_semana', 'pendente'),
  (@zz_d, 'Nutrição', 'Acompanhamento do ganho de peso.', 'São Paulo', 'SP', 'Avenida Jaguaré', 'Jaguaré', '997', 'Apto 15', 400.00, 'urgente', 'quinzenal', 'pendente');
INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (@zz_d, NULL, 'aguardando_captacao', NULL, 'card de teste criado via SQL (reset 2026-11-19)');

-- Limpeza do apoio
DROP PROCEDURE IF EXISTS zz_reset_run;
DROP PROCEDURE IF EXISTS zz_seed_addcol;
DROP TABLE IF EXISTS zz_reset_demands, zz_reset_assignments, zz_reset_auths, zz_reset_targets, zz_reset_report;

-- =============================================================================
-- 6) RESULTADO
-- =============================================================================
SELECT status, COUNT(*) AS cards_depois FROM demands GROUP BY status ORDER BY status;

SELECT u.specialty AS especialidade, u.zone AS zona, COUNT(*) AS profissionais_teste,
       GROUP_CONCAT(DISTINCT RIGHT(u.phone, 4) ORDER BY RIGHT(u.phone, 4) SEPARATOR ', ') AS finais
FROM users u
WHERE u.is_test_professional = 1 AND u.status = 'active' AND u.city = 'São Paulo'
GROUP BY u.specialty, u.zone
ORDER BY u.specialty, u.zone;

SELECT d.id, d.title, d.specialty, d.frequency, d.location_neighborhood AS bairro, d.location_zone AS zona,
       d.has_multiple_requests AS multi,
       (SELECT COUNT(*) FROM demand_sub_requests s WHERE s.demand_id = d.id) AS especialidades,
       hi.name AS operadora
FROM demands d
LEFT JOIN health_insurers hi ON hi.id = d.health_insurer_id
WHERE d.title LIKE '[TESTE %'
ORDER BY d.id;

-- =============================================================================
-- DEPOIS DE CONFERIR (rodar separado, quando quiser):
--
-- a) Apagar os backups do reset:
--    SELECT CONCAT('DROP TABLE `', TABLE_NAME, '`;') FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'zbkp1119\_%';
--    (copie e rode o resultado)
--
-- b) Desativar todos os profissionais de teste criados aqui:
--    UPDATE users SET status = 'inactive' WHERE email LIKE '%@teste.multilife.local';
--
-- c) Apagar os 40 cards de teste:
--    DELETE FROM demand_sub_requests WHERE demand_id IN (SELECT id FROM (SELECT id FROM demands WHERE title LIKE '[TESTE %') x);
--    DELETE FROM demand_status_logs  WHERE demand_id IN (SELECT id FROM (SELECT id FROM demands WHERE title LIKE '[TESTE %') x);
--    DELETE FROM demands WHERE title LIKE '[TESTE %';
-- =============================================================================
