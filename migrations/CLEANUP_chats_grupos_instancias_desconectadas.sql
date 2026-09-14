-- ============================================================================
-- LIMPEZA: Chats e grupos de instâncias WhatsApp DESCONECTADAS (aba Chat ao Vivo)
-- ============================================================================
-- ATENÇÃO: OPERAÇÃO DESTRUTIVA E IRREVERSÍVEL. Faça BACKUP antes de rodar.
--   mysqldump -u USER -p BANCO chat_contacts chat_messages chat_reactions \
--       chat_capture_info chat_groups chat_group_participants > backup_chat.sql
--
-- Definição de "desconectado" (CRITÉRIO EXPLÍCITO):
--   Uma instância só é considerada desconectada quando, na tabela whatsapp_instances,
--   ela está EXPLICITAMENTE marcada como:
--       connection_status = 'disconnected'   (sessão encerrada)
--    OU status = 'inactive'                  (removida / soft delete)
--   Instâncias em 'connecting' ou com connection_status NULL (estado incerto /
--   ainda não sincronizado) NÃO são tocadas, para evitar apagar chats de instâncias
--   que apenas não foram sincronizadas com a Evolution API.
--
-- Ligação chat -> instância:
--   - chat_contacts.instance_name  (conversas/contatos)
--   - chat_messages.instance_name  (mensagens)
--   As tabelas chat_groups / chat_group_participants / chat_reactions / chat_capture_info
--   NÃO possuem instance_name; a associação é feita via remote_jid presente em
--   chat_contacts/chat_messages daquela instância.
--
-- COMO USAR:
--   1) Rode primeiro a SEÇÃO 0 (conferência) para ver o que será removido.
--   2) Se estiver correto, rode a SEÇÃO 1 (limpeza) dentro da transação.
-- ============================================================================


-- ============================================================================
-- SEÇÃO 0 — CONFERÊNCIA (somente leitura, não apaga nada)
-- ============================================================================

-- 0.1 Instâncias consideradas DESCONECTADAS (critério explícito)
SELECT instance_name, status, connection_status
FROM whatsapp_instances
WHERE connection_status = 'disconnected'
   OR status = 'inactive';

-- 0.2 Quantidade de contatos/conversas que serão removidos
SELECT COUNT(*) AS contatos_a_remover
FROM chat_contacts cc
WHERE cc.instance_name IN (
        SELECT instance_name FROM whatsapp_instances
        WHERE connection_status = 'disconnected' OR status = 'inactive'
   );

-- 0.3 Quantidade de mensagens que serão removidas
SELECT COUNT(*) AS mensagens_a_remover
FROM chat_messages cm
WHERE cm.instance_name IN (
        SELECT instance_name FROM whatsapp_instances
        WHERE connection_status = 'disconnected' OR status = 'inactive'
   );


-- ============================================================================
-- SEÇÃO 1 — LIMPEZA (destrutiva). Revise a SEÇÃO 0 antes de executar.
-- ============================================================================
START TRANSACTION;

-- Conjunto de instâncias EXPLICITAMENTE desconectadas (as que serão limpas).

-- 1.1 Remover MENSAGENS de instâncias desconectadas
DELETE FROM chat_messages
WHERE instance_name IN (
        SELECT instance_name FROM whatsapp_instances
        WHERE connection_status = 'disconnected' OR status = 'inactive'
   );

-- 1.2 Remover REAÇÕES cujo chat (remote_jid) não tem mais mensagens nem contato ativo
DELETE cr FROM chat_reactions cr
WHERE NOT EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.remote_jid = cr.remote_jid)
  AND NOT EXISTS (SELECT 1 FROM chat_contacts cc WHERE cc.remote_jid = cr.remote_jid);

-- 1.3 Remover GRUPOS (metadados) cujo group_jid não tem mais mensagens nem contato ativo.
--     chat_group_participants é apagado em cascata (FK ON DELETE CASCADE).
DELETE cg FROM chat_groups cg
WHERE NOT EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.remote_jid = cg.group_jid)
  AND NOT EXISTS (SELECT 1 FROM chat_contacts cc WHERE cc.remote_jid = cg.group_jid);

-- 1.4 Remover INFO DE CAPTAÇÃO de chats que não têm mais mensagens nem contato ativo
DELETE cci FROM chat_capture_info cci
WHERE NOT EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.remote_jid = cci.chat_id)
  AND NOT EXISTS (SELECT 1 FROM chat_contacts cc WHERE cc.remote_jid = cci.chat_id);

-- 1.5 Remover CONTATOS/CONVERSAS de instâncias desconectadas
DELETE FROM chat_contacts
WHERE instance_name IN (
        SELECT instance_name FROM whatsapp_instances
        WHERE connection_status = 'disconnected' OR status = 'inactive'
   );

-- Revise os resultados da SEÇÃO 0. Se estiver tudo certo:
--   COMMIT;
-- Caso contrário, para desfazer:
--   ROLLBACK;


-- ============================================================================
-- SEÇÃO 2 — LIMPAR MENSAGENS ANTIGAS QUE AINDA APARECEM NA CONVERSA
-- ============================================================================
-- Contexto: A aba Chat ao Vivo carrega mensagens SOMENTE do banco
--   (chat_messages WHERE remote_jid = <jid do chat>). A busca via Evolution API
--   está desabilitada. Portanto, se ainda aparecem mensagens antigas em uma
--   conversa, é porque os registros continuam na tabela chat_messages — a
--   limpeza por instância (Seção 1) não os pegou (ex.: a instância dona não está
--   marcada como 'disconnected'/'inactive', ou instance_name está NULL).
--
-- Use esta seção para limpar mensagens de um CHAT ESPECÍFICO (por remote_jid).
--
-- COMO DESCOBRIR O remote_jid:
--   Ao abrir a conversa em Chat ao Vivo, o JID vai na URL: chat_web.php?chat=<JID>
--   Formatos: 55DDDNUMERO@s.whatsapp.net (privado) ou XXXXXXXXX@g.us (grupo).
-- ============================================================================

-- 2.0 CONFERÊNCIA — listar chats com contagem de mensagens (para achar o JID e o volume)
SELECT cm.remote_jid,
       COALESCE(cc.contact_name, cg.group_name) AS nome,
       cc.instance_name,
       COUNT(*) AS total_mensagens,
       FROM_UNIXTIME(MIN(cm.message_timestamp)) AS primeira,
       FROM_UNIXTIME(MAX(cm.message_timestamp)) AS ultima
FROM chat_messages cm
LEFT JOIN chat_contacts cc ON cc.remote_jid = cm.remote_jid
LEFT JOIN chat_groups cg   ON cg.group_jid  = cm.remote_jid
GROUP BY cm.remote_jid, nome, cc.instance_name
ORDER BY total_mensagens DESC;


-- 2.1 LIMPAR UM CHAT ESPECÍFICO (destrutivo). Ajuste o JID e revise antes.
--     Descomente e substitua o JID. Roda dentro de transação.
/*
SET @jid = '5511999999999@s.whatsapp.net';   -- <<< troque pelo remote_jid do chat

START TRANSACTION;

DELETE FROM chat_messages    WHERE remote_jid = @jid;
DELETE FROM chat_reactions   WHERE remote_jid = @jid;
DELETE FROM chat_capture_info WHERE chat_id   = @jid;
-- Se for grupo (@g.us), remove também metadados/participantes (cascata):
DELETE FROM chat_groups      WHERE group_jid  = @jid;
-- Remove o contato da lista lateral (opcional — omita se quiser manter o chat vazio):
DELETE FROM chat_contacts    WHERE remote_jid = @jid;

-- COMMIT;   -- confirme se estiver correto
-- ROLLBACK; -- desfaz
*/


-- ============================================================================
-- SEÇÃO 3 — (OPCIONAL) LIMPAR MENSAGENS ANTIGAS POR DATA (todas as conversas)
-- ============================================================================
-- Remove mensagens anteriores a uma data de corte, independente da instância.
-- Útil para "zerar histórico antigo". message_timestamp é Unix timestamp (segundos).

-- 3.0 CONFERÊNCIA — quantas mensagens são anteriores ao corte
SELECT COUNT(*) AS mensagens_antigas
FROM chat_messages
WHERE message_timestamp < UNIX_TIMESTAMP('2026-01-01 00:00:00');   -- <<< ajuste a data

-- 3.1 LIMPEZA por data (destrutivo). Descomente para usar.
/*
START TRANSACTION;

DELETE FROM chat_messages
WHERE message_timestamp < UNIX_TIMESTAMP('2026-01-01 00:00:00');   -- <<< ajuste a data

-- Limpa reações órfãs após remover mensagens
DELETE cr FROM chat_reactions cr
WHERE NOT EXISTS (SELECT 1 FROM chat_messages cm WHERE cm.remote_jid = cr.remote_jid);

-- COMMIT;
-- ROLLBACK;
*/


-- ============================================================================
-- SEÇÃO 4 — LIMPAR TUDO (zerar completamente o Chat ao Vivo)
-- ============================================================================
-- ATENÇÃO: APAGA TODAS AS MENSAGENS, CONTATOS, GRUPOS, PARTICIPANTES, REAÇÕES
--          E INFO DE CAPTAÇÃO DE TODAS AS CONVERSAS. IRREVERSÍVEL.
--          NÃO mexe em whatsapp_instances (as instâncias/conexões permanecem).
--
-- BACKUP OBRIGATÓRIO ANTES:
--   mysqldump -u USER -p BANCO chat_messages chat_contacts chat_reactions \
--       chat_capture_info chat_groups chat_group_participants > backup_chat_full.sql
--
-- IMPORTANTE: NÃO usar TRUNCATE em chat_groups — o MySQL recusa (erro #1701)
-- porque chat_group_participants tem FK para chat_groups, e FOREIGN_KEY_CHECKS=0
-- não libera TRUNCATE nesse caso. Por isso usamos DELETE nas tabelas com FK.
-- As tabelas sem FK podem usar TRUNCATE normalmente (reseta auto_increment).
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Tabelas sem relação de FK: TRUNCATE (rápido, reseta IDs)
TRUNCATE TABLE chat_messages;
TRUNCATE TABLE chat_reactions;
TRUNCATE TABLE chat_capture_info;
TRUNCATE TABLE chat_contacts;

-- Tabelas com FK (chat_group_participants -> chat_groups): usar DELETE
DELETE FROM chat_group_participants;
DELETE FROM chat_groups;

-- Reforço: garante que reações sejam removidas mesmo se o TRUNCATE acima
-- não tiver rodado (ex.: batch interrompido por erro em outra tabela).
DELETE FROM chat_reactions;
ALTER TABLE chat_reactions AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- Conferência (deve retornar tudo 0):
SELECT
  (SELECT COUNT(*) FROM chat_messages)          AS mensagens,
  (SELECT COUNT(*) FROM chat_contacts)          AS contatos,
  (SELECT COUNT(*) FROM chat_groups)            AS grupos,
  (SELECT COUNT(*) FROM chat_group_participants) AS participantes,
  (SELECT COUNT(*) FROM chat_reactions)         AS reacoes,
  (SELECT COUNT(*) FROM chat_capture_info)      AS captacao_info;


-- ============================================================================
-- SEÇÃO 5 — LIMPAR "REAGIRAM" (lista de espera das captações)
-- ============================================================================
-- A aba de reações/lista de espera do Chat ao Vivo (ícone de relógio, itens
-- "Fulano Reagiu em ...") NÃO vem de chat_reactions. Ela vem da tabela
-- demand_interested_professionals (status='interested'): profissionais que
-- reagiram (👍) às mensagens de captação enviadas nos grupos.
--
-- Por isso limpar chat_reactions não removeu esses itens.
--
-- demand_interested_professionals tem FK para demands (ON DELETE CASCADE) e
-- nenhuma tabela filha aponta para ela, então pode ser limpa diretamente.
-- ============================================================================

-- 5.0 CONFERÊNCIA — quantos registros existem (por status)
SELECT status, COUNT(*) AS total
FROM demand_interested_professionals
GROUP BY status;

-- 5.1 LIMPAR TUDO (todas as reações às captações, qualquer status)
--     Use este para zerar completamente a aba "reagiram".
TRUNCATE TABLE demand_interested_professionals;

-- 5.1-ALT  Se preferir remover SOMENTE os que estão na lista de espera
--          (mantendo histórico de selecionados/rejeitados), use no lugar do 5.1:
-- DELETE FROM demand_interested_professionals WHERE status = 'interested';

-- Conferência final (deve retornar 0 se usou o 5.1):
SELECT COUNT(*) AS reacoes_captacao_restantes FROM demand_interested_professionals;
