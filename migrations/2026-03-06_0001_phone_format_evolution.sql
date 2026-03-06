-- Garantir formato correto de telefone para Evolution API
-- Formato: número completo sem caracteres especiais (apenas dígitos)
-- Exemplo: 5511999999999 (código país + DDD + número)
-- A Evolution API adiciona @s.whatsapp.net automaticamente

-- Função para limpar e formatar número de telefone
DELIMITER //

CREATE FUNCTION IF NOT EXISTS format_phone_for_evolution(phone VARCHAR(30))
RETURNS VARCHAR(30)
DETERMINISTIC
BEGIN
    DECLARE cleaned_phone VARCHAR(30);
    
    -- Remover todos os caracteres não numéricos
    SET cleaned_phone = REGEXP_REPLACE(phone, '[^0-9]', '');
    
    -- Se começar com 0, remover o 0 inicial
    IF LEFT(cleaned_phone, 1) = '0' THEN
        SET cleaned_phone = SUBSTRING(cleaned_phone, 2);
    END IF;
    
    -- Se não tiver código do país (55), adicionar
    IF LENGTH(cleaned_phone) = 10 OR LENGTH(cleaned_phone) = 11 THEN
        SET cleaned_phone = CONCAT('55', cleaned_phone);
    END IF;
    
    RETURN cleaned_phone;
END//

DELIMITER ;

-- Atualizar números existentes na tabela users
UPDATE users 
SET phone = format_phone_for_evolution(phone)
WHERE phone IS NOT NULL 
AND phone != ''
AND phone NOT REGEXP '^[0-9]+$';

-- Atualizar números existentes na tabela patients
UPDATE patients 
SET phone = format_phone_for_evolution(phone)
WHERE phone IS NOT NULL 
AND phone != ''
AND phone NOT REGEXP '^[0-9]+$';

-- Adicionar comentário nas colunas explicando o formato
ALTER TABLE users MODIFY COLUMN phone VARCHAR(30) NULL COMMENT 'Formato Evolution API: apenas dígitos, ex: 5511999999999';
ALTER TABLE patients MODIFY COLUMN phone VARCHAR(30) NULL COMMENT 'Formato Evolution API: apenas dígitos, ex: 5511999999999';
