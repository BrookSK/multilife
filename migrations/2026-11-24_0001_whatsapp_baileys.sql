-- Troca da Evolution API pelo serviço WhatsApp próprio (ml-baileys-service).
--
-- Novas chaves de configuração (prefixo baileys.*). URL e API Key do serviço
-- ficam vazias: preencher em Configurações → WhatsApp Conexão → Credenciais.
-- As demais copiam o valor que já existia na chave antiga (evolution.*).
-- O sistema funciona mesmo sem rodar este arquivo (wa_setting() lê a chave
-- antiga quando a nova está vazia); rodar só deixa a configuração explícita.
-- Não apaga nada: as chaves evolution.* continuam no banco.

INSERT IGNORE INTO admin_settings (setting_key, setting_value) VALUES
    ('baileys.base_url', ''),
    ('baileys.api_key', '');

INSERT IGNORE INTO admin_settings (setting_key, setting_value)
SELECT 'baileys.instance', setting_value FROM admin_settings WHERE setting_key = 'evolution.instance';

INSERT IGNORE INTO admin_settings (setting_key, setting_value)
SELECT 'baileys.admin_phone', setting_value FROM admin_settings WHERE setting_key = 'evolution.admin_phone';

INSERT IGNORE INTO admin_settings (setting_key, setting_value)
SELECT 'baileys.watchdog_auto_heal', setting_value FROM admin_settings WHERE setting_key = 'evolution.watchdog_auto_heal';

INSERT IGNORE INTO admin_settings (setting_key, setting_value)
SELECT 'baileys.webhook_token', setting_value FROM admin_settings WHERE setting_key = 'evolution.webhook_token';

-- Jobs de WhatsApp ainda na fila passam para o nome novo do provedor
-- (o cron também aceita o nome antigo, então isto é só organização).
UPDATE integration_jobs SET provider = 'whatsapp'
WHERE provider = 'evolution' AND status IN ('pending', 'error', 'running');
