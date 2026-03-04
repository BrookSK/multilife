INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Acessar relatórios' AS name, 'reports.view' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'reports.view')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('reports.view')
WHERE r.slug IN ('admin','financeiro','ti')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
