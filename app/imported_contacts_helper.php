<?php

declare(strict_types=1);

/**
 * Contatos importados da agenda (profissionais, operadoras/clientes, pacientes).
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
            category ENUM('cliente','profissional','operadora','paciente','outros') NOT NULL DEFAULT 'outros',
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
        // Tabelas antigas tinham só cliente/profissional/outros.
        db()->exec("ALTER TABLE imported_contacts MODIFY category ENUM('cliente','profissional','operadora','paciente','outros') NOT NULL DEFAULT 'outros'");
        db()->exec("UPDATE imported_contacts SET category = 'operadora' WHERE category = 'cliente'");
    } catch (Throwable $e) {
        error_log('[IMPORTED_CONTACTS] ensure schema: ' . $e->getMessage());
    }
}

function imported_contacts_categories(): array
{
    return [
        'profissional' => 'Profissionais',
        'operadora' => 'Operadoras/Clientes',
        'paciente' => 'Pacientes',
        'outros' => 'Outros',
    ];
}

/**
 * Normaliza um telefone para dígitos com DDI 55.
 * Retorna vazio quando o número não é um celular/fixo brasileiro utilizável.
 */
function imported_contacts_normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }
    $digits = ltrim($digits, '0');
    $len = strlen($digits);
    if ($len === 10 || $len === 11) {
        $digits = '55' . $digits;
    }
    if (strlen($digits) < 12 || strlen($digits) > 13 || substr($digits, 0, 2) !== '55') {
        return '';
    }
    return $digits;
}

function imported_contacts_format_phone(string $digits): string
{
    $d = preg_replace('/\D+/', '', $digits);
    if (strlen($d) === 13 && substr($d, 0, 2) === '55') {
        return sprintf('+%s (%s) %s-%s', substr($d, 0, 2), substr($d, 2, 2), substr($d, 4, 5), substr($d, 9, 4));
    }
    if (strlen($d) === 12 && substr($d, 0, 2) === '55') {
        return sprintf('+%s (%s) %s-%s', substr($d, 0, 2), substr($d, 2, 2), substr($d, 4, 4), substr($d, 8, 4));
    }
    return $digits;
}

function imported_contacts_whatsapp_jid(string $phone): string
{
    $digits = imported_contacts_normalize_phone($phone);
    if ($digits === '') {
        $digits = preg_replace('/\D+/', '', $phone);
    }
    return $digits . '@s.whatsapp.net';
}

/**
 * @param array<int, string> $raws
 * @return array{0:string,1:string} [normalizado, como veio]
 */
function imported_contacts_first_phone(array $raws): array
{
    foreach ($raws as $raw) {
        $parts = preg_split('/\s*:::\s*/', (string)$raw) ?: [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $norm = imported_contacts_normalize_phone($part);
            if ($norm !== '') {
                return [$norm, $part];
            }
        }
    }
    return ['', ''];
}

function imported_contacts_clean_name(string $name): string
{
    $name = preg_replace('/[\x{FE0F}\x{200D}]/u', '', $name) ?? $name;
    $name = preg_replace('/[❌×*~]+/u', '', $name) ?? $name;
    $prev = null;
    while ($prev !== $name && preg_match('/^\s*\([^)]{0,120}\)\s*/u', $name)) {
        $prev = $name;
        $name = preg_replace('/^\s*\([^)]{0,120}\)\s*/u', '', $name) ?? $name;
    }
    $name = preg_replace('/^\s*(cliente|clientes|paciente|pacientes|profissional|profissionais|prof|operadora|outros?)\s*[-:–]\s*/iu', '', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    return trim($name, " \t\n\r\0\x0B/-.");
}

function imported_contacts_inactive_note(string $raw): ?string
{
    if (preg_match('/não\s+atende|nao\s+atende|n[aã]o\s+passar|mudou\s+de\s+n[uú]mero|❌/u', $raw)) {
        return 'nao_atende';
    }
    return null;
}

/**
 * Classifica o contato em profissional, operadora (operadoras/clientes), paciente ou outros.
 * A especialidade no nome tem prioridade: "Fisio ... Pac Joaquim" é o profissional.
 */
function imported_contacts_detect_category(string $marker, string $name = ''): string
{
    $hay = mb_strtolower($marker . ' ' . $name);
    $hay = preg_replace('/[^\p{L}\p{N}\s\.\/\-]/u', ' ', $hay) ?? $hay;
    $hay = preg_replace('/\s+/u', ' ', trim($hay)) ?? trim($hay);

    $prof = '/(?:fisioterap|fisio|fonoaudi|fono|\bfno\b|\bfga\b|\bfg\b|\bfng\b|\bfgo\b|nutric|\bnutri\b|\bnt\b|enfermeir|\benf\b|psic[oó]|terapeuta|\bto\b|\bt\.?\s*o\b|\bmedic[oa]\b|\bmed\b|\bdra?\b|\bfta?\b|\bft\b|dentista|cuidador|psicopedag|\bprofissionais?\b|\bprof\b)/u';
    if (preg_match($prof, $hay)) {
        return 'profissional';
    }

    $pac = '/(?:\bpac\b|pacientes?|\bespos[oa]\b|\bm[aã]e\b|\bpai\b|\bfamiliar\b|respons[aá]vel|\bfilh[oa]\s*\/|\bfilh[oa]\s+(?:de|do|da)\b)/u';
    if (preg_match($pac, $hay)) {
        return 'paciente';
    }

    $op = '/(?:\badm\b|\bpolo\b|\bunimed\b|\bcenemed\b|\bcene\b|\baffinity\b|\bcassi\b|\bbradesco\b|\bhapvida\b|\bgeap\b|operadoras?|conv[eê]nio|\bclientes?\b|\bcnu\b|notre\s?dame|interm[eé]dica|prevent\s+senior|\bomint\b|porto\s+seguro|sulam[eé]rica|golden\s+cross|\bpetrobras\b|\bseguros\b|\bamil\b|\bglobal\b|\bsamp\b|\bsalvus\b|\bmastermed\b|\boutcare\b|cl[ií]nica|ambulat[oó]rio|home\s*care|multilife)/u';
    if (preg_match($op, $hay)) {
        return 'operadora';
    }

    return 'outros';
}

/**
 * @return array{imported:int,skipped:int,by:array<string,int>}
 */
function imported_contacts_store_rows(array $rows, string $source, ?int $userId): array
{
    imported_contacts_ensure_schema();
    $db = db();
    $ins = $db->prepare(
        "INSERT INTO imported_contacts (name, phone, phone_raw, category, source, notes, created_by_user_id)
         VALUES (:name, :phone, :phone_raw, :cat, :src, :notes, :uid)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            phone_raw = VALUES(phone_raw),
            notes = VALUES(notes),
            source = VALUES(source),
            updated_at = CURRENT_TIMESTAMP"
    );

    $imported = 0;
    $skipped = 0;
    $by = ['profissional' => 0, 'operadora' => 0, 'paciente' => 0, 'outros' => 0];
    $source = mb_substr($source, 0, 120);
    $pending = 0;
    $db->beginTransaction();

    foreach ($rows as $row) {
        $name = mb_substr((string)($row['name'] ?? ''), 0, 200);
        $phone = (string)($row['phone'] ?? '');
        $category = (string)($row['category'] ?? 'outros');
        if (!isset($by[$category])) {
            $category = 'outros';
        }
        if ($name === '' || $phone === '') {
            $skipped++;
            continue;
        }
        try {
            $ins->execute([
                'name' => $name,
                'phone' => $phone,
                'phone_raw' => isset($row['phone_raw']) && $row['phone_raw'] !== '' ? mb_substr((string)$row['phone_raw'], 0, 64) : null,
                'cat' => $category,
                'src' => $source,
                'notes' => $row['notes'] ?? null,
                'uid' => $userId,
            ]);
            $imported++;
            $by[$category]++;
            $pending++;
            if ($pending >= 400) {
                $db->commit();
                $db->beginTransaction();
                $pending = 0;
            }
        } catch (Throwable $e) {
            $skipped++;
            error_log('[IMPORTED_CONTACTS] ' . $e->getMessage());
            if (!$db->inTransaction()) {
                $db->beginTransaction();
            }
        }
    }

    if ($db->inTransaction()) {
        $db->commit();
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'by' => $by];
}

/**
 * Lê um CSV (exportação do Google Contatos ou planilha simples) e grava os contatos.
 *
 * @return array{imported:int,skipped:int,by:array<string,int>}
 */
function imported_contacts_import_file(string $path, ?int $userId, string $defaultCategory = 'outros'): array
{
    $raw = (string)file_get_contents($path);
    if ($raw === '') {
        return ['imported' => 0, 'skipped' => 0, 'by' => []];
    }
    $raw = str_replace("\xEF\xBB\xBF", '', $raw);
    $enc = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($enc && $enc !== 'UTF-8') {
        $raw = (string)mb_convert_encoding($raw, 'UTF-8', $enc);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ic');
    if ($tmp === false) {
        return ['imported' => 0, 'skipped' => 0, 'by' => []];
    }
    file_put_contents($tmp, $raw);
    $fh = fopen($tmp, 'r');
    if ($fh === false) {
        @unlink($tmp);
        return ['imported' => 0, 'skipped' => 0, 'by' => []];
    }

    $header = fgetcsv($fh);
    if (!is_array($header)) {
        fclose($fh);
        @unlink($tmp);
        return ['imported' => 0, 'skipped' => 0, 'by' => []];
    }
    $headerNorm = array_map(static fn($c) => mb_strtolower(trim((string)$c)), $header);
    $isGoogle = in_array('phone 1 - value', $headerNorm, true);

    $rows = [];
    $skipped = 0;
    if ($isGoogle) {
        $idx = array_flip($headerNorm);
        while (($cells = fgetcsv($fh)) !== false) {
            $get = static function (string $col) use ($idx, $cells): string {
                if (!isset($idx[$col])) {
                    return '';
                }
                return trim((string)($cells[$idx[$col]] ?? ''));
            };
            $nameRaw = trim($get('first name') . ' ' . $get('middle name') . ' ' . $get('last name'));
            $nameRaw = preg_replace('/\s+/u', ' ', $nameRaw) ?? $nameRaw;
            if ($nameRaw === '') {
                $nameRaw = $get('nickname') !== '' ? $get('nickname') : $get('organization name');
            }
            $built = imported_contacts_row_from_text(
                $nameRaw,
                [$get('phone 1 - value'), $get('phone 2 - value'), $get('phone 3 - value')],
                $get('organization name'),
                $defaultCategory
            );
            if ($built === null) {
                $skipped++;
                continue;
            }
            $rows[] = $built;
        }
    } else {
        fclose($fh);
        @unlink($tmp);
        return imported_contacts_import_simple_csv($raw, basename($path), $userId, $defaultCategory);
    }
    fclose($fh);
    @unlink($tmp);

    $result = imported_contacts_store_rows($rows, basename($path), $userId);
    $result['skipped'] += $skipped;
    return $result;
}

/**
 * @param array<int, string> $phones
 * @return array{name:string,phone:string,phone_raw:string,category:string,notes:?string}|null
 */
function imported_contacts_row_from_text(string $nameRaw, array $phones, string $extraMarker, string $defaultCategory): ?array
{
    [$phone, $phoneRaw] = imported_contacts_first_phone($phones);
    if ($phone === '') {
        return null;
    }
    $name = imported_contacts_clean_name($nameRaw);
    if ($name === '' || mb_strlen($name) < 2) {
        return null;
    }
    $category = imported_contacts_detect_category($extraMarker, $nameRaw);
    if ($category === 'outros' && $extraMarker === '' && in_array($defaultCategory, ['profissional', 'operadora', 'paciente', 'outros'], true) && $defaultCategory !== 'outros') {
        // Só usa o padrão quando o nome não deu nenhuma pista.
        if (!preg_match('/\p{L}/u', $nameRaw)) {
            $category = $defaultCategory;
        }
    }
    return [
        'name' => $name,
        'phone' => $phone,
        'phone_raw' => $phoneRaw,
        'category' => $category,
        'notes' => imported_contacts_inactive_note($nameRaw),
    ];
}

/**
 * CSV simples (nome, telefone, categoria).
 *
 * @return array{imported:int,skipped:int,by:array<string,int>}
 */
function imported_contacts_import_simple_csv(string $raw, string $source, ?int $userId, string $defaultCategory): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $lines = array_values(array_filter($lines, static fn($l) => trim((string)$l) !== ''));
    if (count($lines) === 0) {
        return ['imported' => 0, 'skipped' => 0, 'by' => []];
    }

    $delimiter = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
    $header = str_getcsv($lines[0], $delimiter);
    $idxName = 0;
    $idxPhone = 1;
    $idxCategory = -1;
    $start = 0;
    $headerJoined = mb_strtolower(implode(' ', $header));
    $hasHeader = (bool)preg_match('/nome|name|telefone|phone|categoria/u', $headerJoined);
    if ($hasHeader) {
        $start = 1;
        foreach ($header as $i => $colRaw) {
            $col = mb_strtolower(trim((string)$colRaw));
            if (preg_match('/\b(nome|name|contato)\b/u', $col)) {
                $idxName = $i;
            }
            if (preg_match('/\b(telefone|celular|phone|whatsapp|fone|numero|número)\b/u', $col)) {
                $idxPhone = $i;
            }
            if (preg_match('/\b(categoria|tipo|category|grupo|classe)\b/u', $col)) {
                $idxCategory = $i;
            }
        }
    }

    $rows = [];
    $skipped = 0;
    for ($r = $start; $r < count($lines); $r++) {
        $cells = str_getcsv($lines[$r], $delimiter);
        $nameRaw = trim((string)($cells[$idxName] ?? ''));
        $phoneRaw = trim((string)($cells[$idxPhone] ?? ''));
        $catMarker = $idxCategory >= 0 ? trim((string)($cells[$idxCategory] ?? '')) : '';
        if ($nameRaw === '') {
            $skipped++;
            continue;
        }
        $built = imported_contacts_row_from_text($nameRaw, [$phoneRaw], $catMarker, $defaultCategory);
        if ($built === null) {
            $skipped++;
            continue;
        }
        if ($catMarker !== '') {
            $explicit = imported_contacts_detect_category($catMarker, '');
            if ($explicit !== 'outros') {
                $built['category'] = $explicit;
            }
        } elseif ($built['category'] === 'outros' && in_array($defaultCategory, ['profissional', 'operadora', 'paciente'], true)) {
            $built['category'] = $defaultCategory;
        }
        $rows[] = $built;
    }

    $result = imported_contacts_store_rows($rows, $source, $userId);
    $result['skipped'] += $skipped;
    return $result;
}

/**
 * Importa docs/contatos.csv uma vez, quando a agenda ainda não foi carregada.
 *
 * @return array{imported:int,skipped:int,ran:bool}
 */
function imported_contacts_seed_bundled(bool $force = false): array
{
    imported_contacts_ensure_schema();
    $path = dirname(__DIR__) . '/docs/contatos.csv';
    if (!is_file($path)) {
        return ['imported' => 0, 'skipped' => 0, 'ran' => false];
    }

    @set_time_limit(300);
    try {
        db()->query("SELECT GET_LOCK('imported_contacts_seed', 30)");
    } catch (Throwable $e) {
        // segue sem lock
    }

    if (!$force) {
        try {
            $existing = (int)(db()->query("SELECT COUNT(*) FROM imported_contacts WHERE source = 'contatos.csv'")->fetchColumn() ?: 0);
            if ($existing > 0) {
                try {
                    db()->query("SELECT RELEASE_LOCK('imported_contacts_seed')");
                } catch (Throwable $e) {
                }
                return ['imported' => 0, 'skipped' => 0, 'ran' => false];
            }
        } catch (Throwable $e) {
            return ['imported' => 0, 'skipped' => 0, 'ran' => false];
        }
    }

    $result = imported_contacts_import_file($path, function_exists('auth_user_id') ? auth_user_id() : null, 'outros');
    try {
        db()->query("SELECT RELEASE_LOCK('imported_contacts_seed')");
    } catch (Throwable $e) {
    }
    $result['ran'] = true;
    return $result;
}

/**
 * @return array<string, int>
 */
function imported_contacts_counts(): array
{
    imported_contacts_ensure_schema();
    $counts = ['profissional' => 0, 'operadora' => 0, 'paciente' => 0, 'outros' => 0];
    try {
        foreach (db()->query("SELECT category, COUNT(*) AS c FROM imported_contacts GROUP BY category")->fetchAll(PDO::FETCH_ASSOC) as $cr) {
            $cat = (string)$cr['category'];
            if ($cat === 'cliente') {
                $cat = 'operadora';
            }
            if (!isset($counts[$cat])) {
                continue;
            }
            $counts[$cat] += (int)$cr['c'];
        }
    } catch (Throwable $e) {
    }
    return $counts;
}
