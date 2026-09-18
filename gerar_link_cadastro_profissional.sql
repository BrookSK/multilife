-- ============================================================================
-- Gera o LINK de atualização da ficha cadastral para um PROFISSIONAL específico.
--
-- SEGURO: este script só cria/atualiza colunas de token no PRÓPRIO usuário.
--   NÃO altera senha, e-mail de login, permissões, conexão nem estrutura crítica.
--   NÃO tem nada a ver com o problema anterior (aquilo era o .htaccess, não SQL).
--
-- COMO USAR:
--   1) Ajuste o e-mail do profissional na linha @email_alvo abaixo, se necessário.
--   2) Rode o script inteiro.
--   3) Na última consulta, copie a coluna "url" (link completo) e envie ao profissional.
-- ============================================================================

-- >>> AJUSTE AQUI o profissional (por e-mail). No print: Lucas Campagna Teste 2.
SET @email_alvo := 'lucas.campagna+teste@irvweb.com.br';

-- --- Garantir colunas de apoio SEM erro se já existirem ---------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'registration_token');
SET @sql := IF(@exists = 0, 'ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'registration_token_created_at');
SET @sql := IF(@exists = 0, 'ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- Gerar um token de 64 caracteres (compatível com MySQL e MariaDB) -------
SET @tok := LOWER(CONCAT(MD5(CONCAT(RAND(), NOW(6), @email_alvo)), MD5(CONCAT(RAND(), UUID()))));

-- Grava o token apenas no profissional-alvo
UPDATE users
SET registration_token = @tok, registration_token_created_at = NOW()
WHERE email = @email_alvo;

-- --- Resultado: link pronto para enviar ao profissional ---------------------
-- Se "linhas_afetadas" for 0, o e-mail não bateu: confira o valor de @email_alvo
-- (veja a consulta auxiliar no final para localizar o profissional pelo nome).
SELECT
    id AS user_id,
    name,
    email,
    registration_token AS token,
    LENGTH(registration_token) AS tamanho_token,
    CONCAT('https://multilife.onsolutionsbrasil.com.br/atualizar-cadastro?token=', registration_token) AS url
FROM users
WHERE email = @email_alvo;

-- ============================================================================
-- CONSULTA AUXILIAR (opcional): se o e-mail acima não encontrou o profissional,
-- rode esta busca por nome para descobrir o e-mail correto e ajustar @email_alvo.
-- ============================================================================
-- SELECT id, name, email FROM users WHERE name LIKE '%Lucas Campagna%';
