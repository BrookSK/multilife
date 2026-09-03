-- ============================================================================
-- MIGRATION CONSOLIDADA - MultiLife
-- Agrupa TODAS as migrations de funcionalidade criadas (0006 a 0013).
-- Cobre os itens: 2 (respostas rápidas), 4 (tempo indefinido), 5/11 (finalização),
-- 6 (config já é setting - sem SQL), 7 (eventos clínicos), 8 (histórico consolidado),
-- 13 (docs por tipo de profissional), 14 (perfis), 17 (performance captação).
-- Data: 2026-08-14
--
-- NÃO inclui os SQL de importação de planilhas / limpeza de teste (0001-0005),
-- que são de dados pontuais, não de estrutura.
--
-- Execute UMA vez. Todas as operações são idempotentes.
-- ============================================================================


-- ============================================================================
-- PARTE 1 (item 2) - RESPOSTAS RÁPIDAS NO CHAT AO VIVO
-- ============================================================================

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

INSERT INTO permissions (name, slug)
SELECT * FROM (SELECT 'Gerenciar respostas rápidas globais' AS name, 'quick_replies.manage_global' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE slug = 'quick_replies.manage_global') LIMIT 1;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'quick_replies.manage_global'
WHERE r.slug = 'admin'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);


-- ============================================================================
-- PARTE 2 (item 4) - PRÉ-ADMISSÃO: TEMPO INDEFINIDO
-- ============================================================================

ALTER TABLE authorization_requests
    ADD COLUMN IF NOT EXISTS is_indefinite TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Se 1, atendimento por tempo indefinido (sem data final/semanas fixas)';

ALTER TABLE patient_assignments
    ADD COLUMN IF NOT EXISTS is_indefinite TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Se 1, atendimento por tempo indefinido';


-- ============================================================================
-- PARTE 3 (item 14) - REESTRUTURAÇÃO DOS PERFIS DE ACESSO
-- Cria 'admissao' e 'auditoria'; migra 'analista'->'auditoria', 'gerente_rh'->'auditoria',
-- 'ti'->'admin'; descontinua 'ti', 'gerente_rh', 'analista'.
-- ============================================================================

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Admissão' AS name, 'admissao' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'admissao') LIMIT 1;

INSERT INTO roles (name, slug)
SELECT * FROM (SELECT 'Auditoria' AS name, 'auditoria' AS slug) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'auditoria') LIMIT 1;

-- Permissões: ADMISSÃO
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug IN (
  'demands.manage','patients.manage','patient_links.manage','appointments.manage',
  'chat.manage','documents.manage','whatsapp_groups.manage','professional_applications.manage'
)
WHERE r.slug = 'admissao'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

-- Permissões: AUDITORIA
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug IN (
  'reports.view','documents.manage','patients.manage','appointments.manage',
  'audit.view','patient_access_logs.view'
)
WHERE r.slug = 'auditoria'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

-- Garantir permissões do CAPTADOR (acesso restrito à captação)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug IN ('demands.manage','chat.manage','whatsapp_groups.manage')
WHERE r.slug = 'captador'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

-- Migrar usuários dos perfis descontinuados
INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT ur.user_id, (SELECT id FROM roles WHERE slug = 'auditoria' LIMIT 1)
FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'analista';

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT ur.user_id, (SELECT id FROM roles WHERE slug = 'auditoria' LIMIT 1)
FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'gerente_rh';

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT ur.user_id, (SELECT id FROM roles WHERE slug = 'admin' LIMIT 1)
FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'ti';

-- Remover vínculos, permissões e os perfis descontinuados
DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id
WHERE r.slug IN ('analista', 'gerente_rh', 'ti');

DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
WHERE r.slug IN ('analista', 'gerente_rh', 'ti');

DELETE FROM roles WHERE slug IN ('analista', 'gerente_rh', 'ti');


-- ============================================================================
-- PARTE 4 (itens 5 e 11) - FINALIZAÇÃO DE ATENDIMENTO COM MOTIVO
-- ============================================================================

CREATE TABLE IF NOT EXISTS treatment_end_reasons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = motivo padrão do sistema (não excluível)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_end_reason_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO treatment_end_reasons (name, slug, is_system) VALUES
  ('Atendimento finalizado normalmente', 'finalizado_normal', 1),
  ('Óbito', 'obito', 1),
  ('Alta', 'alta', 1),
  ('Transferência', 'transferencia', 1),
  ('Cancelamento', 'cancelamento', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

ALTER TABLE patient_assignments
    ADD COLUMN IF NOT EXISTS ended_at DATETIME NULL COMMENT 'Data/hora do encerramento do atendimento',
    ADD COLUMN IF NOT EXISTS end_reason_id INT UNSIGNED NULL COMMENT 'FK treatment_end_reasons',
    ADD COLUMN IF NOT EXISTS end_notes TEXT NULL COMMENT 'Observações do encerramento',
    ADD COLUMN IF NOT EXISTS ended_by_user_id INT UNSIGNED NULL;


-- ============================================================================
-- PARTE 5 (item 7) - EVENTOS CLÍNICOS DATADOS DO PACIENTE
-- ============================================================================

CREATE TABLE IF NOT EXISTS patient_clinical_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('internacao','obito','alta','retorno','transferencia','outro') NOT NULL,
    event_date DATE NOT NULL,
    event_time TIME NULL,
    notes TEXT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pce_patient (patient_id),
    KEY idx_pce_type (event_type),
    CONSTRAINT fk_pce_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE patients
    ADD COLUMN IF NOT EXISTS is_closed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Paciente encerrado (ex: óbito)',
    ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS closed_reason VARCHAR(60) NULL;


-- ============================================================================
-- PARTE 6 (item 8) - HISTÓRICO / INFORMAÇÕES CLÍNICAS CONSOLIDADAS
-- ============================================================================

ALTER TABLE patients
    ADD COLUMN IF NOT EXISTS clinical_summary TEXT NULL
    COMMENT 'Histórico e informações clínicas consolidadas (item 8)';


-- ============================================================================
-- PARTE 7 (item 13) - DOCUMENTAÇÃO DE OPERADORA POR TIPO DE PROFISSIONAL
-- ============================================================================

ALTER TABLE health_insurer_documents
    ADD COLUMN IF NOT EXISTS professional_type ENUM('novo','antigo','ambos') NOT NULL DEFAULT 'ambos'
    COMMENT 'Documentação exigida para profissional novo, antigo ou ambos';


-- ============================================================================
-- PARTE 8 (item 17) - PERFORMANCE DA CAPTAÇÃO (lotes/background)
-- ============================================================================

CREATE TABLE IF NOT EXISTS demand_dispatch_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    demand_id BIGINT UNSIGNED NOT NULL,
    group_jid VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL COMMENT 'Número normalizado (só dígitos, com 55)',
    status ENUM('pending','added','error','skipped') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(255) NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_dispatch_target (demand_id, group_jid, phone),
    KEY idx_ddt_status (status),
    KEY idx_ddt_demand (demand_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demand_dispatch_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    demand_id BIGINT UNSIGNED NOT NULL,
    group_jid VARCHAR(100) NOT NULL,
    group_db_id BIGINT UNSIGNED NULL,
    total_targets INT UNSIGNED NOT NULL DEFAULT 0,
    processed_targets INT UNSIGNED NOT NULL DEFAULT 0,
    added_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('queued','processing','completed','completed_with_errors') NOT NULL DEFAULT 'queued',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ddr_demand (demand_id),
    KEY idx_ddr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- VERIFICAÇÃO FINAL
-- ============================================================================
SELECT 'Perfis atuais:' AS info, GROUP_CONCAT(slug ORDER BY slug) AS perfis FROM roles;
SELECT 'Migration consolidada aplicada com sucesso!' AS resultado;
