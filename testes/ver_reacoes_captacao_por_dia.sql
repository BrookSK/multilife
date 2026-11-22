-- =============================================================================
-- REAÇÕES DAS CAPTAÇÕES POR DIA (só leitura, não altera nada)
-- Rodar no phpMyAdmin (aba SQL) do banco certo.
-- Serve para saber se as reações dos testes chegaram ao sistema ou não.
-- =============================================================================

-- 1) Captações enviadas por dia (mensagens que foram para os grupos)
SELECT DATE(dl.created_at) AS dia, COUNT(*) AS captacoes_enviadas,
       COUNT(DISTINCT dl.demand_id) AS cards, COUNT(DISTINCT dl.group_id) AS grupos
FROM demand_dispatch_logs dl
WHERE dl.created_at >= CURDATE() - INTERVAL 7 DAY
GROUP BY DATE(dl.created_at)
ORDER BY dia DESC;

-- 2) Reações registradas como interesse, por dia e situação
--    (a aba Lista de Espera mostra só as "interested")
SELECT DATE(dip.reacted_at) AS dia, dip.status, COUNT(*) AS reacoes,
       COUNT(DISTINCT dip.demand_id) AS cards
FROM demand_interested_professionals dip
WHERE dip.reacted_at >= CURDATE() - INTERVAL 7 DAY
GROUP BY DATE(dip.reacted_at), dip.status
ORDER BY dia DESC, dip.status;

-- 3) Reações que o WhatsApp entregou ao sistema (webhook), por dia, WhatsApp e resultado.
--    "interesse registrado" = virou interesse; "msg não é captação" = reagiu em outra
--    mensagem; "captação ... encerrada" = card já admitido/cancelado.
--    Se um dia não aparece aqui, o WhatsApp não entregou as reações daquele dia.
SELECT DATE(t.created_at) AS dia, t.instance_name AS whatsapp, t.result AS resultado, COUNT(*) AS qtd
FROM chat_webhook_trace t
WHERE t.created_at >= CURDATE() - INTERVAL 7 DAY
  AND t.text_sample LIKE 'emoji=%'
GROUP BY DATE(t.created_at), t.instance_name, t.result
ORDER BY dia DESC, qtd DESC;

-- 4) Últimas 50 reações registradas (quem, em qual card, quando)
SELECT dip.reacted_at AS quando, dip.emoji, COALESCE(dip.push_name, dip.phone) AS quem,
       dip.status, d.id AS card, d.title AS titulo, wg.name AS grupo
FROM demand_interested_professionals dip
INNER JOIN demands d ON d.id = dip.demand_id
LEFT JOIN demand_dispatch_logs dl ON dl.id = dip.dispatch_log_id
LEFT JOIN whatsapp_groups wg ON wg.id = dl.group_id
ORDER BY dip.reacted_at DESC
LIMIT 50;
