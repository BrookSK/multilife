-- ============================================================================
-- Multi-perfil por conexão WhatsApp (N:N)
-- ============================================================================
-- Contexto:
--   Hoje whatsapp_instances.user_id vincula UMA conexão a UM usuário.
--   Precisamos permitir vincular VÁRIOS usuários à MESMA conexão.
--
-- Estratégia (sem quebrar o existente):
--   - Cria tabela de junção whatsapp_instance_users (instance_id + user_id).
--   - Migra os vínculos atuais (whatsapp_instances.user_id) para a junção.
--   - Mantém a coluna user_id na tabela original por compatibilidade
--     (passa a representar o "dono principal"/primeiro vínculo).
-- Data: 2026-08-14
-- ============================================================================

CREATE TABLE IF NOT EXISTS whatsapp_instance_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    instance_id INT UNSIGNED NOT NULL COMMENT 'FK para whatsapp_instances.id',
    user_id INT UNSIGNED NOT NULL COMMENT 'FK para users.id',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE INDEX idx_wiu_unique (instance_id, user_id),
    INDEX idx_wiu_instance (instance_id),
    INDEX idx_wiu_user (user_id),
    CONSTRAINT fk_wiu_instance FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE CASCADE,
    CONSTRAINT fk_wiu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrar vínculos existentes (1:1) para a tabela de junção (idempotente)
INSERT IGNORE INTO whatsapp_instance_users (instance_id, user_id)
SELECT wi.id, wi.user_id
FROM whatsapp_instances wi
WHERE wi.user_id IS NOT NULL;
