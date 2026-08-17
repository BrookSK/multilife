<?php
/**
 * Gera SQL de migration a partir dos dados extraídos das planilhas.
 * 
 * Cria:
 * 1. health_insurers (operadoras: ANERY, APAS, AUSTA, DAY HOME CARE, GANEP LAR, LIFE CARE, VIP CARE)
 * 2. specialties (Fisioterapia, Médico, Nutrição, etc.)
 * 3. users + user_roles (profissionais com role 'profissional')
 * 4. patients (pacientes únicos)
 * 5. patient_professionals (vínculo paciente-profissional com especialidade)
 */

declare(strict_types=1);

$json = file_get_contents(__DIR__ . '/extracted_data.json');
$data = json_decode($json, true);

// =====================================================
// CONFIGURAÇÕES
// =====================================================
$defaultPasswordHash = password_hash('Multilife@2026', PASSWORD_BCRYPT);
$emailDomain = 'importacao.multilife.local';

// =====================================================
// FASE 1: Extrair dados únicos
// =====================================================

$operators = [];       // operadoras (pastas)
$specialties = [];     // especialidades (nome dos arquivos)
$professionals = [];   // profissional => ['name' => ..., 'phone' => ..., 'specialties' => [...]]
$patients = [];        // paciente => ['name' => ..., 'city' => ..., 'insurance' => ..., 'first_date' => ...]
$assignments = [];     // vínculo paciente-profissional-especialidade-operadora

/**
 * Converte serial number do Excel para data Y-m-d.
 * Excel usa 1/1/1900 como dia 1, com bug do leap year de 1900.
 */
function excelDateToYmd(float $serial): string
{
    if ($serial <= 0) return '';
    // Excel pensa que 1900 é ano bissexto, então dia 60 = 29/Feb/1900 (errado)
    // Para datas > 60, subtrair 1
    if ($serial > 60) {
        $serial -= 1;
    }
    // Dia 1 = 1/Jan/1900
    $unixDays = $serial - 1; // 0-based
    $timestamp = (int)(strtotime('1900-01-01') + ($unixDays * 86400));
    return date('Y-m-d', $timestamp);
}

/**
 * Normaliza nome (trim, title case).
 */
function normalizeName(string $name): string
{
    $name = trim($name);
    // Remover non-breaking spaces e múltiplos espaços
    $name = str_replace("\xc2\xa0", ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);
    $name = mb_strtolower($name, 'UTF-8');
    // Title case com exceções para preposições
    $words = explode(' ', $name);
    $result = [];
    $prepositions = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'com'];
    foreach ($words as $i => $word) {
        if ($i > 0 && in_array($word, $prepositions)) {
            $result[] = $word;
        } else {
            $result[] = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
        }
    }
    return implode(' ', $result);
}

/**
 * Normaliza nome de especialidade.
 */
function normalizeSpecialty(string $name): string
{
    $name = trim($name);
    $map = [
        'FISIOTERAPIA' => 'Fisioterapia',
        'MÉDICO' => 'Médico',
        'MEDICO' => 'Médico',
        'NUTRIÇÃO' => 'Nutrição',
        'NUTRICAO' => 'Nutrição',
        'TERAPIA OCUPACIONAL' => 'Terapia Ocupacional',
        'FONOAUDIOLOGIA' => 'Fonoaudiologia',
        'FONOTERAPIA' => 'Fonoaudiologia',
        'ENFERMAGEM' => 'Enfermagem',
        'ENFERMAGEM DAY' => 'Enfermagem',
        'PSICOLOGIA' => 'Psicologia',
    ];
    $upper = mb_strtoupper($name, 'UTF-8');
    return $map[$upper] ?? $name;
}

/**
 * Gera email a partir do nome do profissional.
 */
function generateEmail(string $name, string $domain): string
{
    $name = mb_strtolower($name, 'UTF-8');
    // Remove acentos
    $name = strtr($name, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
    // Apenas letras e espaços
    $name = preg_replace('/[^a-z\s]/', '', $name);
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        $email = $parts[0] . '.' . end($parts);
    } else {
        $email = $parts[0] ?? 'profissional';
    }
    return $email . '@' . $domain;
}

/**
 * Detecta os índices das colunas com base no header.
 */
function detectColumns(array $header): array
{
    $cols = [
        'status' => null,
        'patient' => null,
        'insurance' => null,  // CONVÊNIO ou POLO
        'motive' => null,
        'date' => null,
        'city' => null,
        'professional' => null,
        'phone' => null,
        'pad' => null,
    ];

    foreach ($header as $idx => $val) {
        $val = mb_strtoupper(trim($val), 'UTF-8');
        if (in_array($val, ['STATUS', ''])) {
            if ($cols['status'] === null && ($val === 'STATUS' || $idx === 0)) {
                $cols['status'] = $idx;
            }
        }
        if (in_array($val, ['PACIENTE', 'PACIENTES'])) {
            $cols['patient'] = $idx;
        }
        if (in_array($val, ['CONVÊNIO', 'CONVENIO'])) {
            $cols['insurance'] = $idx;
        }
        if (in_array($val, ['MOTIVO'])) {
            $cols['motive'] = $idx;
        }
        if (in_array($val, ['DATA'])) {
            $cols['date'] = $idx;
        }
        if (in_array($val, ['CIDADE', 'POLO'])) {
            $cols['city'] = $idx;
        }
        if (in_array($val, ['PROFISSIONAIS', 'PROFISSIONAIS '])) {
            $cols['professional'] = $idx;
        }
        if (in_array($val, ['CONTATO', 'TELEFONE'])) {
            $cols['phone'] = $idx;
        }
        if (in_array($val, ['PAD'])) {
            $cols['pad'] = $idx;
        }
    }

    // Fallback: STATUS is always col 0
    if ($cols['status'] === null) {
        $cols['status'] = 0;
    }

    return $cols;
}

// =====================================================
// FASE 2: Processar dados
// =====================================================

foreach ($data as $file) {
    $operator = trim($file['operator']);
    $specialty = normalizeSpecialty($file['specialty']);

    $operators[$operator] = $operator;
    $specialties[$specialty] = $specialty;

    foreach ($file['sheets'] as $sheet) {
        $sheetName = $sheet['sheet_name'];
        $rows = $sheet['rows'];

        if (count($rows) < 2) continue;

        // Detectar colunas pelo header
        $cols = detectColumns($rows[0]);

        // Processar linhas de dados (skip header)
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            // Extrair valores (null-safe para colunas não detectadas)
            $patientName = ($cols['patient'] !== null) ? trim($row[$cols['patient']] ?? '') : '';
            $professionalName = ($cols['professional'] !== null) ? trim($row[$cols['professional']] ?? '') : '';
            $phone = ($cols['phone'] !== null) ? trim($row[$cols['phone']] ?? '') : '';
            $pad = ($cols['pad'] !== null) ? trim($row[$cols['pad']] ?? '') : '';
            $city = ($cols['city'] !== null) ? trim($row[$cols['city']] ?? '') : '';
            $insurance = ($cols['insurance'] !== null) ? trim($row[$cols['insurance']] ?? '') : '';
            $motive = ($cols['motive'] !== null) ? trim($row[$cols['motive']] ?? '') : '';
            $dateRaw = ($cols['date'] !== null) ? trim($row[$cols['date']] ?? '') : '';
            $status = ($cols['status'] !== null) ? trim($row[$cols['status']] ?? '') : '';

            // Pular linhas sem paciente
            if ($patientName === '' || mb_strlen($patientName) < 3) continue;

            // Pular linhas que são categorias (INTERNAÇÃO, PENDENCIA, ÓBITO, ALTA, etc. sem dados)
            if ($professionalName === '' && $pad === '') continue;

            // Normalizar - remover non-breaking spaces e espaços extras
            $patientKey = mb_strtoupper(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', trim($patientName))), 'UTF-8');
            $professionalKey = mb_strtoupper(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', trim($professionalName))), 'UTF-8');

            // Converter data
            $dateFormatted = '';
            if ($dateRaw !== '' && is_numeric($dateRaw)) {
                $dateFormatted = excelDateToYmd((float)$dateRaw);
            }

            // Registrar paciente (guardar a primeira ocorrência com dados mais completos)
            if (!isset($patients[$patientKey])) {
                $patients[$patientKey] = [
                    'name' => normalizeName($patientName),
                    'city' => trim($city),
                    'insurance' => trim($insurance),
                    'first_date' => $dateFormatted,
                    'status' => $status,
                    'operator' => $operator,
                ];
            } else {
                // Atualizar cidade/convênio se estiver vazio
                if (empty($patients[$patientKey]['city']) && !empty($city)) {
                    $patients[$patientKey]['city'] = trim($city);
                }
                if (empty($patients[$patientKey]['insurance']) && !empty($insurance)) {
                    $patients[$patientKey]['insurance'] = trim($insurance);
                }
                if (empty($patients[$patientKey]['first_date']) && !empty($dateFormatted)) {
                    $patients[$patientKey]['first_date'] = $dateFormatted;
                }
            }

            // Registrar profissional
            if ($professionalName !== '' && mb_strlen($professionalName) >= 3) {
                if (!isset($professionals[$professionalKey])) {
                    $professionals[$professionalKey] = [
                        'name' => normalizeName($professionalName),
                        'phone' => $phone,
                        'specialties' => [$specialty],
                    ];
                } else {
                    // Atualizar telefone se vazio
                    if (empty($professionals[$professionalKey]['phone']) && !empty($phone)) {
                        $professionals[$professionalKey]['phone'] = $phone;
                    }
                    // Adicionar especialidade
                    if (!in_array($specialty, $professionals[$professionalKey]['specialties'])) {
                        $professionals[$professionalKey]['specialties'][] = $specialty;
                    }
                }

                // Registrar vínculo
                $assignmentKey = $patientKey . '|||' . $professionalKey . '|||' . $specialty . '|||' . $operator;
                if (!isset($assignments[$assignmentKey])) {
                    $assignments[$assignmentKey] = [
                        'patient_key' => $patientKey,
                        'professional_key' => $professionalKey,
                        'specialty' => $specialty,
                        'operator' => $operator,
                        'pad' => $pad,
                        'first_date' => $dateFormatted,
                        'city' => trim($city),
                        'motive' => $motive,
                        'status' => $status,
                    ];
                } else {
                    // Atualizar data se for anterior
                    if (!empty($dateFormatted) && (empty($assignments[$assignmentKey]['first_date']) || $dateFormatted < $assignments[$assignmentKey]['first_date'])) {
                        $assignments[$assignmentKey]['first_date'] = $dateFormatted;
                    }
                    // Atualizar PAD mais recente
                    if (!empty($pad)) {
                        $assignments[$assignmentKey]['pad'] = $pad;
                    }
                }
            }
        }
    }
}

// =====================================================
// FASE 3: Gerar SQL
// =====================================================

$sql = [];
$sql[] = "-- ============================================";
$sql[] = "-- MIGRATION: Importação de dados das planilhas";
$sql[] = "-- Gerado em: " . date('Y-m-d H:i:s');
$sql[] = "-- ============================================";
$sql[] = "";
$sql[] = "SET NAMES utf8mb4;";
$sql[] = "SET FOREIGN_KEY_CHECKS = 0;";
$sql[] = "";

// --- 1. Health Insurers (operadoras) ---
$sql[] = "-- ============================================";
$sql[] = "-- 1. OPERADORAS (health_insurers)";
$sql[] = "-- ============================================";
$sql[] = "";
foreach ($operators as $op) {
    $opEsc = addslashes($op);
    $sql[] = "INSERT INTO health_insurers (name, notes, is_active) ";
    $sql[] = "SELECT '{$opEsc}', 'Importado das planilhas', 1 ";
    $sql[] = "FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM health_insurers WHERE name = '{$opEsc}');";
    $sql[] = "";
}

// --- 2. Specialties ---
$sql[] = "-- ============================================";
$sql[] = "-- 2. ESPECIALIDADES (specialties)";
$sql[] = "-- ============================================";
$sql[] = "";
foreach ($specialties as $spec) {
    $specEsc = addslashes($spec);
    $sql[] = "INSERT INTO specialties (name, status) ";
    $sql[] = "SELECT '{$specEsc}', 'active' ";
    $sql[] = "FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM specialties WHERE name = '{$specEsc}');";
    $sql[] = "";
}

// --- 3. Profissionais (users + user_roles) ---
$sql[] = "-- ============================================";
$sql[] = "-- 3. PROFISSIONAIS (users + user_roles)";
$sql[] = "-- ============================================";
$sql[] = "";

// Usar variáveis para controlar IDs
$sql[] = "-- Garantir que a role 'profissional' existe";
$sql[] = "INSERT INTO roles (name, slug) ";
$sql[] = "SELECT 'Profissional', 'profissional' FROM DUAL ";
$sql[] = "WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'profissional');";
$sql[] = "";

// Para evitar duplicatas de email, vamos usar um contador
$emailCounts = [];
$profIdx = 0;
foreach ($professionals as $key => $prof) {
    $profIdx++;
    $nameEsc = addslashes($prof['name']);
    $email = generateEmail($prof['name'], $emailDomain);
    
    // Garantir unicidade de email
    if (isset($emailCounts[$email])) {
        $emailCounts[$email]++;
        $email = preg_replace('/@/', $emailCounts[$email] . '@', $email);
    } else {
        $emailCounts[$email] = 1;
    }
    $professionals[$key]['email'] = $email;
    
    $emailEsc = addslashes($email);
    $phoneEsc = addslashes($prof['phone']);
    $hashEsc = addslashes($defaultPasswordHash);

    $sql[] = "-- Profissional #{$profIdx}: {$prof['name']} ({$email})";
    $sql[] = "INSERT INTO users (name, email, password_hash, status) ";
    $sql[] = "SELECT '{$nameEsc}', '{$emailEsc}', '{$hashEsc}', 'active' ";
    $sql[] = "FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = '{$emailEsc}');";
    $sql[] = "";
    $sql[] = "INSERT INTO user_roles (user_id, role_id) ";
    $sql[] = "SELECT u.id, r.id FROM users u, roles r ";
    $sql[] = "WHERE u.email = '{$emailEsc}' AND r.slug = 'profissional' ";
    $sql[] = "AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id);";
    $sql[] = "";
}

// --- 4. Pacientes ---
$sql[] = "-- ============================================";
$sql[] = "-- 4. PACIENTES (patients)";
$sql[] = "-- ============================================";
$sql[] = "";

$patIdx = 0;
foreach ($patients as $key => $pat) {
    $patIdx++;
    $nameEsc = addslashes($pat['name']);
    $cityEsc = addslashes(trim($pat['city']));
    $insuranceEsc = addslashes(trim($pat['insurance']));
    
    // Definir admin_status baseado no status da planilha
    $adminStatus = 'ativo';
    $statusUpper = mb_strtoupper(trim($pat['status']), 'UTF-8');
    if (in_array($statusUpper, ['ÓBITO', 'OBITO'])) {
        $adminStatus = 'obito';
    } elseif (in_array($statusUpper, ['ALTA'])) {
        $adminStatus = 'alta';
    } elseif (in_array($statusUpper, ['PENDENCIA', 'PENDENCIAS', 'PENDÊNCIA'])) {
        $adminStatus = 'pendencia';
    }

    $sql[] = "-- Paciente #{$patIdx}: {$pat['name']}";
    $sql[] = "INSERT INTO patients (full_name, address_city, insurance_name, admin_status) ";
    $sql[] = "SELECT '{$nameEsc}', " . ($cityEsc ? "'{$cityEsc}'" : "NULL") . ", " . ($insuranceEsc ? "'{$insuranceEsc}'" : "NULL") . ", '{$adminStatus}' ";
    $sql[] = "FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE full_name = '{$nameEsc}' AND deleted_at IS NULL);";
    $sql[] = "";
}

// --- 5. Vínculos paciente-profissional ---
$sql[] = "-- ============================================";
$sql[] = "-- 5. VÍNCULOS PACIENTE-PROFISSIONAL (patient_professionals)";
$sql[] = "-- ============================================";
$sql[] = "";

$assignIdx = 0;
foreach ($assignments as $key => $assign) {
    $assignIdx++;
    $patientName = $patients[$assign['patient_key']]['name'] ?? '';
    $professionalEmail = $professionals[$assign['professional_key']]['email'] ?? '';
    $specialty = $assign['specialty'];
    
    if (empty($patientName) || empty($professionalEmail)) continue;
    
    $patientNameEsc = addslashes($patientName);
    $professionalEmailEsc = addslashes($professionalEmail);
    $specialtyEsc = addslashes($specialty);
    
    $sql[] = "INSERT INTO patient_professionals (patient_id, professional_user_id, specialty, is_active) ";
    $sql[] = "SELECT p.id, u.id, '{$specialtyEsc}', 1 ";
    $sql[] = "FROM patients p, users u ";
    $sql[] = "WHERE p.full_name = '{$patientNameEsc}' AND p.deleted_at IS NULL ";
    $sql[] = "AND u.email = '{$professionalEmailEsc}' ";
    $sql[] = "AND NOT EXISTS ( ";
    $sql[] = "  SELECT 1 FROM patient_professionals pp ";
    $sql[] = "  WHERE pp.patient_id = p.id AND pp.professional_user_id = u.id ";
    $sql[] = ");";
    $sql[] = "";
}

// --- 6. Vincular pacientes à operadora (health_insurer_id) ---
$sql[] = "-- ============================================";
$sql[] = "-- 6. VINCULAR PACIENTES À OPERADORA (health_insurer_id)";
$sql[] = "-- ============================================";
$sql[] = "";

// Agrupar pacientes por operadora
$patientsByOperator = [];
foreach ($patients as $key => $pat) {
    $op = $pat['operator'];
    $patientsByOperator[$op][] = $pat['name'];
}

foreach ($patientsByOperator as $op => $patNames) {
    $opEsc = addslashes($op);
    foreach ($patNames as $patName) {
        $patNameEsc = addslashes($patName);
        $sql[] = "UPDATE patients SET health_insurer_id = (SELECT id FROM health_insurers WHERE name = '{$opEsc}' LIMIT 1) ";
        $sql[] = "WHERE full_name = '{$patNameEsc}' AND deleted_at IS NULL AND health_insurer_id IS NULL;";
    }
    $sql[] = "";
}

// --- Finalização ---
$sql[] = "";
$sql[] = "SET FOREIGN_KEY_CHECKS = 1;";
$sql[] = "";
$sql[] = "-- ============================================";
$sql[] = "-- RESUMO DA IMPORTAÇÃO";
$sql[] = "-- ============================================";
$sql[] = "-- Operadoras: " . count($operators);
$sql[] = "-- Especialidades: " . count($specialties);
$sql[] = "-- Profissionais: " . count($professionals);
$sql[] = "-- Pacientes: " . count($patients);
$sql[] = "-- Vínculos paciente-profissional: " . $assignIdx;
$sql[] = "-- ============================================";

// Salvar em UTF-8 (sem BOM, compatível com MySQL)
$outputPath = __DIR__ . '/../migrations/2026-08-14_0001_import_planilhas.sql';
$content = implode("\n", $sql);
file_put_contents($outputPath, $content);

echo "SQL gerado com sucesso!\n";
echo "Arquivo: migrations/2026-08-14_0001_import_planilhas.sql\n";
echo "\n";
echo "RESUMO:\n";
echo "  Operadoras: " . count($operators) . " (" . implode(', ', $operators) . ")\n";
echo "  Especialidades: " . count($specialties) . " (" . implode(', ', $specialties) . ")\n";
echo "  Profissionais: " . count($professionals) . "\n";
echo "  Pacientes: " . count($patients) . "\n";
echo "  Vínculos paciente-profissional: " . $assignIdx . "\n";

// Listar profissionais com telefone
echo "\n--- PROFISSIONAIS ---\n";
$profList = array_values($professionals);
usort($profList, fn($a, $b) => strcmp($a['name'], $b['name']));
foreach ($profList as $prof) {
    echo "  {$prof['name']} | {$prof['phone']} | " . implode(', ', $prof['specialties']) . " | {$prof['email']}\n";
}
