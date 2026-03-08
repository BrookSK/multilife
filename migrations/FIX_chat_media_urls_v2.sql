-- Corrigir URLs de mídia que têm domínio completo
-- Remove o domínio e mantém apenas o path

-- Backup antes de alterar
CREATE TABLE IF NOT EXISTS chat_messages_backup_urls AS 
SELECT id, media_url FROM chat_messages WHERE message_type != 'text';

-- Atualizar URLs que contêm o domínio
UPDATE chat_messages
SET media_url = CONCAT('/', SUBSTRING_INDEX(media_url, '/uploads/', -1))
WHERE message_type != 'text'
  AND media_url LIKE '%festive-darwin%'
  AND media_url LIKE '%/uploads/%';

-- Adicionar barra inicial em URLs que não têm
UPDATE chat_messages
SET media_url = CONCAT('/', media_url)
WHERE message_type != 'text'
  AND media_url IS NOT NULL
  AND media_url != ''
  AND media_url NOT LIKE '/%'
  AND media_url NOT LIKE 'http://%'
  AND media_url NOT LIKE 'https://%';

-- Verificar resultado
SELECT 
    id,
    message_type,
    LEFT(media_url, 100) as media_url_preview,
    CASE 
        WHEN media_url LIKE '/%' THEN '✅ OK - Path relativo'
        WHEN media_url LIKE 'http%' THEN '⚠️ URL externa'
        ELSE '❌ Formato inválido'
    END as status
FROM chat_messages
WHERE message_type != 'text'
ORDER BY id DESC
LIMIT 20;
