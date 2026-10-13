<?php

declare(strict_types=1);

/**
 * Clients / Operators Helper
 *
 * Separa os dois conceitos:
 *   - CLIENTE (tabela clients): o contratante/pagador (ex.: GLOBAL).
 *   - OPERADORA (tabela health_insurers): o convênio (ex.: BRADESCO), que
 *     pertence a um cliente (health_insurers.client_id).
 *
 * Cada um tem closing_day (dia de entrega/conferência ao financeiro). A
 * competência continua sendo o mês-calendário; o closing_day é só o prazo.
 *
 * Identificação SEMPRE por id (nomes podem repetir entre clientes/operadoras).
 */

/**
 * Garante (idempotente) as estruturas do refactor cliente/operadora.
 * Chamado defensivamente pelas telas para o caso da migration não ter rodado.
 */
function clients_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = db();

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS clients (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            cnpj VARCHAR(18) NULL,
            contact_phone VARCHAR(20) NULL,
            contact_email VARCHAR(255) NULL,
            billing_email VARCHAR(255) NULL,
            email_domain VARCHAR(255) NULL,
            closing_day TINYINT UNSIGNED NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_clients_active (is_active),
            KEY idx_clients_email_domain (email_domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('[CLIENTS] ensure clients: ' . $e->getMessage()); }

    // Colunas idempotentes (ignora erro se já existirem).
    $alters = [
        "ALTER TABLE health_insurers ADD COLUMN client_id INT UNSIGNED NULL",
        "ALTER TABLE health_insurers ADD COLUMN closing_day TINYINT UNSIGNED NULL",
        "ALTER TABLE patient_assignments ADD COLUMN client_id INT UNSIGNED NULL",
        "ALTER TABLE demands ADD COLUMN client_id INT UNSIGNED NULL",
        "ALTER TABLE demands ADD COLUMN health_insurer_id INT UNSIGNED NULL",
        "ALTER TABLE financial_entries ADD COLUMN client_id INT UNSIGNED NULL",
        "ALTER TABLE financial_entries ADD COLUMN health_insurer_id INT UNSIGNED NULL",
        "ALTER TABLE billing_monthly_closure_items ADD COLUMN client_id INT UNSIGNED NULL",
        "ALTER TABLE billing_monthly_closures ADD COLUMN scope ENUM('global','operator','client') NOT NULL DEFAULT 'global'",
        "ALTER TABLE billing_monthly_closures ADD COLUMN client_id INT UNSIGNED NULL",
        "ALTER TABLE billing_monthly_closures ADD COLUMN health_insurer_id INT UNSIGNED NULL",
        "ALTER TABLE billing_monthly_closures ADD COLUMN sent_to_finance_at DATETIME NULL",
        "ALTER TABLE billing_monthly_closures ADD COLUMN sent_to_finance_by_user_id INT UNSIGNED NULL",
    ];
    foreach ($alters as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* já existe */ }
    }
}

/**
 * Lista clientes (contratantes).
 *
 * @param bool $onlyActive
 * @return array<int,array<string,mixed>>
 */
function clients_list(bool $onlyActive = false): array
{
    clients_ensure_schema();
    $where = $onlyActive ? 'WHERE is_active = 1' : '';
    $stmt = db()->query("SELECT * FROM clients $where ORDER BY name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca um cliente por id.
 */
function clients_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    clients_ensure_schema();
    $stmt = db()->prepare("SELECT * FROM clients WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Lista operadoras (health_insurers), opcionalmente de um cliente.
 *
 * @param int|null $clientId Filtra por cliente (null = todas)
 * @param bool $onlyActive
 * @return array<int,array<string,mixed>>
 */
function operators_list(?int $clientId = null, bool $onlyActive = false): array
{
    clients_ensure_schema();
    $where = [];
    $params = [];
    if ($clientId !== null && $clientId > 0) {
        $where[] = 'hi.client_id = :cid';
        $params['cid'] = $clientId;
    }
    if ($onlyActive) {
        $where[] = 'hi.is_active = 1';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("
        SELECT hi.*, c.name AS client_name
        FROM health_insurers hi
        LEFT JOIN clients c ON c.id = hi.client_id
        $whereSql
        ORDER BY hi.name ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca uma operadora por id (com dados do cliente).
 */
function operators_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    clients_ensure_schema();
    $stmt = db()->prepare("
        SELECT hi.*, c.name AS client_name, c.closing_day AS client_closing_day
        FROM health_insurers hi
        LEFT JOIN clients c ON c.id = hi.client_id
        WHERE hi.id = :id LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Resolve o client_id de uma operadora.
 */
function operator_client_id(int $operatorId): ?int
{
    $op = operators_find($operatorId);
    if ($op === null || $op['client_id'] === null) {
        return null;
    }
    return (int)$op['client_id'];
}

/**
 * Tenta detectar cliente e/ou operadora a partir de um e-mail (pelo domínio).
 * Usado na captação (card nasce já classificado quando possível).
 *
 * @return array{client_id:?int, health_insurer_id:?int}
 */
function clients_detect_from_email(string $email): array
{
    $result = ['client_id' => null, 'health_insurer_id' => null];
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return $result;
    }
    clients_ensure_schema();
    $domain = strtolower(trim(substr(strrchr($email, '@'), 1)));
    if ($domain === '') {
        return $result;
    }

    // 1) Operadora por domínio (traz o cliente junto).
    $stmt = db()->prepare("SELECT id, client_id FROM health_insurers WHERE email_domain = :d AND is_active = 1 LIMIT 1");
    $stmt->execute(['d' => $domain]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($op) {
        $result['health_insurer_id'] = (int)$op['id'];
        $result['client_id'] = $op['client_id'] !== null ? (int)$op['client_id'] : null;
        return $result;
    }

    // 2) Cliente por domínio.
    $stmt = db()->prepare("SELECT id FROM clients WHERE email_domain = :d AND is_active = 1 LIMIT 1");
    $stmt->execute(['d' => $domain]);
    $cl = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cl) {
        $result['client_id'] = (int)$cl['id'];
    }

    return $result;
}
