-- Migration: revisão do fluxo de captação.
-- Data: 2026-10-27
--
-- 1) Permissão 'cadastros.manage' (especialidades, tipos de serviço, operadoras,
--    clientes, orientações) e acesso do CAPTADOR a todos os cadastros que o
--    fluxo começa por ele (usuários/profissionais, pacientes, contatos...).
--    Observação: sem 'roles.manage', o captador só cria/edita usuários com perfil
--    Profissional ou Paciente (trava no código, evita escalonamento de privilégio).
-- 2) Localização detalhada para grupos por CIDADE e ZONA (São Paulo/Rio):
--    demands.location_zone/location_zip, whatsapp_groups.zone,
--    users.state/neighborhood/zone e dados extras em imported_contacts.
--
-- Idempotente (MariaDB/MySQL 8 com ADD COLUMN IF NOT EXISTS). O código também
-- cria essas colunas sozinho se a migration não for rodada.

INSERT INTO permissions (name, slug)
SELECT 'Gerenciar cadastros básicos (especialidades, operadoras, clientes, serviços)', 'cadastros.manage' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'cadastros.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'cadastros.manage'
WHERE r.slug = 'admin'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'cadastros.manage', 'users.manage', 'patients.manage', 'patient_links.manage',
  'demands.manage', 'chat.manage', 'appointments.manage', 'whatsapp_groups.manage'
)
WHERE r.slug = 'captador'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

-- Localização da demanda
ALTER TABLE demands
  ADD COLUMN IF NOT EXISTS location_complement VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS location_zone VARCHAR(40) NULL COMMENT 'Zona em cidades divididas por zona (São Paulo/Rio)',
  ADD COLUMN IF NOT EXISTS location_zip VARCHAR(10) NULL;

-- Grupo por cidade + zona
ALTER TABLE whatsapp_groups
  ADD COLUMN IF NOT EXISTS zone VARCHAR(40) NULL;

-- Localização de atendimento do profissional
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS city VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS state CHAR(2) NULL,
  ADD COLUMN IF NOT EXISTS neighborhood VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS zone VARCHAR(40) NULL;

-- Contatos da agenda (profissionais) usados na captação e no pré-cadastro
ALTER TABLE imported_contacts
  ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS specialty VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS city VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS state CHAR(2) NULL,
  ADD COLUMN IF NOT EXISTS neighborhood VARCHAR(120) NULL;

-- Autorização: frequency era ENUM('single','daily','weekly',...) e rejeitava '3x_semana' etc.
ALTER TABLE authorization_requests
  MODIFY COLUMN frequency VARCHAR(30) NOT NULL DEFAULT 'weekly';

-- Senha do sistema externo agora é gravada criptografada (texto maior).
ALTER TABLE patient_assignments
  MODIFY COLUMN external_password VARCHAR(500) NULL;

-- Histórico de conexão das instâncias (detecção de WhatsApp em loop).
CREATE TABLE IF NOT EXISTS whatsapp_instance_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  instance_name VARCHAR(100) NOT NULL,
  event_type VARCHAR(30) NOT NULL COMMENT 'connection | qrcode | probe | send_error | send_ok',
  state VARCHAR(30) NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wie_instance_time (instance_name, created_at),
  KEY idx_wie_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grupos antigos: preenche a zona a partir do nome ("... - São Paulo/SP - Zona Sul - 2").
UPDATE whatsapp_groups SET zone = 'Zona Sul'   WHERE zone IS NULL AND name LIKE '%Zona Sul%';
UPDATE whatsapp_groups SET zone = 'Zona Norte' WHERE zone IS NULL AND name LIKE '%Zona Norte%';
UPDATE whatsapp_groups SET zone = 'Zona Leste' WHERE zone IS NULL AND name LIKE '%Zona Leste%';
UPDATE whatsapp_groups SET zone = 'Zona Oeste' WHERE zone IS NULL AND name LIKE '%Zona Oeste%';

-- Conferência:
-- SELECT p.slug FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'captador' ORDER BY p.slug;
