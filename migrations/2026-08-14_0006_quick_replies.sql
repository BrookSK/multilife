-- ============================================
-- RESPOSTAS RÁPIDAS (Quick Replies) para o Chat ao Vivo
-- Suporta respostas globais (user_id NULL) e individuais (user_id preenchido)
-- Data: 2026-08-14
-- ============================================

CREATE TABLE IF NOT EXISTS quick_replies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL COMMENT 'NULL = resposta global; preenchido = individual do usuário',
    title VARCHAR(120) NOT NULL COMMENT 'Título/atalho curto da resposta',
    content TEXT NOT NULL COMMENT 'Conteúdo da mensagem',
    scope ENUM('global','individual') NOT NULL DEFAULT 'individual',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_quick_replies_user (user_id),
    KEY idx_quick_replies_scope (scope),
    CONSTRAINT fk_quick_replies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_quick_replies_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissão para gerenciar respostas rápidas globais (só admin/autorizados)
INSERT INTO permissions (name, slug)
SELECT * FROM (
  SELECT 'Gerenciar respostas rápidas globais' AS name, 'quick_replies.manage_global' AS slug
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'quick_replies.manage_global')
LIMIT 1;

-- Dar a permissão de respostas globais ao admin
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'quick_replies.manage_global'
WHERE r.slug = 'admin'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

SELECT 'Tabela quick_replies criada!' AS resultado;
