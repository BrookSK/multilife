-- Configurar credenciais da Evolution API
-- Base URL: http://31.97.83.150:8080
-- Token: d0b9eb5a5fe4993598b4ef2b51b98d3d4fa8209e464e9988cf6fe86f9c1a194e

-- Inserir ou atualizar Base URL
INSERT INTO admin_settings (setting_key, setting_value, created_at, updated_at)
VALUES ('evolution.base_url', 'http://31.97.83.150:8080', NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_value = 'http://31.97.83.150:8080', updated_at = NOW();

-- Inserir ou atualizar API Key (Token)
INSERT INTO admin_settings (setting_key, setting_value, created_at, updated_at)
VALUES ('evolution.api_key', 'd0b9eb5a5fe4993598b4ef2b51b98d3d4fa8209e464e9988cf6fe86f9c1a194e', NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_value = 'd0b9eb5a5fe4993598b4ef2b51b98d3d4fa8209e464e9988cf6fe86f9c1a194e', updated_at = NOW();

-- Opcional: Definir nome da instância padrão (você pode alterar conforme necessário)
INSERT INTO admin_settings (setting_key, setting_value, created_at, updated_at)
VALUES ('evolution.instance', 'multilife_whatsapp', NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_value = 'multilife_whatsapp', updated_at = NOW();
