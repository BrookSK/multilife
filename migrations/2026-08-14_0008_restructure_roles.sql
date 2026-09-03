-- ============================================
-- REESTRUTURAÇÃO DOS PERFIS DE ACESSO (item 14)
-- Novos perfis principais: Administrador, Captador, Admissão, Financeiro, Profissional, Auditoria
-- - Criar 'admissao' e 'auditoria'
-- - Migrar 'analista' -> 'auditoria'
-- - Descontinuar 'ti', 'gerente_rh', 'analista'
-- Data: 2026-08-14
-- ============================================

-- --------------------------------------------
-- 1. CRIAR NOVOS PERFIS
-- --------------------------------------------
INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Admissão' AS name, 'admissao' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'admissao') LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Auditoria' AS name, 'auditoria' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'auditoria') LIMIT 1;

-- --------------------------------------------
-- 2. PERMISSÕES: ADMISSÃO
-- Captação + Pré-Admissão + fluxos de admissão + chat + pacientes + agendamentos
-- --------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'demands.manage',
  'patients.manage',
  'patient_links.manage',
  'appointments.manage',
  'chat.manage',
  'documents.manage',
  'whatsapp_groups.manage',
  'professional_applications.manage'
)
WHERE r.slug = 'admissao'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- --------------------------------------------
-- 3. PERMISSÕES: AUDITORIA (base semelhante ao antigo Analista, ajustada)
-- --------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'reports.view',
  'documents.manage',
  'patients.manage',
  'appointments.manage',
  'audit.view',
  'patient_access_logs.view'
)
WHERE r.slug = 'auditoria'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- --------------------------------------------
-- 4. GARANTIR PERMISSÕES DO CAPTADOR (acesso restrito à captação)
-- --------------------------------------------
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'demands.manage',
  'chat.manage',
  'whatsapp_groups.manage'
)
WHERE r.slug = 'captador'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- --------------------------------------------
-- 5. MIGRAR USUÁRIOS DOS PERFIS DESCONTINUADOS
-- analista -> auditoria
-- gerente_rh -> auditoria (perfil administrativo mais próximo com relatórios/auditoria)
-- ti -> admin (para não perder acesso; ajuste manual depois se necessário)
-- --------------------------------------------

-- analista -> auditoria
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT ur.user_id, (SELECT id FROM roles WHERE slug = 'auditoria' LIMIT 1)
FROM user_roles ur
JOIN roles r ON r.id = ur.role_id
WHERE r.slug = 'analista';

-- gerente_rh -> auditoria
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT ur.user_id, (SELECT id FROM roles WHERE slug = 'auditoria' LIMIT 1)
FROM user_roles ur
JOIN roles r ON r.id = ur.role_id
WHERE r.slug = 'gerente_rh';

-- ti -> admin (mantém acesso total; reveja manualmente se preferir outro perfil)
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT ur.user_id, (SELECT id FROM roles WHERE slug = 'admin' LIMIT 1)
FROM user_roles ur
JOIN roles r ON r.id = ur.role_id
WHERE r.slug = 'ti';

-- --------------------------------------------
-- 6. REMOVER OS VÍNCULOS E OS PERFIS DESCONTINUADOS
-- --------------------------------------------
-- Remover vínculos user_roles dos perfis descontinuados
DELETE ur FROM user_roles ur
JOIN roles r ON r.id = ur.role_id
WHERE r.slug IN ('analista', 'gerente_rh', 'ti');

-- Remover permissões dos perfis descontinuados
DELETE rp FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
WHERE r.slug IN ('analista', 'gerente_rh', 'ti');

-- Remover os perfis descontinuados
DELETE FROM roles WHERE slug IN ('analista', 'gerente_rh', 'ti');

-- --------------------------------------------
-- 7. VERIFICAÇÃO
-- --------------------------------------------
SELECT 'Perfis atuais:' AS info, GROUP_CONCAT(slug ORDER BY slug) AS perfis FROM roles;
SELECT 'Reestruturação de perfis concluída!' AS resultado;
