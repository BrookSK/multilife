-- =============================================================================
-- GRUPOS DE WHATSAPP JÁ CADASTRADOS NO SISTEMA (só leitura, não altera nada)
-- Rodar no phpMyAdmin (aba SQL) do banco certo.
--
-- A captação reaproveita um grupo quando já existe um com a MESMA especialidade
-- + cidade/UF (+ zona em São Paulo e Rio de Janeiro). Grupo reaproveitado mantém
-- todo mundo que já estava nele e a mensagem sai pelo WhatsApp que criou o grupo
-- (coluna "whatsapp_dono").
-- =============================================================================

-- 1) Todos os grupos, do mais novo para o mais antigo
SELECT g.id,
       g.name            AS grupo,
       g.specialty       AS especialidade,
       g.city            AS cidade,
       g.state           AS uf,
       g.zone            AS zona,
       g.instance_name   AS whatsapp_dono,
       wi.display_name   AS apelido_whatsapp,
       wi.owner_phone_formatted AS numero_whatsapp,
       g.status,
       g.created_at      AS criado_em
FROM whatsapp_groups g
LEFT JOIN whatsapp_instances wi ON wi.instance_name = g.instance_name
ORDER BY g.id DESC;

-- 2) Resumo: quantos grupos por cidade e por WhatsApp dono
SELECT CONCAT(COALESCE(g.city, '(sem cidade)'), '/', COALESCE(g.state, '--')) AS cidade_uf,
       COALESCE(g.instance_name, '(sem dono)') AS whatsapp_dono,
       COUNT(*) AS grupos,
       GROUP_CONCAT(DISTINCT g.specialty ORDER BY g.specialty SEPARATOR ', ') AS especialidades
FROM whatsapp_groups g
GROUP BY cidade_uf, whatsapp_dono
ORDER BY cidade_uf, whatsapp_dono;

-- 3) Grupos que já existem no Rio de Janeiro (o SQL mover_cards_teste_para_rio.sql
--    usa o Rio e para sozinho se algum card fosse cair num destes grupos)
SELECT g.id, g.name AS grupo, g.specialty AS especialidade, g.city AS cidade, g.state AS uf,
       g.zone AS zona, g.instance_name AS whatsapp_dono, g.status
FROM whatsapp_groups g
WHERE (g.state = 'RJ' AND g.city LIKE 'Rio de Janeiro%')
   OR g.name LIKE '%Rio de Janeiro%';

-- 4) Cards de teste e em qual grupo cada um já foi captado
SELECT d.id AS card, d.title AS titulo, d.status, d.location_city AS cidade, d.location_zone AS zona,
       (SELECT GROUP_CONCAT(DISTINCT g2.name SEPARATOR ' | ')
          FROM demand_dispatch_logs dl
          INNER JOIN whatsapp_groups g2 ON g2.id = dl.group_id
         WHERE dl.demand_id = d.id) AS grupos_usados
FROM demands d
WHERE d.title LIKE '[TESTE %'
ORDER BY d.id;
