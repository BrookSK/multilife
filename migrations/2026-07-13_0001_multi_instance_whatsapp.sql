-- Migration: Multi-instância WhatsApp por usuário
-- Data: 2026-07-13
-- Descrição: Permite que cada usuário tenha sua própria conexão WhatsApp.
--            Se o usuário não tiver instância própria, usa a padrão da plataforma.
--            Na criação de grupos, todos os números conectados são adicionados.

-- Adicionar colunas na tabela whatsapp_instances para suporte multi-instância
ALTER TABLE whatsapp_instances
ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL COMMENT 'Usuário dono desta instância (NULL = instância da plataforma/padrão)' AFTER id,
ADD COLUMN IF NOT EXISTS is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 se é a instância padrão da plataforma' AFTER user_id,
ADD COLUMN IF NOT EXISTS connection_status ENUM('disconnected','connecting','connected') NOT NULL DEFAULT 'disconnected' COMMENT 'Status da conexão atual' AFTER status,
ADD COLUMN IF NOT EXISTS owner_phone_formatted VARCHAR(20) NULL COMMENT 'Número formatado com DDI (55XXXXXXXXXXX)' AFTER owner_number;

-- Índices para busca rápida
ALTER TABLE whatsapp_instances
ADD INDEX IF NOT EXISTS idx_whatsapp_instances_user_id (user_id),
ADD INDEX IF NOT EXISTS idx_whatsapp_instances_is_default (is_default),
ADD INDEX IF NOT EXISTS idx_whatsapp_instances_connection_status (connection_status);

-- FK para users (se não existir)
-- Nota: usar SET NULL para não impedir deleção do usuário
ALTER TABLE whatsapp_instances
ADD CONSTRAINT fk_whatsapp_instances_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Marcar a instância padrão existente (a configurada em admin_settings)
UPDATE whatsapp_instances wi
SET wi.is_default = 1
WHERE wi.instance_name = (SELECT setting_value FROM admin_settings WHERE setting_key = 'evolution.instance' LIMIT 1)
LIMIT 1;

-- Se não existir nenhuma instância marcada como default, inserir a configurada
INSERT INTO whatsapp_instances (instance_name, token, owner_number, status, is_default, connection_status)
SELECT 
    s_inst.setting_value,
    s_key.setting_value,
    NULL,
    'active',
    1,
    'connected'
FROM admin_settings s_inst
LEFT JOIN admin_settings s_key ON s_key.setting_key = 'evolution.api_key'
WHERE s_inst.setting_key = 'evolution.instance'
AND s_inst.setting_value IS NOT NULL
AND s_inst.setting_value != ''
AND NOT EXISTS (SELECT 1 FROM whatsapp_instances WHERE is_default = 1)
LIMIT 1;

-- Permissão para usuário gerenciar sua própria instância WhatsApp
INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar WhatsApp próprio' AS name, 'whatsapp.own_instance' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'whatsapp.own_instance')
LIMIT 1;

-- Dar essa permissão a todos os roles que já têm demands.manage
INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p.id
FROM role_permissions rp
JOIN permissions pm ON pm.id = rp.permission_id AND pm.slug = 'demands.manage'
JOIN permissions p ON p.slug = 'whatsapp.own_instance'
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp2
    WHERE rp2.role_id = rp.role_id AND rp2.permission_id = p.id
);
