INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar prontuário (Admin)' AS name, 'patient_prontuario.manage' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'patient_prontuario.manage')
LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'patient_prontuario.manage'
WHERE r.slug IN ('admin')
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
