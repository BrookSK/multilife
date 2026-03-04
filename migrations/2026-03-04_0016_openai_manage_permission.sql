INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar OpenAI (ChatGPT)' AS name, 'openai.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'openai.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('openai.manage')
WHERE r.slug IN ('admin','ti')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
