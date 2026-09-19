-- Migration: Permissão específica para a tela de Fechamento Mensal de Faturamento
-- Data: 2026-09-18
--
-- Contexto (reunião 15/09): a equipe de ADMISSÃO precisa controlar o fechamento
-- mensal do faturamento, MAS não deve ter acesso às áreas financeiras
-- (Contas a Pagar, Contas a Receber, Lançamentos), que seguem sob finance.manage.
--
-- Criamos uma permissão dedicada 'billing.closure.manage' para a tela/fechamento,
-- concedida a admin, financeiro e admissao. Assim a admissão fecha o mês sem ganhar
-- finance.manage; o Financeiro continua responsável pela parte financeira.

-- 1) Criar a permissão (idempotente).
INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar fechamento mensal de faturamento' AS name, 'billing.closure.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'billing.closure.manage')
LIMIT 1;

-- 2) Conceder a admin, financeiro e admissao (idempotente).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'billing.closure.manage'
WHERE r.slug IN ('admin', 'financeiro', 'admissao')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
