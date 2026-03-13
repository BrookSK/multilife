-- Adicionar rastreamento de Message-ID para identificação 100% precisa de respostas

-- Verificar e adicionar sent_message_id em authorization_requests
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'authorization_requests' 
    AND COLUMN_NAME = 'sent_message_id');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE authorization_requests ADD COLUMN sent_message_id VARCHAR(255) NULL COMMENT ''Message-ID do e-mail enviado (para rastreamento de respostas)'' AFTER email_thread_id',
    'SELECT ''Coluna sent_message_id já existe'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar índice se não existir
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'authorization_requests' 
    AND INDEX_NAME = 'idx_sent_message_id');

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE authorization_requests ADD INDEX idx_sent_message_id (sent_message_id)',
    'SELECT ''Índice idx_sent_message_id já existe'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar e adicionar in_reply_to em inbound_emails
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'inbound_emails' 
    AND COLUMN_NAME = 'in_reply_to');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE inbound_emails ADD COLUMN in_reply_to VARCHAR(255) NULL COMMENT ''Header In-Reply-To do e-mail'' AFTER message_id',
    'SELECT ''Coluna in_reply_to já existe'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificar e adicionar references em inbound_emails
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'inbound_emails' 
    AND COLUMN_NAME = 'references');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE inbound_emails ADD COLUMN `references` TEXT NULL COMMENT ''Header References do e-mail'' AFTER in_reply_to',
    'SELECT ''Coluna references já existe'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Adicionar índice se não existir
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'inbound_emails' 
    AND INDEX_NAME = 'idx_in_reply_to');

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE inbound_emails ADD INDEX idx_in_reply_to (in_reply_to)',
    'SELECT ''Índice idx_in_reply_to já existe'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
