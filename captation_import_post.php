<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /captation_import.php');
    exit;
}

if (!isset($_FILES['captation_file']) || ($_FILES['captation_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_set('error', 'Envie um arquivo CSV.');
    header('Location: /captation_import.php');
    exit;
}

$delimiterOpt = (string)($_POST['delimiter'] ?? ';');
$delimiter = $delimiterOpt === 'tab' ? "\t" : ($delimiterOpt === ',' ? ',' : ';');
$hasHeader = isset($_POST['has_header']) && $_POST['has_header'] === '1';
$defaultSpecialty = trim((string)($_POST['default_specialty'] ?? ''));
$zeroInProgress = isset($_POST['zero_in_progress']) && $_POST['zero_in_progress'] === '1';
$zeroConfirm = isset($_POST['zero_confirm']) && $_POST['zero_confirm'] === '1';

$db = db();

// Ler e normalizar o arquivo.
$raw = (string)file_get_contents((string)$_FILES['captation_file']['tmp_name']);
$origName = (string)$_FILES['captation_file']['name'];
if ($raw === '') {
    flash_set('error', 'Arquivo vazio.');
    header('Location: /captation_import.php');
    exit;
}
$raw = str_replace("\xEF\xBB\xBF", '', $raw);
$enc = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
if ($enc && $enc !== 'UTF-8') {
    $raw = (string)mb_convert_encoding($raw, 'UTF-8', $enc);
}
$lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $raw), static fn($l) => trim((string)$l) !== ''));
if (count($lines) === 0) {
    flash_set('error', 'Nenhuma linha no arquivo.');
    header('Location: /captation_import.php');
    exit;
}

// Detectar colunas pelo cabeçalho.
$idx = ['patient' => -1, 'professional' => -1, 'specialty' => -1, 'city' => -1, 'state' => -1, 'frequency' => -1, 'status' => -1, 'phone' => -1];
$startRow = 0;
if ($hasHeader) {
    $startRow = 1;
    foreach (str_getcsv($lines[0], $delimiter) as $i => $colRaw) {
        $col = mb_strtolower(trim((string)$colRaw));
        if ($idx['patient'] < 0 && preg_match('/\b(paciente|pacientes|segurado|cliente)\b/u', $col)) $idx['patient'] = $i;
        if ($idx['professional'] < 0 && preg_match('/\b(profissional|profissionais|prestador)\b/u', $col)) $idx['professional'] = $i;
        if ($idx['specialty'] < 0 && preg_match('/\b(especialidade|servico|serviço|tipo)\b/u', $col)) $idx['specialty'] = $i;
        if ($idx['city'] < 0 && preg_match('/\b(cidade|municipio|município|polo)\b/u', $col)) $idx['city'] = $i;
        if ($idx['state'] < 0 && preg_match('/\b(uf|estado)\b/u', $col)) $idx['state'] = $i;
        if ($idx['frequency'] < 0 && preg_match('/\b(frequencia|frequência|pad|periodicidade)\b/u', $col)) $idx['frequency'] = $i;
        if ($idx['status'] < 0 && preg_match('/\b(status|situacao|situação)\b/u', $col)) $idx['status'] = $i;
        if ($idx['phone'] < 0 && preg_match('/\b(telefone|celular|contato|fone|whatsapp)\b/u', $col)) $idx['phone'] = $i;
    }
}
// Defaults posicionais se não houver cabeçalho identificável.
if ($idx['patient'] < 0) $idx['patient'] = 0;
if ($idx['professional'] < 0) $idx['professional'] = 1;

// Helpers inline (idempotentes por nome).
$ensureSpecialtyName = function (string $name) use ($db): string {
    $name = trim($name);
    if ($name === '') return '';
    try {
        $s = $db->prepare("SELECT name FROM specialties WHERE LOWER(name) = LOWER(:n) LIMIT 1");
        $s->execute(['n' => $name]);
        $found = $s->fetchColumn();
        if ($found) return (string)$found;
        $db->prepare("INSERT INTO specialties (name, status) VALUES (:n, 'active')")->execute(['n' => $name]);
    } catch (Throwable $e) { /* ignore */ }
    return $name;
};

$ensurePatientId = function (string $fullName) use ($db): int {
    $fullName = trim($fullName);
    if ($fullName === '') return 0;
    $s = $db->prepare("SELECT id FROM patients WHERE LOWER(full_name) = LOWER(:n) AND deleted_at IS NULL LIMIT 1");
    $s->execute(['n' => $fullName]);
    $id = (int)($s->fetchColumn() ?: 0);
    if ($id > 0) return $id;
    $db->prepare("INSERT INTO patients (full_name) VALUES (:n)")->execute(['n' => $fullName]);
    return (int)$db->lastInsertId();
};

$ensureProfessionalId = function (string $name, string $phone) use ($db): int {
    $name = trim($name);
    if ($name === '') return 0;
    $s = $db->prepare("SELECT id FROM users WHERE LOWER(name) = LOWER(:n) LIMIT 1");
    $s->execute(['n' => $name]);
    $id = (int)($s->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    $slug = preg_replace('/[^a-z0-9]+/', '.', mb_strtolower($name));
    $slug = trim((string)$slug, '.');
    if ($slug === '') $slug = 'prof';
    $email = $slug . '@import.multilife.local';
    $chk = $db->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
    $chk->execute(['e' => $email]);
    if ($chk->fetch()) { $email = $slug . '.' . time() . rand(100, 999) . '@import.multilife.local'; }

    $phoneClean = preg_replace('/\D+/', '', $phone);
    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO users (name, email, phone, password_hash, status) VALUES (:n,:e,:p,:h,'active')")
        ->execute(['n' => $name, 'e' => $email, 'p' => $phoneClean !== '' ? $phoneClean : null, 'h' => $hash]);
    $uid = (int)$db->lastInsertId();
    try {
        $roleId = (int)($db->query("SELECT id FROM roles WHERE slug = 'profissional' LIMIT 1")->fetchColumn() ?: 0);
        if ($roleId > 0) {
            $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:u,:r)")->execute(['u' => $uid, 'r' => $roleId]);
        }
    } catch (Throwable $e) {}
    return $uid;
};

$cellsAt = function (array $row, int $i): string {
    return $i >= 0 && isset($row[$i]) ? trim((string)$row[$i]) : '';
};

$imported = 0;
$skipped = 0;
$zeroed = 0;

try {
    $db->beginTransaction();

    // ZERAR em andamento anteriores (se confirmado).
    if ($zeroInProgress && $zeroConfirm) {
        $statuses = ['aguardando_captacao', 'tratamento_manual', 'em_captacao'];
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        // Antes de agora: usa NOW() como corte (os cards já existentes).
        $sel = $db->prepare("SELECT id, status FROM demands WHERE status IN ($ph) AND created_at <= NOW()");
        $sel->execute($statuses);
        $toZero = $sel->fetchAll(PDO::FETCH_ASSOC);

        $updZero = $db->prepare("UPDATE demands SET status = 'cancelado', updated_at = NOW() WHERE id = :id");
        $logZero = $db->prepare("INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did,:old,'cancelado',:uid,:note)");
        foreach ($toZero as $z) {
            $updZero->execute(['id' => (int)$z['id']]);
            $logZero->execute(['did' => (int)$z['id'], 'old' => (string)$z['status'], 'uid' => auth_user_id(), 'note' => 'Zerado na importação de captações']);
            $zeroed++;
        }
    } elseif ($zeroInProgress && !$zeroConfirm) {
        $db->rollBack();
        flash_set('error', 'Para zerar os cards em andamento, marque também a confirmação.');
        header('Location: /captation_import.php');
        exit;
    }

    // Importar linhas da planilha como cards (demands) já admitidos quando houver profissional.
    $insDemand = $db->prepare(
        "INSERT INTO demands (title, patient_name, specialty, location_city, location_state, frequency, status, description)
         VALUES (:t,:pn,:sp,:city,:state,:freq,:st,:desc)"
    );
    $insLog = $db->prepare(
        "INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, NULL, :ns, :uid, :note)"
    );

    for ($r = $startRow; $r < count($lines); $r++) {
        $row = str_getcsv($lines[$r], $delimiter);
        $patient = $cellsAt($row, $idx['patient']);
        $professional = $cellsAt($row, $idx['professional']);
        $specialty = $cellsAt($row, $idx['specialty']);
        if ($specialty === '' && $defaultSpecialty !== '') $specialty = $defaultSpecialty;
        $city = $cellsAt($row, $idx['city']);
        $state = mb_strtoupper($cellsAt($row, $idx['state']));
        if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) $state = '';
        $frequency = $cellsAt($row, $idx['frequency']);
        $statusRaw = mb_strtolower($cellsAt($row, $idx['status']));
        $phone = $cellsAt($row, $idx['phone']);

        if ($patient === '') { $skipped++; continue; }

        // Status do card a partir do status da planilha.
        $demandStatus = 'admitido';
        if ($statusRaw !== '') {
            if (preg_match('/(cancel|óbito|obito|desist)/u', $statusRaw)) $demandStatus = 'cancelado';
            elseif (preg_match('/(fechad|admit|ativo|internad)/u', $statusRaw)) $demandStatus = 'admitido';
            elseif (preg_match('/(captac|andamento|aberto)/u', $statusRaw)) $demandStatus = 'em_captacao';
        }

        $specialtyName = $specialty !== '' ? $ensureSpecialtyName($specialty) : '';
        $title = trim($patient . ($specialtyName !== '' ? ' - ' . $specialtyName : ''));

        $insDemand->execute([
            't' => mb_strimwidth($title, 0, 200, ''),
            'pn' => mb_substr($patient, 0, 200),
            'sp' => $specialtyName !== '' ? $specialtyName : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'freq' => $frequency !== '' ? $frequency : null,
            'st' => $demandStatus,
            'desc' => 'Importado via planilha de captações (' . mb_substr($origName, 0, 80) . ')',
        ]);
        $demandId = (int)$db->lastInsertId();
        $insLog->execute(['did' => $demandId, 'ns' => $demandStatus, 'uid' => auth_user_id(), 'note' => 'Importação de captações']);

        // Pré-cadastra paciente e profissional (não cria assignment aqui para não
        // conflitar com o fluxo de monitoramento; a equipe vincula depois).
        $ensurePatientId($patient);
        if ($professional !== '') { $ensureProfessionalId($professional, $phone); }

        $imported++;
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    error_log('[CAPTATION_IMPORT] ' . $e->getMessage());
    flash_set('error', 'Erro na importação: ' . $e->getMessage());
    header('Location: /captation_import.php');
    exit;
}

audit_log('import', 'demands_captation_import', '0', null, [
    'source' => mb_substr($origName, 0, 120),
    'imported' => $imported,
    'skipped' => $skipped,
    'zeroed' => $zeroed,
]);

flash_set('success', 'Importação concluída: ' . $imported . ' card(s) criado(s)'
    . ($zeroed > 0 ? ', ' . $zeroed . ' em andamento zerado(s)' : '')
    . ($skipped > 0 ? ', ' . $skipped . ' linha(s) ignorada(s)' : '') . '.');
header('Location: /demands_list.php');
exit;
