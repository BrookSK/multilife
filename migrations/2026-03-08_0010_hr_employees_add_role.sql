-- ============================================
-- SISTEMA DE FUNÇÕES PARA RH
-- Adiciona campo role_id e pré-configura funções padrão
-- ============================================

-- 1. Adicionar campo role_id na tabela hr_employees
ALTER TABLE hr_employees
ADD COLUMN IF NOT EXISTS role_id INT UNSIGNED NULL AFTER department,
ADD KEY IF NOT EXISTS idx_hr_employees_role (role_id);

-- 2. Adicionar constraint de foreign key se não existir
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'hr_employees' 
    AND CONSTRAINT_NAME = 'fk_hr_employees_role'
);

SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE hr_employees ADD CONSTRAINT fk_hr_employees_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL',
    'SELECT "Constraint fk_hr_employees_role já existe" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 3. PRÉ-CONFIGURAÇÃO DE FUNÇÕES PADRÃO
-- ============================================

-- Criar funções padrão se não existirem
INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Administrador' AS name, 'admin' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'admin')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Financeiro' AS name, 'financeiro' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'financeiro')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Captador / Admissão' AS name, 'captador' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'captador')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'TI / Suporte' AS name, 'ti' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'ti')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Profissional de Saúde' AS name, 'profissional' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'profissional')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Gerente de RH' AS name, 'gerente_rh' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'gerente_rh')
LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Analista' AS name, 'analista' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'analista')
LIMIT 1;

-- ============================================
-- 4. CONFIGURAR PERMISSÕES PARA CADA FUNÇÃO
-- ============================================

-- ADMIN: Acesso total ao sistema
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'users.manage','roles.manage','permissions.manage',
  'demands.manage','whatsapp_groups.manage','chat.manage',
  'professional_applications.manage',
  'professional_docs.submit','professional_docs.review',
  'patients.manage','patient_links.manage','patients.view_linked',
  'appointments.manage','appointments.view_linked',
  'finance.manage','documents.manage',
  'admin.dashboard','admin.settings.manage','hr.manage',
  'tech_logs.view','integration_jobs.manage',
  'patient_access_logs.view','backups.manage',
  'reports.view','audit.view',
  'whatsapp.manage','openai.manage','zapsign.manage',
  'patients.lgpd.anonymize','patient_prontuario.manage'
)
WHERE r.slug = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- FINANCEIRO: Gestão financeira e revisão de documentos
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'finance.manage',
  'professional_docs.review',
  'reports.view',
  'documents.manage'
)
WHERE r.slug = 'financeiro'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- CAPTADOR: Captação, pacientes e agendamentos
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
  'whatsapp_groups.manage'
)
WHERE r.slug = 'captador'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- TI: Infraestrutura técnica e integrações
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'tech_logs.view',
  'integration_jobs.manage',
  'backups.manage',
  'patient_access_logs.view',
  'documents.manage',
  'whatsapp.manage',
  'openai.manage',
  'zapsign.manage',
  'reports.view',
  'admin.settings.manage'
)
WHERE r.slug = 'ti'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- PROFISSIONAL: Acesso limitado aos próprios pacientes
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'professional_docs.submit',
  'patients.view_linked',
  'appointments.view_linked'
)
WHERE r.slug = 'profissional'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- GERENTE DE RH: Gestão completa de RH
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'hr.manage',
  'users.manage',
  'documents.manage',
  'reports.view',
  'audit.view'
)
WHERE r.slug = 'gerente_rh'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- ANALISTA: Acesso básico para análise e relatórios
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
  'reports.view',
  'documents.manage',
  'patients.manage',
  'appointments.manage'
)
WHERE r.slug = 'analista'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
