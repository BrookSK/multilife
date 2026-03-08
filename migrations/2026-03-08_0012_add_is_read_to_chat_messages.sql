-- Adicionar campo is_read para controlar mensagens lidas/não lidas

ALTER TABLE chat_messages 
ADD COLUMN IF NOT EXISTS is_read TINYINT(1) DEFAULT 0 AFTER from_me;

-- Criar índice para melhorar performance de queries de mensagens não lidas
CREATE INDEX IF NOT EXISTS idx_unread_messages ON chat_messages(remote_jid, from_me, is_read);

-- Marcar todas as mensagens existentes como lidas
UPDATE chat_messages SET is_read = 1 WHERE is_read = 0;

-- Verificar resultado
SELECT 
    COUNT(*) as total_messages,
    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_messages,
    SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_messages
FROM chat_messages;
