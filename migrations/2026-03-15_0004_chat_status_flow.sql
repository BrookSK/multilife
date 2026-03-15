-- Migration: Fluxo de Status de Chat (Aguardando/Ativa/Resolvidos)
-- Data: 2026-03-15
-- Descrição: Alterar status de chat_conversations para suportar novo fluxo

-- Alterar ENUM de status para incluir waiting, active, resolved
ALTER TABLE chat_conversations 
MODIFY COLUMN status ENUM('waiting','active','resolved','open','closed') NOT NULL DEFAULT 'waiting'
COMMENT 'waiting=aguardando aceite, active=em atendimento, resolved=finalizado';

-- Migrar dados existentes
UPDATE chat_conversations 
SET status = 'active' 
WHERE status = 'open';

UPDATE chat_conversations 
SET status = 'resolved' 
WHERE status = 'closed';

-- Remover valores antigos do ENUM (após migração)
ALTER TABLE chat_conversations 
MODIFY COLUMN status ENUM('waiting','active','resolved') NOT NULL DEFAULT 'waiting';

-- Adicionar índice para melhor performance
ALTER TABLE chat_conversations 
ADD INDEX idx_status_last_message (status, last_message_at);

-- Verificar migração
SELECT 
    status,
    COUNT(*) as total
FROM chat_conversations
GROUP BY status
ORDER BY 
    CASE status
        WHEN 'waiting' THEN 1
        WHEN 'active' THEN 2
        WHEN 'resolved' THEN 3
    END;
