<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/whatsapp_instances.php';

auth_require_login();
rbac_require_permission('demands.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /monitoramento.php');
    exit;
}

// Garante colunas de sigilo (idempotente).
try {
    db()->exec("ALTER TABLE demands ADD COLUMN is_confidential TINYINT(1) NOT NULL DEFAULT 0");
} catch (Throwable $e) { /* já existe */ }
try {
    db()->exec("ALTER TABLE demands ADD COLUMN confidential_exclude_user_id INT UNSIGNED NULL");
} catch (Throwable $e) { /* já existe */ }

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    flash_set('error', 'Atendimento inválido.');
    header('Location: /monitoramento.php');
    exit;
}

// Carregar atendimento + demanda + profissional atual.
$stmt = db()->prepare(
    "SELECT pa.*, d.id AS demand_id, d.title, d.specialty AS demand_specialty, d.location_city, d.location_state,
            d.ai_summary, d.frequency AS demand_frequency,
            p.full_name AS patient_name,
            u.id AS cur_prof_id, u.name AS cur_prof_name
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     LEFT JOIN demands d ON d.id = pa.demand_id
     LEFT JOIN users u ON u.id = pa.professional_user_id
     WHERE pa.id = :id"
);
$stmt->execute(['id' => $assignmentId]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$a) {
    flash_set('error', 'Atendimento não encontrado.');
    header('Location: /monitoramento.php');
    exit;
}

$demandId = (int)($a['demand_id'] ?? 0);
$currentProfId = (int)($a['cur_prof_id'] ?? 0);
$specialty = (string)($a['specialty'] ?? $a['demand_specialty'] ?? '');
$city = (string)($a['location_city'] ?? '');
$state = (string)($a['location_state'] ?? '');

if ($demandId <= 0) {
    flash_set('error', 'Este atendimento não tem uma captação (demanda) vinculada para recapturar.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}

// Marcar a demanda como em recaptação SIGILOSA, guardando quem não pode ser avisado.
try {
    db()->prepare(
        "UPDATE demands SET status = 'em_captacao', is_confidential = 1, confidential_exclude_user_id = :ex, updated_at = NOW() WHERE id = :id"
    )->execute(['ex' => $currentProfId > 0 ? $currentProfId : null, 'id' => $demandId]);
} catch (Throwable $e) {
    error_log('[RECAPTAR_SIGILOSO] Erro ao marcar demanda: ' . $e->getMessage());
}

// Selecionar profissionais ELEGÍVEIS para DM: ativos, role 'profissional', COM telefone,
// EXCLUINDO o profissional atual. (Filtro de especialidade/região é intencionalmente amplo:
// a lista de interessados é refinada manualmente depois.)
$sqlProfs =
    "SELECT DISTINCT u.id, u.name, u.phone
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id
     WHERE u.status = 'active' AND r.slug = 'profissional'
       AND u.phone IS NOT NULL AND u.phone <> ''
       AND u.id <> :cur
     ORDER BY u.name ASC
     LIMIT 200";
$profStmt = db()->prepare($sqlProfs);
$profStmt->execute(['cur' => $currentProfId]);
$professionals = $profStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($professionals) === 0) {
    flash_set('error', 'Nenhum profissional elegível (ativo e com telefone) para recaptação sigilosa.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}

// Montar mensagem SANITIZADA (sem nome do paciente). Inicial do paciente p/ privacidade.
$patientName = trim((string)($a['patient_name'] ?? ''));
$patientInitial = '';
if ($patientName !== '') {
    $tok = trim(preg_split('/\s+/', $patientName)[0] ?? '');
    if ($tok !== '') $patientInitial = mb_strtoupper(mb_substr($tok, 0, 1)) . '.';
}

$freqRaw = (string)($a['session_frequency'] ?? $a['demand_frequency'] ?? '');
$freqDisplay = $freqRaw;
if ($freqRaw !== '' && function_exists('frequency_get_label') && function_exists('frequency_normalize')) {
    $norm = frequency_normalize($freqRaw);
    if ($norm !== '') $freqDisplay = frequency_get_label($norm);
}

$localTxt = trim(($city !== '' ? $city : '') . ($state !== '' ? '/' . $state : ''));

$msgLines = [];
$msgLines[] = '[OPORTUNIDADE DE ATENDIMENTO]';
if ($specialty !== '') $msgLines[] = '🏥 Especialidade: ' . $specialty;
if ($localTxt !== '') $msgLines[] = '📍 Local: ' . $localTxt;
if ($freqDisplay !== '') $msgLines[] = '📅 Frequência: ' . $freqDisplay;
$msgLines[] = '';
$msgLines[] = 'Temos um atendimento disponível. Você tem interesse e disponibilidade?';
$msgLines[] = 'Responda esta mensagem para alinharmos os detalhes.';
$message = implode("\n", $msgLines);

// Instância do captador para enviar as DMs.
$captadorInstance = whatsapp_get_user_instance(auth_user_id());
$captadorInstanceName = (string)($captadorInstance['instance_name'] ?? '');

$api = null;
try {
    $baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
    $apiKey = (string)admin_setting_get('evolution.api_key', '');
    if ($baseUrl !== '' && $apiKey !== '' && $captadorInstanceName !== '') {
        $api = new EvolutionApiV1($baseUrl, $apiKey, $captadorInstanceName);
    } elseif ($baseUrl !== '' && $apiKey !== '') {
        // Fallback: primeira instância conectada.
        $instStmt = db()->query("SELECT instance_name FROM whatsapp_instances WHERE status = 'active' AND connection_status = 'connected' ORDER BY is_default DESC, id ASC LIMIT 1");
        $instName = (string)($instStmt->fetchColumn() ?: '');
        if ($instName !== '') {
            $api = new EvolutionApiV1($baseUrl, $apiKey, $instName);
        }
    }
} catch (Throwable $e) {
    error_log('[RECAPTAR_SIGILOSO] Erro ao instanciar Evolution: ' . $e->getMessage());
}

if ($api === null) {
    flash_set('error', 'Nenhuma instância WhatsApp conectada para enviar a recaptação. Verifique as conexões.');
    header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
    exit;
}

$logStmt = db()->prepare(
    "INSERT INTO demand_dispatch_logs (demand_id, group_id, dispatched_by_user_id, message, dispatch_status, error_message)
     VALUES (:did, NULL, :uid, :msg, :st, :err)"
);

$sent = 0;
$failed = 0;
foreach ($professionals as $prof) {
    $phone = preg_replace('/\D+/', '', (string)($prof['phone'] ?? ''));
    if ($phone === '') { continue; }
    if (!str_starts_with($phone, '55')) { $phone = '55' . $phone; }

    try {
        $res = $api->sendText($phone, $message);
        $http = (int)($res['status'] ?? 0);
        $ok = $http >= 200 && $http < 300;
        if ($ok) { $sent++; } else { $failed++; }
        $logStmt->execute([
            'did' => $demandId,
            'uid' => auth_user_id(),
            'msg' => '[SIGILOSO/DM] ' . $message,
            'st' => $ok ? 'sent' : 'error',
            'err' => $ok ? null : ('HTTP ' . $http),
        ]);
    } catch (Throwable $e) {
        $failed++;
        try {
            $logStmt->execute([
                'did' => $demandId,
                'uid' => auth_user_id(),
                'msg' => '[SIGILOSO/DM] ' . $message,
                'st' => 'error',
                'err' => mb_substr($e->getMessage(), 0, 250),
            ]);
        } catch (Throwable $e2) {}
    }
    // Pequeno intervalo para evitar flood/bloqueio.
    usleep(300000); // 300ms
}

audit_log('dispatch', 'demand_confidential_recaptation', (string)$demandId, null, [
    'assignment_id' => $assignmentId,
    'excluded_professional_id' => $currentProfId,
    'sent' => $sent,
    'failed' => $failed,
]);

flash_set('success', 'Recaptação sigilosa enviada por mensagem direta a ' . $sent . ' profissional(is)'
    . ($failed > 0 ? ' (' . $failed . ' falha(s))' : '')
    . '. O profissional atual não foi avisado e nenhum grupo foi usado.');
header('Location: /monitoramento_substituicao.php?assignment_id=' . $assignmentId);
exit;
