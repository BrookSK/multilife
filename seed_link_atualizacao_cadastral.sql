-- ============================================================================
-- TESTE RÁPIDO do link público de ATUALIZAÇÃO CADASTRAL (pós pré-admissão).
--
-- Cria (se não existir) um profissional de PRÉ-CADASTRO e gera um token de
-- atualização cadastral. Ao final, mostra a URL que você deve abrir no navegador.
--
-- É idempotente: pode rodar quantas vezes quiser. Não dá erro se as colunas
-- já existirem (verifica antes de criar).
-- ============================================================================

-- --- Garantir colunas de apoio SEM erro se já existirem ---------------------
-- registration_token
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'registration_token');
SET @sql := IF(@exists = 0, 'ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- registration_token_created_at
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'registration_token_created_at');
SET @sql := IF(@exists = 0, 'ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- city
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'city');
SET @sql := IF(@exists = 0, 'ALTER TABLE users ADD COLUMN city VARCHAR(120) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- is_pre_registration
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_pre_registration');
SET @sql := IF(@exists = 0, 'ALTER TABLE users ADD COLUMN is_pre_registration TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- Cria o profissional de teste (pré-cadastro) se ainda não existir -------
INSERT INTO users (name, email, phone, specialty, city, password_hash, status, is_pre_registration)
SELECT 'Profissional Teste Cadastro', 'teste.cadastro@precadastro.local', '11999990000', 'Fisioterapia', 'São Paulo',
       '$2y$10$abcdefghijklmnopqrstuv', 'active', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'teste.cadastro@precadastro.local');

-- Garante a role de profissional
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u JOIN roles r ON r.slug = 'profissional'
WHERE u.email = 'teste.cadastro@precadastro.local';

-- --- Gera um token de atualização cadastral (64 hex) ------------------------
-- MD5 gera 32 hex; concatenando dois MD5 diferentes obtemos 64 hex (compatível
-- com MySQL e MariaDB, sem depender de RANDOM_BYTES).
SET @tok := LOWER(CONCAT(MD5(RAND()), MD5(CONCAT(RAND(), NOW(6)))));

UPDATE users
SET registration_token = @tok, registration_token_created_at = NOW()
WHERE email = 'teste.cadastro@precadastro.local';

-- --- Mostra o token e a URL para abrir no navegador -------------------------
SELECT
    id AS user_id,
    name,
    registration_token AS token,
    CONCAT('https://multilife.onsolutionsbrasil.com.br/atualizar-cadastro?token=', registration_token) AS url_rota_limpa,
    CONCAT('https://multilife.onsolutionsbrasil.com.br/complete_registration.php?token=', registration_token) AS url_direta
FROM users
WHERE email = 'teste.cadastro@precadastro.local';
