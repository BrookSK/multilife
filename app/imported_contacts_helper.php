<?php

declare(strict_types=1);

/**
 * Helper do importador de contatos (Ajuste 2).
 */

function imported_contacts_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS imported_contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            phone VARCHAR(32) NULL,
            phone_raw VARCHAR(64) NULL,
            category ENUM('cliente','profissional','outros') NOT NULL DEFAULT 'outros',
            source VARCHAR(120) NULL,
            notes VARCHAR(255) NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ic_category (category),
            KEY idx_ic_name (name),
            UNIQUE KEY uk_ic_phone_category (phone, category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[IMPORTED_CONTACTS] ensure schema: ' . $e->getMessage());
    }
}

/**
 * Normaliza um telefone para apenas dígitos (com DDI 55 quando aplicável).
 */
function imported_contacts_normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }
    // Remove zeros à esquerda de operadora e normaliza para começar com 55 (Brasil)
    // quando o número tem 10-11 dígitos (DDD + número).
    $len = strlen($digits);
    if ($len === 10 || $len === 11) {
        $digits = '55' . $digits;
    }
    return $digits;
}

/**
 * Detecta a categoria (cliente/profissional/outros) a partir de um texto marcador
 * e/ou do nome. Flexível: cobre as marcações mais comuns da exportação.
 *
 * @param string $marker Texto da coluna de categoria (se houver)
 * @param string $name   Nome do contato (pode conter prefixo tipo "CLIENTE - Fulano")
 */
function imported_contacts_detect_category(string $marker, string $name = ''): string
{
    $hay = mb_strtolower($marker . ' ' . $name);

    // Cliente / paciente / contratante.
    if (preg_match('/\b(cliente|clientes|paciente|pacientes|contratante|familia|família|responsavel|responsável)\b/u', $hay)) {
        return 'cliente';
    }
    // Profissional / especialidades comuns.
    if (preg_match('/\b(profissional|profissionais|prof|fisio|fisioterapeuta|fono|fonoaudi|enfermeir|cuidador|tecnic|médic|medic|nutri|psicolog|terapeuta|to)\b/u', $hay)) {
        return 'profissional';
    }
    return 'outros';
}

/**
 * Remove um prefixo marcador do nome ("CLIENTE - Fulano" -> "Fulano").
 */
function imported_contacts_clean_name(string $name): string
{
    $clean = preg_replace('/^\s*(cliente|clientes|paciente|profissional|profissionais|prof|outros?)\s*[-:–]\s*/iu', '', $name);
    return trim((string)$clean);
}
