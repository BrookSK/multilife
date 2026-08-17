<?php
/**
 * Script de importação de planilhas CSV para o sistema MultiLife.
 * 
 * Este script:
 * 1. Lê todos os arquivos CSV da pasta imports/
 * 2. Cadastra especialidades que não existem
 * 3. Cadastra pacientes (tabela patients) só com nome
 * 4. Cadastra profissionais (tabela users com role 'profissional') só com nome
 * 5. Cria uma demand para cada importação
 * 6. Cria patient_assignments (monitoramento) vinculando paciente, profissional, especialidade, frequência e quantidade
 *
 * USO: php imports/import_spreadsheets.php
 * 
 * IMPORTANTE: Rode no servidor onde o banco MySQL está acessível.
 */

declare(strict_types=1);

// ============================================================
// CONFIGURAÇÃO DO BANCO DE DADOS
// ============================================================
// Usa o mesmo config do sistema ou valores padrão
$configFile = __DIR__ . '/../config/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    $dbHost = $config['db']['host'] ?? 'localhost';
    $dbPort = $config['db']['port'] ?? 3306;
    $dbName = $config['db']['name'] ?? 'multilife';
    $dbUser = $config['db']['user'] ?? 'multilife';
    $dbPass = $config['db']['pass'] ?? '';
} else {
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbPort = (int)(getenv('DB_PORT') ?: 3306);
    $dbName = getenv('DB_NAME') ?: 'multilife';
    $dbUser = getenv('DB_USER') ?: 'multilife';
    $dbPass = getenv('DB_PASS') ?: '';
}

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Erro ao conectar no banco: " . $e->getMessage() . "\n");
}

echo "=== IMPORTAÇÃO DE PLANILHAS MULTILIFE ===\n";
echo "Conectado ao banco: {$dbName}@{$dbHost}:{$dbPort}\n\n";

// ============================================================
// MAPEAMENTO DE ESPECIALIDADE POR NOME DO ARQUIVO
// ============================================================
$specialtyMap = [
    'FISIOTERAPIA' => 'Fisioterapia',
    'FONOTERAPIA' => 'Fonoaudiologia',
    'FONOAUDIOLOGIA' => 'Fonoaudiologia',
    'ENFERMAGEM' => 'Enfermagem',
    'MÉDICO' => 'Medico',
    'MEDICO' => 'Medico',
    'NUTRIÇÃO' => 'Nutrição',
    'NUTRICAO' => 'Nutrição',
    'TERAPIA OCUPACIONAL' => 'Terapia Ocupacional',
    'PSICOLOGIA' => 'Psicologia',
];

// ============================================================
// MAPEAMENTO DE EMPRESA (OPERADORA) POR NOME DO ARQUIVO
// ============================================================
$companyMap = [
    'GANEP LAR' => 'GANEP LAR',
    'LIFE CARE' => 'LIFE CARE',
    'VIP CARE' => 'VIP CARE',
    'DAY' => 'DAY HOME CARE',
    'DAY HOME CARE' => 'DAY HOME CARE',
    'ANERY' => 'ANERY',
    'APAS' => 'APAS',
    'AUSTA' => 'AUSTA',
];

// ============================================================
// FUNÇÕES AUXILIARES
// ============================================================

/**
 * Extrai a especialidade e empresa do nome do arquivo
 */
function parseFileName(string $filename): array
{
    global $specialtyMap, $companyMap;

    // Remove extensão e underscores extras
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace('_', '', $name);
    $name = trim($name);
    $nameUpper = mb_strtoupper($name);

    $specialty = null;
    $company = null;

    // Detecta especialidade
    foreach ($specialtyMap as $key => $value) {
        if (mb_strpos($nameUpper, mb_strtoupper($key)) !== false) {
            $specialty = $value;
            break;
        }
    }

    // Detecta empresa
    foreach ($companyMap as $key => $value) {
        if (mb_strpos($nameUpper, $key) !== false) {
            $company = $value;
            break;
        }
    }

    return [$specialty, $company];
}

/**
 * Normaliza frequência (PAD) para formato padrão
 */
function normalizeFrequency(string $pad): string
{
    $pad = trim($pad);
    $pad = mb_strtoupper($pad);

    // Normaliza variações comuns
    $pad = str_replace(['X/', 'X /'], ['x/', 'x/'], $pad);

    // Converte para minúsculo padronizado
    $map = [
        '1X/SEMANA' => '1x por semana',
        '2X/SEMANA' => '2x por semana',
        '3X/SEMANA' => '3x por semana',
        '4X/SEMANA' => '4x por semana',
        '5X/SEMANA' => '5x por semana',
        '6X/SEMANA' => '6x por semana',
        '7X/SEMANA' => '7x por semana',
        '1X/DIA' => '1x por dia',
        '1X/MÊS' => '1x por mês',
        '2X/MÊS' => '2x por mês',
        '3X/MÊS' => '3x por mês',
        '1X/MES' => '1x por mês',
        '2X/MES' => '2x por mês',
        'AVALIAÇÃO' => 'Avaliação',
        'AVALIACÃO' => 'Avaliação',
        'AVALIACAO' => 'Avaliação',
    ];

    // Remove espaços extras
    $padClean = preg_replace('/\s+/', '', $pad);
    foreach ($map as $key => $value) {
        $keyClean = preg_replace('/\s+/', '', $key);
        if ($padClean === $keyClean) {
            return $value;
        }
    }

    // Se não encontrou, retorna como está (lowercase)
    return mb_strtolower($pad);
}

/**
 * Garante que uma especialidade existe no banco. Retorna o ID.
 */
function ensureSpecialty(PDO $pdo, string $name): int
{
    static $cache = [];

    $key = mb_strtolower(trim($name));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("SELECT id FROM specialties WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $stmt->execute(['name' => trim($name)]);
    $row = $stmt->fetch();

    if ($row) {
        $cache[$key] = (int)$row['id'];
        return $cache[$key];
    }

    // Cadastrar nova especialidade
    $stmt = $pdo->prepare("INSERT INTO specialties (name, status) VALUES (:name, 'active')");
    $stmt->execute(['name' => trim($name)]);
    $id = (int)$pdo->lastInsertId();
    $cache[$key] = $id;
    echo "  [+] Especialidade criada: {$name} (ID: {$id})\n";
    return $id;
}

/**
 * Garante que um paciente existe no banco. Retorna o ID.
 * Busca por nome (case-insensitive). Se não existir, cria só com o nome.
 */
function ensurePatient(PDO $pdo, string $fullName): int
{
    static $cache = [];

    $fullName = trim($fullName);
    $key = mb_strtolower($fullName);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("SELECT id FROM patients WHERE LOWER(full_name) = LOWER(:name) AND deleted_at IS NULL LIMIT 1");
    $stmt->execute(['name' => $fullName]);
    $row = $stmt->fetch();

    if ($row) {
        $cache[$key] = (int)$row['id'];
        return $cache[$key];
    }

    // Cadastrar novo paciente (somente nome)
    $stmt = $pdo->prepare("INSERT INTO patients (full_name) VALUES (:name)");
    $stmt->execute(['name' => $fullName]);
    $id = (int)$pdo->lastInsertId();
    $cache[$key] = $id;
    echo "  [+] Paciente criado: {$fullName} (ID: {$id})\n";
    return $id;
}

/**
 * Garante que um profissional (user) existe no banco. Retorna o ID.
 * Busca por nome (case-insensitive). Se não existir, cria com nome e email fictício.
 * Atribui a role 'profissional'.
 */
function ensureProfessional(PDO $pdo, string $name, ?string $phone = null): int
{
    static $cache = [];

    $name = trim($name);
    $key = mb_strtolower($name);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch();

    if ($row) {
        $cache[$key] = (int)$row['id'];
        return $cache[$key];
    }

    // Gerar email fictício único baseado no nome
    $slug = preg_replace('/[^a-z0-9]+/', '.', mb_strtolower(removeAccents($name)));
    $slug = trim($slug, '.');
    $email = $slug . '@import.multilife.local';

    // Verificar se email já existe, se sim adicionar sufixo
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        $email = $slug . '.' . time() . rand(100, 999) . '@import.multilife.local';
    }

    // Senha aleatória (não será usada, apenas para cumprir constraint NOT NULL)
    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

    // Limpar telefone
    $phoneClean = null;
    if ($phone) {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) >= 10) {
            $phoneClean = $digits;
        }
    }

    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, status) VALUES (:name, :email, :phone, :hash, 'active')");
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'phone' => $phoneClean,
        'hash' => $hash,
    ]);
    $id = (int)$pdo->lastInsertId();
    $cache[$key] = $id;

    // Atribuir role 'profissional'
    $stmtRole = $pdo->prepare("SELECT id FROM roles WHERE slug = 'profissional' LIMIT 1");
    $stmtRole->execute();
    $role = $stmtRole->fetch();
    if ($role) {
        $stmtAssign = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)");
        $stmtAssign->execute(['uid' => $id, 'rid' => (int)$role['id']]);
    }

    echo "  [+] Profissional criado: {$name} (ID: {$id}, email: {$email})\n";
    return $id;
}

/**
 * Remove acentos de uma string
 */
function removeAccents(string $str): string
{
    $map = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
    ];
    return strtr($str, $map);
}

/**
 * Cria uma demand para a importação. Retorna o ID.
 */
function createDemand(PDO $pdo, string $title, string $specialty, ?string $city = null): int
{
    $stmt = $pdo->prepare("
        INSERT INTO demands (title, specialty, location_city, status, description)
        VALUES (:title, :specialty, :city, 'admitido', 'Importado via planilha')
    ");
    $stmt->execute([
        'title' => $title,
        'specialty' => $specialty,
        'city' => $city,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Cria um patient_assignment (monitoramento)
 */
function createAssignment(
    PDO $pdo,
    int $demandId,
    int $patientId,
    int $professionalUserId,
    int $assignedByUserId,
    string $specialty,
    string $frequency,
    int $sessionQuantity
): int {
    $stmt = $pdo->prepare("
        INSERT INTO patient_assignments (
            demand_id, patient_id, professional_remote_jid, professional_user_id,
            assigned_by_user_id, specialty, session_quantity, session_frequency,
            payment_value, status, confirmed_at
        ) VALUES (
            :demand_id, :patient_id, :jid, :professional_user_id,
            :assigned_by, :specialty, :quantity, :frequency,
            0.00, 'confirmed', NOW()
        )
    ");
    $stmt->execute([
        'demand_id' => $demandId,
        'patient_id' => $patientId,
        'jid' => 'import_' . $professionalUserId . '@import.local',
        'professional_user_id' => $professionalUserId,
        'assigned_by' => $assignedByUserId,
        'specialty' => $specialty,
        'quantity' => $sessionQuantity,
        'frequency' => $frequency,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Detecta as colunas do CSV baseado no cabeçalho
 */
function detectColumns(array $header): array
{
    $cols = [
        'status' => null,
        'patient' => null,
        'convenio' => null,
        'motivo' => null,
        'data' => null,
        'cidade' => null,
        'polo' => null,
        'profissional' => null,
        'telefone' => null,
        'pad' => null,
        'total' => null,
        'fp' => null,
        'days_start' => null, // Índice onde começam os dias 1-31
    ];

    foreach ($header as $i => $col) {
        $col = mb_strtoupper(trim($col));

        if (in_array($col, ['STATUS', 'STATUS '])) {
            $cols['status'] = $i;
        } elseif (in_array($col, ['PACIENTES', 'PACIENTE'])) {
            $cols['patient'] = $i;
        } elseif ($col === 'CONVÊNIO' || $col === 'CONVENIO') {
            $cols['convenio'] = $i;
        } elseif ($col === 'MOTIVO') {
            $cols['motivo'] = $i;
        } elseif ($col === 'DATA') {
            $cols['data'] = $i;
        } elseif (in_array($col, ['CIDADE', 'CIDADE '])) {
            $cols['cidade'] = $i;
        } elseif ($col === 'POLO') {
            $cols['polo'] = $i;
        } elseif (in_array($col, ['PROFISSIONAIS', 'PROFISSIONAIS '])) {
            $cols['profissional'] = $i;
        } elseif (in_array($col, ['TELEFONE', 'CONTATO'])) {
            $cols['telefone'] = $i;
        } elseif ($col === 'PAD') {
            $cols['pad'] = $i;
        } elseif (in_array($col, ['TOTAL', 'TOTAL '])) {
            $cols['total'] = $i;
        } elseif ($col === 'FP') {
            $cols['fp'] = $i;
        } elseif ($col === '1') {
            $cols['days_start'] = $i;
        }
    }

    return $cols;
}

// ============================================================
// DETERMINAR USUÁRIO "SISTEMA" PARA assigned_by_user_id
// ============================================================
$stmtAdmin = $pdo->prepare("
    SELECT u.id FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.slug = 'admin'
    ORDER BY u.id ASC
    LIMIT 1
");
$stmtAdmin->execute();
$adminRow = $stmtAdmin->fetch();
if (!$adminRow) {
    die("Erro: Nenhum usuário admin encontrado no sistema. Crie ao menos um admin antes de importar.\n");
}
$systemUserId = (int)$adminRow['id'];
echo "Usando usuário admin ID {$systemUserId} como responsável pela importação.\n\n";

// ============================================================
// PROCESSAR TODOS OS CSVs
// ============================================================
$csvDir = __DIR__;
$csvFiles = glob($csvDir . '/*.csv');

if (empty($csvFiles)) {
    die("Nenhum arquivo CSV encontrado em: {$csvDir}\n");
}

$stats = [
    'files' => 0,
    'specialties_created' => 0,
    'patients_created' => 0,
    'professionals_created' => 0,
    'assignments_created' => 0,
    'rows_skipped' => 0,
];

foreach ($csvFiles as $csvFile) {
    $filename = basename($csvFile);
    [$specialty, $company] = parseFileName($filename);

    if (!$specialty) {
        echo "[!] Não foi possível detectar especialidade do arquivo: {$filename}. Pulando.\n";
        continue;
    }

    echo "--- Processando: {$filename} ---\n";
    echo "    Especialidade: {$specialty} | Empresa: " . ($company ?? 'N/A') . "\n";

    // Garantir que a especialidade existe
    $specialtyId = ensureSpecialty($pdo, $specialty);

    // Ler CSV
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        echo "  [!] Erro ao abrir arquivo. Pulando.\n";
        continue;
    }

    // Ler cabeçalho
    $headerLine = fgets($handle);
    if (!$headerLine) {
        fclose($handle);
        continue;
    }

    // Detectar BOM UTF-8
    $headerLine = str_replace("\xEF\xBB\xBF", '', $headerLine);
    $header = str_getcsv($headerLine, ';');
    $cols = detectColumns($header);

    if ($cols['patient'] === null || $cols['profissional'] === null || $cols['pad'] === null) {
        echo "  [!] Colunas obrigatórias não encontradas (PACIENTE, PROFISSIONAL, PAD). Pulando.\n";
        fclose($handle);
        continue;
    }

    // Criar uma demand para este arquivo de importação
    $demandTitle = "Importação {$specialty} - " . ($company ?? 'Geral');
    $demandId = createDemand($pdo, $demandTitle, $specialty);
    echo "  [+] Demand criada: ID {$demandId}\n";

    $lineNum = 1;
    $fileAssignments = 0;

    while (($line = fgets($handle)) !== false) {
        $lineNum++;
        $line = trim($line);
        if ($line === '') continue;

        $row = str_getcsv($line, ';');

        // Extrair dados
        $patientName = isset($row[$cols['patient']]) ? trim($row[$cols['patient']]) : '';
        $professionalName = isset($row[$cols['profissional']]) ? trim($row[$cols['profissional']]) : '';
        $pad = isset($row[$cols['pad']]) ? trim($row[$cols['pad']]) : '';
        $phone = ($cols['telefone'] !== null && isset($row[$cols['telefone']])) ? trim($row[$cols['telefone']]) : null;
        $city = null;
        if ($cols['cidade'] !== null && isset($row[$cols['cidade']])) {
            $city = trim($row[$cols['cidade']]);
        } elseif ($cols['polo'] !== null && isset($row[$cols['polo']])) {
            $city = trim($row[$cols['polo']]);
        }

        // Pular linhas sem paciente ou sem profissional
        if ($patientName === '' || $professionalName === '') {
            $stats['rows_skipped']++;
            continue;
        }

        // Pular cabeçalhos de status vazios (linhas que só têm um rótulo de status como INTERNAÇÃO, ÓBITO, etc sem dados)
        if ($pad === '' && $professionalName === '') {
            $stats['rows_skipped']++;
            continue;
        }

        // Calcular total de sessões
        $totalSessions = 0;
        if ($cols['total'] !== null && isset($row[$cols['total']])) {
            $totalVal = trim($row[$cols['total']]);
            if (is_numeric($totalVal)) {
                $totalSessions = (int)$totalVal;
            }
        }

        // Se total é 0, tentar calcular pela soma dos dias
        if ($totalSessions === 0 && $cols['days_start'] !== null) {
            for ($d = 0; $d < 31; $d++) {
                $idx = $cols['days_start'] + $d;
                if (isset($row[$idx]) && is_numeric(trim($row[$idx]))) {
                    $totalSessions += (int)trim($row[$idx]);
                }
            }
        }

        // Se ainda é 0, usar 1 como padrão (pelo menos existe a atribuição)
        if ($totalSessions === 0) {
            $totalSessions = 1;
        }

        // Normalizar frequência
        $frequency = $pad !== '' ? normalizeFrequency($pad) : 'Não informado';

        // Cadastrar paciente
        $patientId = ensurePatient($pdo, $patientName);

        // Cadastrar profissional
        $professionalId = ensureProfessional($pdo, $professionalName, $phone);

        // Criar assignment
        $assignmentId = createAssignment(
            $pdo,
            $demandId,
            $patientId,
            $professionalId,
            $systemUserId,
            $specialty,
            $frequency,
            $totalSessions
        );

        $fileAssignments++;
        $stats['assignments_created']++;
    }

    fclose($handle);
    $stats['files']++;
    echo "  => {$fileAssignments} atribuições criadas para este arquivo.\n\n";
}

// ============================================================
// RESUMO FINAL
// ============================================================
echo "\n=== IMPORTAÇÃO CONCLUÍDA ===\n";
echo "Arquivos processados: {$stats['files']}\n";
echo "Atribuições (monitoramento) criadas: {$stats['assignments_created']}\n";
echo "Linhas ignoradas (vazias/incompletas): {$stats['rows_skipped']}\n";
echo "============================\n";
