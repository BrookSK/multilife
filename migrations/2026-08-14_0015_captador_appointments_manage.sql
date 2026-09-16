-- ============================================
-- CORREÇÃO: Devolver ao CAPTADOR a permissão de enviar a captação para autorização
-- ============================================
-- Contexto:
--   A reestruturação de perfis (2026-08-14_0008_restructure_roles.sql) restringiu o
--   perfil 'captador' às permissões: demands.manage, chat.manage, whatsapp_groups.manage.
--   Com isso o captador perdeu 'appointments.manage'.
--
--   O envio da captação para autorização (popup "Selecionar profissional") posta em
--   chat_select_professional_post.php, que exige rbac_require_permission('appointments.manage').
--   Sem a permissão, o RBAC redireciona para /forbidden.php -> erro "forbidden" ao enviar.
--
-- Correção:
--   Reconceder 'appointments.manage' ao perfil 'captador' (idempotente).
-- Data: 2026-08-14
-- ============================================

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('appointments.manage')
WHERE r.slug = 'captador'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- Verificação
SELECT 'Permissões atuais do captador:' AS info,
       GROUP_CONCAT(p.slug ORDER BY p.slug SEPARATOR ', ') AS permissoes
FROM roles r
JOIN role_permissions rp ON rp.role_id = r.id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'captador';
