-- Corrigir URLs de mídia que estão sem barra inicial
-- Adiciona '/' no início de URLs que começam com 'uploads/'

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
    media_url,
    media_filename,
    from_me,
    message_timestamp
FROM chat_messages
WHERE message_type != 'text'
ORDER BY id DESC
LIMIT 20;
