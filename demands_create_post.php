<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

// Garante colunas necessárias (idempotente).
if (function_exists('clients_ensure_schema')) {
    try { clients_ensure_schema(); } catch (Throwable $e) {}
}
try { db()->exec("ALTER TABLE demands ADD COLUMN location_raw TEXT NULL"); } catch (Throwable $e) { /* já existe */ }

$patientName = trim((string)($_POST['patient_name'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$specialty = trim((string)($_POST['specialty'] ?? ''));
$frequency = trim((string)($_POST['frequency'] ?? ''));
$urgency = trim((string)($_POST['urgency'] ?? ''));
$procedureValueRaw = trim((string)($_POST['procedure_value'] ?? ''));
$locationRaw = trim((string)($_POST['location_raw'] ?? ''));
$originEmail = trim((string)($_POST['origin_email'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$clientId = (int)($_POST['client_id'] ?? 0);
$healthInsurerId = (int)($_POST['health_insurer_id'] ?? 0);
$status = (string)($_POST['status'] ?? 'aguardando_captacao');

$allowedStatuses = ['aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'aguardando_captacao';
}

// Validações.
if ($patientName === '') {
    flash_set('error', 'Informe o nome do paciente.');
    header('Location: /demands_create.php');
    exit;
}
if ($specialty === '') {
    flash_set('error', 'Informe a especialidade.');
    header('Location: /demands_create.php');
    exit;
}
if ($originEmail !== '' && !filter_var($originEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail de origem inválido.');
    header('Location: /demands_create.php');
    exit;
}

$procedureValue = null;
if ($procedureValueRaw !== '') {
    $procedureValue = (float)str_replace(',', '.', $procedureValueRaw);
    if ($procedureValue < 0) { $procedureValue = null; }
}

$urgencyAllowed = ['urgente', 'normal', 'baixa'];
if (!in_array($urgency, $urgencyAllowed, true)) {
    $urgency = '';
}

// Título gerado automaticamente quando não informado.
if ($title === '') {
    $title = mb_strimwidth($patientName . ' - ' . $specialty, 0, 200, '');
}

// Normalizar frequência (se houver helper).
if ($frequency !== '' && function_exists('frequency_normalize')) {
    if (!defined('FREQUENCY_WEEKDAYS_MAP') || !isset(FREQUENCY_WEEKDAYS_MAP[$frequency])) {
        $norm = frequency_normalize($frequency);
        if ($norm !== '') { $frequency = $norm; }
    }
}

// Detecta quais colunas existem para montar o INSERT dinamicamente.
$cols = [];
try {
    foreach (db()->query("SHOW COLUMNS FROM demands")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[(string)$c['Field']] = true;
    }
} catch (Throwable $e) {}

$insCols = ['title', 'specialty', 'description', 'origin_email', 'status'];
$insVals = [':t', ':sp', ':d', ':o', ':st'];
$params = [
    't' => $title,
    'sp' => $specialty,
    'd' => $description !== '' ? $description : null,
    'o' => $originEmail !== '' ? $originEmail : null,
    'st' => $status,
];

$optional = [
    'patient_name' => ['pname', $patientName !== '' ? $patientName : null],
    'location_raw' => ['lraw', $locationRaw !== '' ? $locationRaw : null],
    'frequency' => ['freq', $frequency !== '' ? $frequency : null],
    'urgency' => ['urg', $urgency !== '' ? $urgency : null],
    'procedure_value' => ['pv', $procedureValue],
    'client_id' => ['cid', $clientId > 0 ? $clientId : null],
    'health_insurer_id' => ['hid', $healthInsurerId > 0 ? $healthInsurerId : null],
];
foreach ($optional as $col => [$ph, $val]) {
    if (isset($cols[$col])) {
        $insCols[] = $col;
        $insVals[] = ':' . $ph;
        $params[$ph] = $val;
    }
}

$stmt = db()->prepare('INSERT INTO demands (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', $insVals) . ')');
$stmt->execute($params);

$id = (string)db()->lastInsertId();

$stmt = db()->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, NULL, :ns, :uid, :note)');
$stmt->execute([
    'did' => $id,
    'ns' => $status,
    'uid' => auth_user_id(),
    'note' => 'criação manual',
]);

audit_log('create', 'demands', $id, null, ['title' => $title, 'status' => $status]);

page_history_log(
    '/demands_list.php',
    'Captação',
    'create',
    'Criou nova demanda: ' . $title,
    'demand',
    (int)$id
);

flash_set('success', 'Card criado.');
header('Location: /demands_view.php?id=' . urlencode($id));
exit;
