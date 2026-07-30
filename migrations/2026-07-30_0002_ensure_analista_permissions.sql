-- Garantir que as permissoes necessarias existem
INSERT INTO permissions (name, slug) SELECT 'Gerenciar agendamentos', 'appointments.manage' FROM dual WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'appointments.manage');
INSERT INTO permissions (name, slug) SELECT 'Visualizar relatorios', 'reports.view' FROM dual WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'reports.view');
INSERT INTO permissions (name, slug) SELECT 'Gerenciar pacientes', 'patients.manage' FROM dual WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'patients.manage');
INSERT INTO permissions (name, slug) SELECT 'Gerenciar documentos', 'documents.manage' FROM dual WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'documents.manage');

-- Associar permissoes ao role analista
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'appointments.manage'
WHERE r.slug = 'analista' AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'reports.view'
WHERE r.slug = 'analista' AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'patients.manage'
WHERE r.slug = 'analista' AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'documents.manage'
WHERE r.slug = 'analista' AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);
