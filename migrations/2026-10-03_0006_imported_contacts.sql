-- Migration: Importador de contatos (agenda do celular) — Ajuste 2.
-- Data: 2026-10-03
--
-- Contatos exportados (nome, telefone, categoria: cliente/profissional/outros)
-- ficam pré-cadastrados e disponíveis para a equipe de captação.

CREATE TABLE IF NOT EXISTS imported_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    phone VARCHAR(32) NULL,            -- somente dígitos (normalizado)
    phone_raw VARCHAR(64) NULL,        -- como veio no arquivo
    category ENUM('cliente','profissional','outros') NOT NULL DEFAULT 'outros',
    source VARCHAR(120) NULL,          -- origem (nome do arquivo, etc.)
    notes VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ic_category (category),
    KEY idx_ic_name (name),
    -- Evita duplicar o mesmo telefone na mesma categoria.
    UNIQUE KEY uk_ic_phone_category (phone, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
