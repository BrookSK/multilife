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
        // Polo (cliente -> polo -> operadora) e dados editáveis do atendimento.
        "ALTER TABLE health_insurers ADD COLUMN polo_id INT UNSIGNED NULL",
        "ALTER TABLE demands ADD COLUMN polo_id INT UNSIGNED NULL",
        "ALTER TABLE patient_assignments ADD COLUMN polo_id INT UNSIGNED NULL",
        "ALTER TABLE patient_assignments ADD COLUMN internal_notes TEXT NULL",
        "ALTER TABLE patient_assignments ADD COLUMN weaning_at DATE NULL",
        // Usadas na remarcação da agenda (desmame) também a partir do Fechamento.
        "ALTER TABLE patient_assignments ADD COLUMN month_days VARCHAR(120) NULL",
        "ALTER TABLE patient_assignments ADD COLUMN is_indefinite TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE financial_entries ADD COLUMN polo_id INT UNSIGNED NULL",
        "ALTER TABLE billing_monthly_closure_items ADD COLUMN polo_id INT UNSIGNED NULL",
    ];
    foreach ($alters as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* já existe */ }
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS polos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id INT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            city VARCHAR(120) NULL,
            state CHAR(2) NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_polos_client (client_id),
            KEY idx_polos_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('[CLIENTS] ensure polos: ' . $e->getMessage()); }
}

/**
 * Lista polos, opcionalmente de um cliente.
 *
 * @return array<int,array<string,mixed>>
 */
function polos_list(?int $clientId = null, bool $onlyActive = false): array
{
    clients_ensure_schema();
    $where = [];
    $params = [];
    if ($clientId !== null && $clientId > 0) {
        $where[] = 'po.client_id = :cid';
        $params['cid'] = $clientId;
    }
    if ($onlyActive) {
        $where[] = 'po.is_active = 1';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    try {
        $stmt = db()->prepare("
            SELECT po.*, c.name AS client_name
            FROM polos po
            LEFT JOIN clients c ON c.id = po.client_id
            $whereSql
            ORDER BY c.name ASC, po.name ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[POLOS] list: ' . $e->getMessage());
        return [];
    }
}

/**
 * Busca um polo por id.
 */
function polos_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    clients_ensure_schema();
    try {
        $stmt = db()->prepare("SELECT po.*, c.name AS client_name FROM polos po LEFT JOIN clients c ON c.id = po.client_id WHERE po.id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Polos encontrados nos dados do projeto (sugestão de cadastro inicial).
 * Fontes:
 *   - docs/contatos.csv: "Polo Araçatuba - Cenemed", "Polo Bauru - Cenemed" etc.;
 *   - orientações da GLOBAL (migrations 2026-10-03_0009 e 2026-10-27_0003):
 *     "Rio Preto/SP/Prudente/Campinas" e "Salvador/Recife".
 * Mesma lista da migration 2026-11-11_0002_polos_iniciais.sql.
 *
 * @return array<int,array{client:string,name:string,city:string,state:string,source:string}>
 */
function polos_seed_list(): array
{
    $global = 'Orientações da GLOBAL (Rio Preto/SP/Prudente/Campinas, Salvador/Recife)';
    $cene = 'Contatos: "Polo ... - Cenemed"';
    return [
        ['client' => 'GLOBAL', 'name' => 'Rio Preto', 'city' => 'São José do Rio Preto', 'state' => 'SP', 'source' => $global],
        ['client' => 'GLOBAL', 'name' => 'São Paulo', 'city' => 'São Paulo', 'state' => 'SP', 'source' => $global],
        ['client' => 'GLOBAL', 'name' => 'Presidente Prudente', 'city' => 'Presidente Prudente', 'state' => 'SP', 'source' => $global],
        ['client' => 'GLOBAL', 'name' => 'Campinas', 'city' => 'Campinas', 'state' => 'SP', 'source' => $global],
        ['client' => 'GLOBAL', 'name' => 'Salvador', 'city' => 'Salvador', 'state' => 'BA', 'source' => $global],
        ['client' => 'GLOBAL', 'name' => 'Recife', 'city' => 'Recife', 'state' => 'PE', 'source' => $global],
        ['client' => 'CENEMED', 'name' => 'Araçatuba', 'city' => 'Araçatuba', 'state' => 'SP', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'Bauru', 'city' => 'Bauru', 'state' => 'SP', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'Campinas', 'city' => 'Campinas', 'state' => 'SP', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'Divinópolis', 'city' => 'Divinópolis', 'state' => 'MG', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'Fernandópolis', 'city' => 'Fernandópolis', 'state' => 'SP', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'Goiânia', 'city' => 'Goiânia', 'state' => 'GO', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'Marília', 'city' => 'Marília', 'state' => 'SP', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'Presidente Prudente', 'city' => 'Presidente Prudente', 'state' => 'SP', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'Ribeirão Preto', 'city' => 'Ribeirão Preto', 'state' => 'SP', 'source' => $cene],
        ['client' => 'CENEMED', 'name' => 'São Paulo', 'city' => 'São Paulo', 'state' => 'SP', 'source' => $cene],
    ];
}

/** Acha o cliente pelo nome (exato primeiro; depois começando com o nome). */
function polos_seed_find_client(string $name): ?int
{
    $stmt = db()->prepare("SELECT id FROM clients
        WHERE UPPER(TRIM(name)) = :n OR UPPER(TRIM(name)) LIKE :p
        ORDER BY (UPPER(TRIM(name)) = :n2) DESC, is_active DESC, id ASC LIMIT 1");
    $up = mb_strtoupper(trim($name));
    $stmt->execute(['n' => $up, 'p' => $up . '%', 'n2' => $up]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * Sugestões que ainda não estão cadastradas (cliente existe e o polo não).
 *
 * @return array{missing: array<int,array>, no_client: string[]}
 */
function polos_seed_pending(): array
{
    clients_ensure_schema();
    $missing = [];
    $noClient = [];
    $exists = db()->prepare('SELECT 1 FROM polos WHERE client_id = :c AND UPPER(TRIM(name)) = :n LIMIT 1');
    foreach (polos_seed_list() as $p) {
        $cid = polos_seed_find_client($p['client']);
        if ($cid === null) {
            $noClient[$p['client']] = $p['client'];
            continue;
        }
        $exists->execute(['c' => $cid, 'n' => mb_strtoupper($p['name'])]);
        if (!$exists->fetchColumn()) {
            $missing[] = $p + ['client_id' => $cid];
        }
    }
    return ['missing' => $missing, 'no_client' => array_values($noClient)];
}

/**
 * Cadastra as sugestões que faltam. Não altera nem apaga polos existentes.
 *
 * @return array{inserted:int, no_client:string[]}
 */
function polos_seed_apply(): array
{
    $pending = polos_seed_pending();
    $ins = db()->prepare('INSERT INTO polos (client_id, name, city, state, notes, is_active) VALUES (:c, :n, :city, :st, :notes, 1)');
    $count = 0;
    foreach ($pending['missing'] as $p) {
        $ins->execute([
            'c' => $p['client_id'],
            'n' => $p['name'],
            'city' => $p['city'],
            'st' => $p['state'],
            'notes' => 'Cadastro inicial. Fonte: ' . $p['source'],
        ]);
        $count++;
    }
    return ['inserted' => $count, 'no_client' => $pending['no_client']];
}

/**
 * Rótulo do polo para selects ("Rio Preto — GLOBAL").
 */
function polos_label(array $polo, bool $withClient = true): string
{
    $label = (string)($polo['name'] ?? '');
    if (!empty($polo['city']) && stripos($label, (string)$polo['city']) === false) {
        $label .= ' (' . $polo['city'] . (!empty($polo['state']) ? '/' . $polo['state'] : '') . ')';
    }
    if ($withClient && !empty($polo['client_name'])) {
        $label .= ' — ' . $polo['client_name'];
    }
    return $label;
}

/**
 * Valida um trio cliente/operadora/polo vindo de formulário e completa o que
 * faltar a partir da operadora (cliente e polo padrão). Não grava nada.
 *
 * @return array{client_id:?int, health_insurer_id:?int, polo_id:?int}
 */
function org_resolve(?int $clientId, ?int $insurerId, ?int $poloId): array
{
    $clientId = ($clientId !== null && $clientId > 0) ? $clientId : null;
    $insurerId = ($insurerId !== null && $insurerId > 0) ? $insurerId : null;
    $poloId = ($poloId !== null && $poloId > 0) ? $poloId : null;

    if ($insurerId !== null) {
        $op = operators_find($insurerId);
        if ($op === null) {
            $insurerId = null;
        } else {
            if ($clientId === null && $op['client_id'] !== null) {
                $clientId = (int)$op['client_id'];
            }
            if ($poloId === null && isset($op['polo_id']) && $op['polo_id'] !== null) {
                $poloId = (int)$op['polo_id'];
            }
        }
    }
    if ($poloId !== null) {
        $polo = polos_find($poloId);
        if ($polo === null) {
            $poloId = null;
        } elseif ($clientId === null && $polo['client_id'] !== null) {
            $clientId = (int)$polo['client_id'];
        }
    }
    return ['client_id' => $clientId, 'health_insurer_id' => $insurerId, 'polo_id' => $poloId];
}

/**
 * Copia cliente/operadora/polo da DEMANDA (card) para o atendimento quando o
 * atendimento ainda não tem esses dados. Nunca sobrescreve o que já existe.
 * Seguro para chamar dentro de transação (não faz DDL).
 */
function assignment_fill_org(PDO $db, int $assignmentId): void
{
    if ($assignmentId <= 0) {
        return;
    }
    try {
        $db->prepare("
            UPDATE patient_assignments pa
            INNER JOIN demands d ON d.id = pa.demand_id
            SET pa.health_insurer_id = COALESCE(pa.health_insurer_id, d.health_insurer_id),
                pa.client_id = COALESCE(pa.client_id, d.client_id),
                pa.polo_id = COALESCE(pa.polo_id, d.polo_id)
            WHERE pa.id = :id
        ")->execute(['id' => $assignmentId]);
        // Completa o que faltar pela operadora (cliente e polo padrão).
        $db->prepare("
            UPDATE patient_assignments pa
            INNER JOIN health_insurers hi ON hi.id = pa.health_insurer_id
            SET pa.client_id = COALESCE(pa.client_id, hi.client_id),
                pa.polo_id = COALESCE(pa.polo_id, hi.polo_id)
            WHERE pa.id = :id
        ")->execute(['id' => $assignmentId]);
    } catch (Throwable $e) {
        error_log('[ORG] assignment_fill_org #' . $assignmentId . ': ' . $e->getMessage());
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
