<?php
/**
 * Gera SQL de migration COMPLETA a partir dos dados extraídos das planilhas.
 * 
 * Fluxo completo:
 * 1. health_insurers (operadoras)
 * 2. specialties (especialidades)
 * 3. users + user_roles (profissionais com role 'profissional')
 * 4. patients (pacientes únicos)
 * 5. patient_professionals (vínculo paciente-profissional)
 * 6. demands (uma demanda por vínculo paciente-profissional-especialidade-operadora)
 * 7. patient_assignments (atribuição com status admitted, frequência, admitted_at)
 * 8. billing_document_requirements (uma por sessão realizada em cada dia/mês)
 */

declare(strict_types=1);

$json = file_get_contents(__DIR__ . '/extracted_data.json');
$data = json_decode($json, true);

// =====================================================
// CONFIGURAÇÕES
// =====================================================
$defaultPasswordHash = password_hash('Multilife@2026', PASSWORD_BCRYPT);
$emailDomain = 'importacao.multilife.local';
$adminUserId = 1; // assigned_by_user_id

// =====================================================
// FUNÇÕES AUXILIARES
// =====================================================

function excelDateToYmd(float $serial): string
{
    if ($serial <= 0) return '';
    if ($serial > 60) $serial -= 1;
    $unixDays = $serial - 1;
    $timestamp = (int)(strtotime('1900-01-01') + ($unixDays * 86400));
    return date('Y-m-d', $timestamp);
}

function normalizeName(string $name): string
{
    $name = trim($name);
    $name = str_replace("\xc2\xa0", ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);
    $name = mb_strtolower($name, 'UTF-8');
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

function generateEmail(string $name, string $domain): string
{
    $name = mb_strtolower($name, 'UTF-8');
    $name = strtr($name, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
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
 * Converte telefone brasileiro para formato WhatsApp JID.
 * Ex: "11 98348-6581" -> "5511983486581@s.whatsapp.net"
 */
function phoneToJid(string $phone): string
{
    // Remover tudo que não é dígito
    $digits = preg_replace('/\D/', '', $phone);
    // Se começa com 0, remover
    $digits = ltrim($digits, '0');
    // Se não começa com 55, adicionar
    if (substr($digits, 0, 2) !== '55') {
        $digits = '55' . $digits;
    }
    return $digits . '@s.whatsapp.net';
}

/**
 * Converte nome da aba (ex: "MAIO25", "JANEIRO26") para ano/mês numérico.
 */
function sheetNameToYearMonth(string $name): ?array
{
    $name = mb_strtoupper(trim($name), 'UTF-8');
    $months = [
        'JANEIRO' => 1, 'FEVEREIRO' => 2, 'MARÇO' => 3, 'MARCO' => 3,
        'ABRIL' => 4, 'MAIO' => 5, 'JUNHO' => 6,
        'JULHO' => 7, 'AGOSTO' => 8, 'SETEMBRO' => 9,
        'OUTUBRO' => 10, 'NOVEMBRO' => 11, 'DEZEMBRO' => 12,
    ];
    
    foreach ($months as $monthName => $monthNum) {
        if (strpos($name, $monthName) === 0) {
            $yearPart = trim(str_replace($monthName, '', $name));
            if (strlen($yearPart) === 2) {
                $year = 2000 + (int)$yearPart;
            } elseif (strlen($yearPart) === 4) {
                $year = (int)$yearPart;
            } else {
                return null;
            }
            return ['year' => $year, 'month' => $monthNum];
        }
    }
    return null;
}

/**
 * Detecta os índices das colunas com base no header.
 * Retorna índices das colunas de metadados E o índice onde começam os dias.
 */
function detectColumns(array $header): array
{
    $cols = [
        'status' => 0,
        'patient' => null,
        'insurance' => null,
        'motive' => null,
        'date' => null,
        'city' => null,
        'professional' => null,
        'phone' => null,
        'pad' => null,
        'day_start' => null, // onde começam colunas de dias (1, 2, 3...)
    ];

    foreach ($header as $idx => $val) {
        $val = mb_strtoupper(trim($val), 'UTF-8');
        if (in_array($val, ['PACIENTE', 'PACIENTES'])) {
            $cols['patient'] = $idx;
        }
        if (in_array($val, ['CONVÊNIO', 'CONVENIO'])) {
            $cols['insurance'] = $idx;
        }
        if ($val === 'MOTIVO') {
            $cols['motive'] = $idx;
        }
        if ($val === 'DATA') {
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
        if ($val === 'PAD') {
            $cols['pad'] = $idx;
        }
        // Detectar coluna do dia 1 (pode ser "1", "1.0")
        if ($cols['day_start'] === null && in_array($val, ['1', '1.0'])) {
            $cols['day_start'] = $idx;
        }
    }

    return $cols;
}

/**
 * Extrai datas de sessões realizadas a partir das colunas de dias.
 * Retorna array de datas (Y-m-d) em que houve sessão.
 */
function extractSessionDates(array $row, int $dayStartIdx, int $year, int $month): array
{
    $dates = [];
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $colIdx = $dayStartIdx + ($day - 1);
        $val = trim($row[$colIdx] ?? '');
        // Sessão realizada se célula tem "1.0", "1", "2.0", "2" etc. (valores > 0)
        if ($val !== '' && is_numeric($val) && (float)$val > 0) {
            $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    
    return $dates;
}

// =====================================================
// FASE 1: Processar dados e coletar informações
// =====================================================

$operators = [];
$specialties = [];
$professionals = [];    // key => [name, phone, email, specialties]
$patients = [];         // key => [name, city, insurance, first_date, status, operator]
$emailCounts = [];

// Coletar todos os vínculos com sessões detalhadas por mês
// Estrutura: chave do vínculo => [meta + sessions_by_month]
$fullAssignments = []; // key => [patient_key, professional_key, specialty, operator, pad, first_date, city, sessions => [[date, ...], ...]]

foreach ($data as $file) {
    $operator = trim($file['operator']);
    $specialty = normalizeSpecialty($file['specialty']);

    $operators[$operator] = $operator;
    $specialties[$specialty] = $specialty;

    foreach ($file['sheets'] as $sheet) {
        $sheetName = $sheet['sheet_name'];
        $rows = $sheet['rows'];

        if (count($rows) < 2) continue;

        // Detectar mês/ano da aba
        $ym = sheetNameToYearMonth($sheetName);
        if (!$ym) continue;
        $year = $ym['year'];
        $month = $ym['month'];

        // Detectar colunas
        $cols = detectColumns($rows[0]);
        
        if ($cols['patient'] === null || $cols['professional'] === null) continue;

        // Processar linhas
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            $patientName = ($cols['patient'] !== null) ? trim($row[$cols['patient']] ?? '') : '';
            $professionalName = ($cols['professional'] !== null) ? trim($row[$cols['professional']] ?? '') : '';
            $phone = ($cols['phone'] !== null) ? trim($row[$cols['phone']] ?? '') : '';
            $pad = ($cols['pad'] !== null) ? trim($row[$cols['pad']] ?? '') : '';
            $city = ($cols['city'] !== null) ? trim($row[$cols['city']] ?? '') : '';
            $insurance = ($cols['insurance'] !== null) ? trim($row[$cols['insurance']] ?? '') : '';
            $motive = ($cols['motive'] !== null) ? trim($row[$cols['motive']] ?? '') : '';
            $dateRaw = ($cols['date'] !== null) ? trim($row[$cols['date']] ?? '') : '';
            $status = ($cols['status'] !== null) ? trim($row[$cols['status']] ?? '') : '';

            // Pular linhas sem paciente ou profissional
            if ($patientName === '' || mb_strlen($patientName) < 3) continue;
            if ($professionalName === '' || mb_strlen($professionalName) < 3) continue;
            if ($pad === '') continue;

            // Normalizar chaves (remover nbsp, espaços extras)
            $patientKey = mb_strtoupper(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', trim($patientName))), 'UTF-8');
            $professionalKey = mb_strtoupper(preg_replace('/\s+/', ' ', str_replace("\xc2\xa0", ' ', trim($professionalName))), 'UTF-8');

            // Converter data
            $dateFormatted = '';
            if ($dateRaw !== '' && is_numeric($dateRaw)) {
                $dateFormatted = excelDateToYmd((float)$dateRaw);
            }

            // Registrar paciente
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
            if (!isset($professionals[$professionalKey])) {
                $professionals[$professionalKey] = [
                    'name' => normalizeName($professionalName),
                    'phone' => $phone,
                    'specialties' => [$specialty],
                ];
            } else {
                if (empty($professionals[$professionalKey]['phone']) && !empty($phone)) {
                    $professionals[$professionalKey]['phone'] = $phone;
                }
                if (!in_array($specialty, $professionals[$professionalKey]['specialties'])) {
                    $professionals[$professionalKey]['specialties'][] = $specialty;
                }
            }

            // Extrair sessões realizadas neste mês
            $sessionDates = [];
            if ($cols['day_start'] !== null) {
                $sessionDates = extractSessionDates($row, $cols['day_start'], $year, $month);
            }

            // Registrar vínculo com sessões
            $assignmentKey = $patientKey . '|||' . $professionalKey . '|||' . $specialty . '|||' . $operator;
            if (!isset($fullAssignments[$assignmentKey])) {
                $fullAssignments[$assignmentKey] = [
                    'patient_key' => $patientKey,
                    'professional_key' => $professionalKey,
                    'specialty' => $specialty,
                    'operator' => $operator,
                    'pad' => $pad,
                    'first_date' => $dateFormatted,
                    'city' => trim($city),
                    'sessions' => $sessionDates,
                ];
            } else {
                // Acumular sessões
                $fullAssignments[$assignmentKey]['sessions'] = array_merge(
                    $fullAssignments[$assignmentKey]['sessions'],
                    $sessionDates
                );
                // Atualizar data mais antiga
                if (!empty($dateFormatted) && (empty($fullAssignments[$assignmentKey]['first_date']) || $dateFormatted < $fullAssignments[$assignmentKey]['first_date'])) {
                    $fullAssignments[$assignmentKey]['first_date'] = $dateFormatted;
                }
                // Atualizar PAD mais recente
                if (!empty($pad)) {
                    $fullAssignments[$assignmentKey]['pad'] = $pad;
                }
            }
        }
    }
}

// Gerar emails únicos para profissionais
foreach ($professionals as $key => &$prof) {
    $email = generateEmail($prof['name'], $emailDomain);
    if (isset($emailCounts[$email])) {
        $emailCounts[$email]++;
        $email = preg_replace('/@/', $emailCounts[$email] . '@', $email);
    } else {
        $emailCounts[$email] = 1;
    }
    $prof['email'] = $email;
}
unset($prof);

// Deduplicar e ordenar sessões
foreach ($fullAssignments as &$assign) {
    $assign['sessions'] = array_unique($assign['sessions']);
    sort($assign['sessions']);
}
unset($assign);

// =====================================================
// FASE 2: Gerar SQL
// =====================================================

$sql = [];
$sql[] = "-- ============================================";
$sql[] = "-- MIGRATION: Importação COMPLETA das planilhas";
$sql[] = "-- Inclui: operadoras, especialidades, profissionais, pacientes,";
$sql[] = "-- demands, patient_assignments, billing_document_requirements";
$sql[] = "-- Gerado em: " . date('Y-m-d H:i:s');
$sql[] = "-- ============================================";
$sql[] = "";
$sql[] = "SET NAMES utf8mb4;";
$sql[] = "SET FOREIGN_KEY_CHECKS = 0;";
$sql[] = "";

// --- 1. Health Insurers ---
$sql[] = "-- ============================================";
$sql[] = "-- 1. OPERADORAS (health_insurers)";
$sql[] = "-- ============================================";
$sql[] = "";
foreach ($operators as $op) {
    $opEsc = addslashes($op);
    $sql[] = "INSERT INTO health_insurers (name, notes, is_active) SELECT '{$opEsc}', 'Importado das planilhas', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM health_insurers WHERE name = '{$opEsc}');";
}
$sql[] = "";

// --- 2. Specialties ---
$sql[] = "-- ============================================";
$sql[] = "-- 2. ESPECIALIDADES (specialties)";
$sql[] = "-- ============================================";
$sql[] = "";
foreach ($specialties as $spec) {
    $specEsc = addslashes($spec);
    $sql[] = "INSERT INTO specialties (name, status) SELECT '{$specEsc}', 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM specialties WHERE name = '{$specEsc}');";
}
$sql[] = "";

// --- 3. Profissionais ---
$sql[] = "-- ============================================";
$sql[] = "-- 3. PROFISSIONAIS (users + user_roles)";
$sql[] = "-- ============================================";
$sql[] = "";
$sql[] = "INSERT INTO roles (name, slug) SELECT 'Profissional', 'profissional' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'profissional');";
$sql[] = "";

foreach ($professionals as $key => $prof) {
    $nameEsc = addslashes($prof['name']);
    $emailEsc = addslashes($prof['email']);
    $phoneEsc = addslashes($prof['phone']);
    $hashEsc = addslashes($defaultPasswordHash);
    
    $sql[] = "-- Prof: {$prof['name']}";
    $sql[] = "INSERT INTO users (name, email, phone, password_hash, status) SELECT '{$nameEsc}', '{$emailEsc}', '{$phoneEsc}', '{$hashEsc}', 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = '{$emailEsc}');";
    $sql[] = "INSERT INTO user_roles (user_id, role_id) SELECT u.id, r.id FROM users u, roles r WHERE u.email = '{$emailEsc}' AND r.slug = 'profissional' AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id);";
    $sql[] = "";
}

// --- 4. Pacientes ---
$sql[] = "-- ============================================";
$sql[] = "-- 4. PACIENTES (patients)";
$sql[] = "-- ============================================";
$sql[] = "";

foreach ($patients as $key => $pat) {
    $nameEsc = addslashes($pat['name']);
    $cityEsc = addslashes(trim($pat['city']));
    $insuranceEsc = addslashes(trim($pat['insurance']));
    $opEsc = addslashes($pat['operator']);
    
    $adminStatus = 'ativo';
    $statusUpper = mb_strtoupper(trim($pat['status']), 'UTF-8');
    if (in_array($statusUpper, ['ÓBITO', 'OBITO'])) $adminStatus = 'obito';
    elseif ($statusUpper === 'ALTA') $adminStatus = 'alta';
    elseif (in_array($statusUpper, ['PENDENCIA', 'PENDENCIAS', 'PENDÊNCIA'])) $adminStatus = 'pendencia';

    $cityVal = $cityEsc ? "'{$cityEsc}'" : "NULL";
    $insVal = $insuranceEsc ? "'{$insuranceEsc}'" : "NULL";

    $sql[] = "INSERT INTO patients (full_name, address_city, insurance_name, admin_status, health_insurer_id) SELECT '{$nameEsc}', {$cityVal}, {$insVal}, '{$adminStatus}', (SELECT id FROM health_insurers WHERE name = '{$opEsc}' LIMIT 1) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM patients WHERE full_name = '{$nameEsc}' AND deleted_at IS NULL);";
}
$sql[] = "";

// --- 5. Patient-Professionals ---
$sql[] = "-- ============================================";
$sql[] = "-- 5. VÍNCULOS PACIENTE-PROFISSIONAL (patient_professionals)";
$sql[] = "-- ============================================";
$sql[] = "";

foreach ($fullAssignments as $assign) {
    $patientName = $patients[$assign['patient_key']]['name'] ?? '';
    $professionalEmail = $professionals[$assign['professional_key']]['email'] ?? '';
    $specialty = $assign['specialty'];
    
    if (empty($patientName) || empty($professionalEmail)) continue;
    
    $patientNameEsc = addslashes($patientName);
    $professionalEmailEsc = addslashes($professionalEmail);
    $specialtyEsc = addslashes($specialty);
    
    $sql[] = "INSERT INTO patient_professionals (patient_id, professional_user_id, specialty, is_active) SELECT p.id, u.id, '{$specialtyEsc}', 1 FROM patients p, users u WHERE p.full_name = '{$patientNameEsc}' AND p.deleted_at IS NULL AND u.email = '{$professionalEmailEsc}' AND NOT EXISTS (SELECT 1 FROM patient_professionals pp WHERE pp.patient_id = p.id AND pp.professional_user_id = u.id);";
}
$sql[] = "";

// --- 6. Demands + Patient Assignments + Billing Document Requirements ---
$sql[] = "-- ============================================";
$sql[] = "-- 6. DEMANDS + PATIENT_ASSIGNMENTS + BILLING_DOCUMENT_REQUIREMENTS";
$sql[] = "-- (fluxo completo de monitoramento)";
$sql[] = "-- ============================================";
$sql[] = "";

// Vou usar um approach com variáveis SET para capturar IDs inseridos
// Para cada assignment, preciso:
// 1. Criar demand
// 2. Criar patient_assignment referenciando a demand
// 3. Criar billing_document_requirements referenciando o assignment

$assignIdx = 0;
$totalSessions = 0;

foreach ($fullAssignments as $assignKey => $assign) {
    $patientName = $patients[$assign['patient_key']]['name'] ?? '';
    $professionalEmail = $professionals[$assign['professional_key']]['email'] ?? '';
    $professionalPhone = $professionals[$assign['professional_key']]['phone'] ?? '';
    $specialty = $assign['specialty'];
    $operator = $assign['operator'];
    $pad = $assign['pad'];
    $firstDate = $assign['first_date'];
    $city = $assign['city'];
    $sessions = $assign['sessions'];
    
    if (empty($patientName) || empty($professionalEmail)) continue;
    if (empty($sessions)) continue; // Sem sessões, sem monitoramento
    
    $assignIdx++;
    $sessionCount = count($sessions);
    $totalSessions += $sessionCount;
    
    $patientNameEsc = addslashes($patientName);
    $professionalEmailEsc = addslashes($professionalEmail);
    $specialtyEsc = addslashes($specialty);
    $operatorEsc = addslashes($operator);
    $padEsc = addslashes($pad);
    $cityEsc = addslashes(trim($city));
    $jid = phoneToJid($professionalPhone);
    $jidEsc = addslashes($jid);
    
    // Data de admissão: usar first_date ou primeira sessão
    $admittedAt = $firstDate;
    if (empty($admittedAt) && !empty($sessions)) {
        $admittedAt = $sessions[0];
    }
    if (empty($admittedAt)) {
        $admittedAt = '2025-05-01'; // fallback
    }
    $admittedAtEsc = addslashes($admittedAt);
    
    // Título da demanda
    $demandTitle = "{$specialty} - {$patientName} ({$operator})";
    $demandTitleEsc = addslashes($demandTitle);
    
    $sql[] = "-- === Assignment #{$assignIdx}: {$patientName} ← {$professionals[$assign['professional_key']]['name']} ({$specialty}/{$operator}) - {$sessionCount} sessões ===";
    
    // 6a. Criar demand
    $sql[] = "INSERT INTO demands (title, location_city, specialty, status, created_at) SELECT '{$demandTitleEsc}', " . ($cityEsc ? "'{$cityEsc}'" : "NULL") . ", '{$specialtyEsc}', 'admitido', '{$admittedAtEsc} 08:00:00' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM demands WHERE title = '{$demandTitleEsc}' LIMIT 1);";
    $sql[] = "SET @demand_id_{$assignIdx} = (SELECT id FROM demands WHERE title = '{$demandTitleEsc}' ORDER BY id DESC LIMIT 1);";
    
    // 6b. Criar patient_assignment
    $sql[] = "INSERT INTO patient_assignments (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id, specialty, service_type, session_quantity, session_frequency, payment_value, agreed_value, status, confirmed_at, approved_at, admitted_at, created_at) SELECT @demand_id_{$assignIdx}, p.id, '{$jidEsc}', u.id, {$adminUserId}, '{$specialtyEsc}', 'Atendimento Domiciliar', {$sessionCount}, '{$padEsc}', 0.00, 0.00, 'admitted', '{$admittedAtEsc} 08:00:00', '{$admittedAtEsc} 08:00:00', '{$admittedAtEsc} 08:00:00', '{$admittedAtEsc} 08:00:00' FROM patients p, users u WHERE p.full_name = '{$patientNameEsc}' AND p.deleted_at IS NULL AND u.email = '{$professionalEmailEsc}' AND NOT EXISTS (SELECT 1 FROM patient_assignments pa2 WHERE pa2.demand_id = @demand_id_{$assignIdx} AND pa2.patient_id = p.id AND pa2.professional_user_id = u.id);";
    $sql[] = "SET @assignment_id_{$assignIdx} = (SELECT pa.id FROM patient_assignments pa INNER JOIN patients p ON p.id = pa.patient_id INNER JOIN users u ON u.id = pa.professional_user_id WHERE p.full_name = '{$patientNameEsc}' AND p.deleted_at IS NULL AND u.email = '{$professionalEmailEsc}' AND pa.demand_id = @demand_id_{$assignIdx} ORDER BY pa.id DESC LIMIT 1);";
    
    // 6c. Criar billing_document_requirements (uma por sessão)
    foreach ($sessions as $sessNum => $sessionDate) {
        $sessNumber = $sessNum + 1;
        $sessionDateEsc = addslashes($sessionDate);
        $sql[] = "INSERT INTO billing_document_requirements (assignment_id, patient_id, professional_user_id, session_number, session_date, status) SELECT @assignment_id_{$assignIdx}, p.id, u.id, {$sessNumber}, '{$sessionDateEsc}', 'pending' FROM patients p, users u WHERE p.full_name = '{$patientNameEsc}' AND p.deleted_at IS NULL AND u.email = '{$professionalEmailEsc}' AND @assignment_id_{$assignIdx} IS NOT NULL AND NOT EXISTS (SELECT 1 FROM billing_document_requirements bdr WHERE bdr.assignment_id = @assignment_id_{$assignIdx} AND bdr.session_number = {$sessNumber});";
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
$sql[] = "-- Assignments (monitoramentos): " . $assignIdx;
$sql[] = "-- Sessões (billing_document_requirements): " . $totalSessions;
$sql[] = "-- ============================================";

// Salvar
$outputPath = __DIR__ . '/../migrations/2026-08-14_0001_import_planilhas.sql';
$content = implode("\n", $sql);
file_put_contents($outputPath, $content);

echo "SQL gerado com sucesso!\n";
echo "Arquivo: migrations/2026-08-14_0001_import_planilhas.sql\n";
echo "Tamanho: " . round(strlen($content) / 1024) . " KB\n";
echo "\nRESUMO:\n";
echo "  Operadoras: " . count($operators) . " (" . implode(', ', $operators) . ")\n";
echo "  Especialidades: " . count($specialties) . " (" . implode(', ', $specialties) . ")\n";
echo "  Profissionais: " . count($professionals) . "\n";
echo "  Pacientes: " . count($patients) . "\n";
echo "  Assignments (monitoramentos): " . $assignIdx . "\n";
echo "  Sessões (billing_document_requirements): " . $totalSessions . "\n";
