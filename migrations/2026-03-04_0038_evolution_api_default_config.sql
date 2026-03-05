-- Configuração padrão da Evolution API
-- Credenciais fornecidas pelo usuário

INSERT INTO admin_settings (setting_key, setting_value)
VALUES 
    ('evolution.base_url', 'http://31.97.83.150:8080'),
    ('evolution.api_key', 'd0b9eb5a5fe4993598b4ef2b51b98d3d4fa8209e464e9988cf6fe86f9c1a194e'),
    ('evolution.instance', 'multilife')
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value);
