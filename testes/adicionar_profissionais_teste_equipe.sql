-- =============================================================================
-- MAIS PROFISSIONAIS DE TESTE DA EQUIPE (1 cadastro por especialidade x zona dos cards de teste)
-- Gerado em 2026-11-20. Rodar de uma vez no phpMyAdmin (aba SQL) do banco certo.
--
--   - Comercial On Solutions    (11) 97224-3329
--   - Atendimento On Solutions  (17) 99109-8286
--   - Beatriz Nogueira          (17) 98835-8367
--
-- Os cadastros seguem os cards [TESTE nn] onde eles estiverem AGORA:
--   - Se já rodou mover_cards_teste_para_rio.sql: Rio de Janeiro/RJ (4 zonas).
--   - Se ainda não rodou: São Paulo/SP (5 zonas). Quando rodar o do Rio depois, ele
--     leva estes cadastros junto (mesma regra dos outros @teste.multilife.local).
-- Para cada pessoa: 1 cadastro por especialidade usada nos cards (Fisioterapia,
-- Fonoaudiologia, Enfermagem, Técnico de Enfermagem, Nutrição, Psicologia) em cada
-- zona dos cards. Todos ativos, perfil profissional e marcados como profissional de
-- teste. Senha: Teste@2026. E-mails @teste.multilife.local.
-- Pode rodar de novo: não duplica (pula o que já existe).
-- =============================================================================

DROP TABLE IF EXISTS zz_add_people, zz_add_combos;
CREATE TABLE zz_add_people (slug VARCHAR(40), name VARCHAR(120), phone VARCHAR(30));
CREATE TABLE zz_add_combos (specialty VARCHAR(120), spec_slug VARCHAR(20), city VARCHAR(120), state CHAR(2),
                            zone VARCHAR(40), zone_slug VARCHAR(10), neighborhood VARCHAR(120));

INSERT INTO zz_add_people (slug, name, phone) VALUES
  ('comercial.onsolutions', 'Comercial On Solutions', '5511972243329'),
  ('atendimento.onsolutions', 'Atendimento On Solutions', '5517991098286'),
  ('beatriz.nogueira', 'Beatriz Nogueira', '5517988358367');

-- Especialidades x cidade/zona que os cards de teste usam hoje (card + especialidades do card)
INSERT INTO zz_add_combos (specialty, spec_slug, city, state, zone, zone_slug, neighborhood)
SELECT s.specialty, s.spec_slug, z.city, z.state, z.zone,
       CASE z.zone WHEN 'Centro' THEN 'centro' WHEN 'Zona Norte' THEN 'zn' WHEN 'Zona Sul' THEN 'zs'
                   WHEN 'Zona Leste' THEN 'zl' WHEN 'Zona Oeste' THEN 'zo' END,
       CASE WHEN z.state = 'RJ' THEN
              CASE z.zone WHEN 'Centro' THEN 'Centro' WHEN 'Zona Norte' THEN 'Tijuca'
                          WHEN 'Zona Sul' THEN 'Botafogo' ELSE 'Barra da Tijuca' END
            ELSE
              CASE z.zone WHEN 'Centro' THEN 'Bela Vista' WHEN 'Zona Norte' THEN 'Santana'
                          WHEN 'Zona Sul' THEN 'Vila Mariana' WHEN 'Zona Leste' THEN 'Tatuapé' ELSE 'Pinheiros' END
       END
FROM (
  SELECT DISTINCT
         CASE x.k WHEN 'fisio' THEN 'Fisioterapia' WHEN 'fono' THEN 'Fonoaudiologia' WHEN 'tecenf' THEN 'Técnico de Enfermagem'
                  WHEN 'enf' THEN 'Enfermagem' WHEN 'nutri' THEN 'Nutrição' WHEN 'psico' THEN 'Psicologia' END AS specialty,
         x.k AS spec_slug
  FROM (
    SELECT CASE WHEN t.spec LIKE '%cnico de enferm%' THEN 'tecenf'
                WHEN t.spec LIKE '%enferm%' THEN 'enf'
                WHEN t.spec LIKE '%fisio%' THEN 'fisio'
                WHEN t.spec LIKE '%fono%' THEN 'fono'
                WHEN t.spec LIKE '%nutri%' THEN 'nutri'
                WHEN t.spec LIKE '%psicol%' THEN 'psico' END AS k
    FROM (
      SELECT CONVERT(d.specialty USING utf8mb4) AS spec FROM demands d WHERE d.title LIKE '[TESTE %'
      UNION ALL
      SELECT CONVERT(sr.specialty USING utf8mb4) FROM demand_sub_requests sr
      INNER JOIN demands d2 ON d2.id = sr.demand_id WHERE d2.title LIKE '[TESTE %'
    ) t
  ) x
  WHERE x.k IS NOT NULL
) s
CROSS JOIN (
  SELECT DISTINCT location_city AS city, location_state AS state, location_zone AS zone
  FROM demands
  WHERE title LIKE '[TESTE %' AND location_zone IN ('Centro', 'Zona Norte', 'Zona Sul', 'Zona Leste', 'Zona Oeste')
) z;

-- Cria os cadastros (só o que ainda não existe para o mesmo telefone + especialidade + cidade + zona)
INSERT INTO users (name, email, phone, password_hash, status, specialty, city, state, neighborhood, zone,
                   is_test_professional, professional_type, is_pre_registration)
SELECT p.name,
       CONCAT(p.slug, '.', c.spec_slug, '.', c.zone_slug, IF(c.state = 'RJ', '.rj', ''), '@teste.multilife.local'),
       p.phone, '$2y$10$q5mGL1tBdqaoS3bW16DHnO0X/DnmfRkzfqwYMizvR/E5y299FAbni', 'active',
       c.specialty, c.city, c.state, c.neighborhood, c.zone, 1, 'new', 0
FROM zz_add_people p
CROSS JOIN zz_add_combos c
WHERE NOT EXISTS (
        SELECT 1 FROM users u
        WHERE u.phone = p.phone AND u.specialty = c.specialty AND u.city = c.city AND u.zone = c.zone AND u.status = 'active')
  AND NOT EXISTS (
        SELECT 1 FROM users u2
        WHERE u2.email = CONCAT(p.slug, '.', c.spec_slug, '.', c.zone_slug, IF(c.state = 'RJ', '.rj', ''), '@teste.multilife.local'));

-- Perfil "profissional" para os novos
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
INNER JOIN roles r ON r.slug = 'profissional'
WHERE u.email LIKE '%@teste.multilife.local'
  AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id);

DROP TABLE IF EXISTS zz_add_people, zz_add_combos;

-- -----------------------------------------------------------------------------
-- RESULTADO
-- -----------------------------------------------------------------------------
-- Cadastros das 3 pessoas novas
SELECT u.name AS nome, u.phone AS telefone, u.city AS cidade, u.state AS uf,
       COUNT(*) AS cadastros,
       GROUP_CONCAT(DISTINCT u.specialty ORDER BY u.specialty SEPARATOR ', ') AS especialidades,
       GROUP_CONCAT(DISTINCT u.zone ORDER BY u.zone SEPARATOR ', ') AS zonas
FROM users u
WHERE u.phone IN ('5511972243329', '5517991098286', '5517988358367') AND u.is_test_professional = 1 AND u.status = 'active'
GROUP BY u.name, u.phone, u.city, u.state
ORDER BY u.name;

-- Todos os profissionais de teste por cidade/especialidade/zona (finais = 4 últimos dígitos)
SELECT u.city AS cidade, u.state AS uf, u.specialty AS especialidade, u.zone AS zona,
       COUNT(*) AS cadastros,
       GROUP_CONCAT(DISTINCT RIGHT(u.phone, 4) ORDER BY RIGHT(u.phone, 4) SEPARATOR ', ') AS finais
FROM users u
WHERE u.is_test_professional = 1 AND u.status = 'active'
GROUP BY u.city, u.state, u.specialty, u.zone
ORDER BY u.city, u.specialty, u.zone;

-- Para desativar só estes 3 depois:
--   UPDATE users SET status = 'inactive'
--   WHERE is_test_professional = 1 AND phone IN ('5511972243329', '5517991098286', '5517988358367');
