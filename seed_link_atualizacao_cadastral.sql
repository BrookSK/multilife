-- ============================================================================
-- TESTE RÁPIDO do link público de ATUALIZAÇÃO CADASTRAL (pós pré-admissão).
--
-- Cria (se não existir) um profissional de PRÉ-CADASTRO e gera um token de
-- atualização cadastral. Ao final, mostra a URL que você deve abrir no navegador.
--
-- Depois de rodar, copie o token exibido e acesse:
--   https://multilife.onsolutionsbrasil.com.br/atualizar-cadastro?token=SEU_TOKEN
-- (ou, sem rota limpa: /complete_registration.php?token=SEU_TOKEN)
-- ============================================================================

-- Garantir colunas de apoio (idempotente)
ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL;
ALTER TABLE users ADD COLUMN city VARCHAR(120) NULL;
ALTER TABLE users ADD COLUMN is_pre_registration TINYINT(1) NOT NULL DEFAULT 0;
-- Obs: se alguma coluna já existir, o MySQL retorna erro nessa linha específica;
-- pode ignorar o erro "Duplicate column name" e seguir.

-- Cria o profissional de teste (pré-cadastro) se ainda não existir
INSERT INTO users (name, email, phone, specialty, city, password_hash, status, is_pre_registration)
SELECT 'Profissional Teste Cadastro', 'teste.cadastro@precadastro.local', '11999990000', 'Fisioterapia', 'São Paulo',
       '$2y$10$abcdefghijklmnopqrstuv', 'active', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'teste.cadastro@precadastro.local');

-- Garante que ele tenha a role de profissional
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u JOIN roles r ON r.slug = 'profissional'
WHERE u.email = 'teste.cadastro@precadastro.local';

-- Gera um token de atualização cadastral (64 hex)
SET @tok = LOWER(CONCAT(
    HEX(RANDOM_BYTES(16)), HEX(RANDOM_BYTES(16))
));

UPDATE users
SET registration_token = @tok, registration_token_created_at = NOW()
WHERE email = 'teste.cadastro@precadastro.local';

-- Mostra o token e a URL para abrir no navegador
SELECT
    id AS user_id,
    name,
    registration_token AS token,
    CONCAT('https://multilife.onsolutionsbrasil.com.br/atualizar-cadastro?token=', registration_token) AS url_para_abrir
FROM users
WHERE email = 'teste.cadastro@precadastro.local';
