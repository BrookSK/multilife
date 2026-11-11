<?php

declare(strict_types=1);

/**
 * Edição dos dados do ATENDIMENTO (patient_assignments) direto na tela de
 * Fechamento Mensal (conferência).
 *
 * Campos: PAD (frequência / qtd. de sessões / contínuo), especialidade,
 * cliente / polo / operadora, datas (início, desmame, fim) e observação interna.
 *
 * Como o fechamento financeiro e as demais telas leem do próprio atendimento,
 * a alteração aparece em todo o sistema. NÃO recalcula as datas das sessões
 * (isso continua no fluxo de desmame do Monitoramento).
 *
 * Bloqueio: se o cliente/operadora do atendimento já foi fechado na competência,
 * é preciso reabrir antes de editar (evita divergência com o que foi conferido).
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('billing.closure.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /faturamento_fechamento.php');
    exit;
}

$db = db();
clients_ensure_schema();

$month = billing_closure_normalize_month($_POST['month'] ?? null) ?? date('Y-m');
$filters = billing_closure_filters_from(is_array($_POST['f'] ?? null) ? $_POST['f'] : []);
$redirect = '/faturamento_fechamento.php?' . billing_closure_filters_qs($month, $filters);

$fail = static function (string $msg) use ($redirect): void {
    flash_set('error', $msg);
    header('Location: ' . $redirect);
    exit;
};

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    $fail('Atendimento inválido.');
}

$stmt = $db->prepare("SELECT pa.*, p.full_name AS patient_name FROM patient_assignments pa INNER JOIN patients p ON p.id = pa.patient_id WHERE pa.id = :id LIMIT 1");
$stmt->execute(['id' => $assignmentId]);
$old = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$old) {
    $fail('Atendimento não encontrado.');
}

// ---- Leitura e validação ----
$parseDate = static function ($v): ?string {
    $v = trim((string)$v);
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) {
        return 'invalid';
    }
    return $v;
};

$frequency = trim((string)($_POST['session_frequency'] ?? ''));
if ($frequency !== '' && !isset(FREQUENCY_LABELS[$frequency])) {
    // Mantém valores legados que já estavam no atendimento (texto livre).
    if ($frequency !== (string)($old['session_frequency'] ?? '')) {
        $fail('Frequência (PAD) inválida.');
    }
}
$isIndefinite = (string)($_POST['is_indefinite'] ?? '') === '1' ? 1 : 0;
$qtyRaw = trim((string)($_POST['session_quantity'] ?? ''));
$quantity = $qtyRaw === '' ? null : (int)$qtyRaw;
if ($quantity !== null && ($quantity < 0 || $quantity > 1000)) {
    $fail('Quantidade de sessões inválida.');
}

$specialty = mb_substr(trim((string)($_POST['specialty'] ?? '')), 0, 120);
$internalNotes = trim((string)($_POST['internal_notes'] ?? ''));
if (mb_strlen($internalNotes) > 5000) {
    $fail('Observação interna muito longa (máx. 5000 caracteres).');
}

$admittedAt = $parseDate($_POST['admitted_at'] ?? '');
$weaningAt = $parseDate($_POST['weaning_at'] ?? '');
$endedAt = $parseDate($_POST['ended_at'] ?? '');
if ($admittedAt === 'invalid' || $weaningAt === 'invalid' || $endedAt === 'invalid') {
    $fail('Data inválida.');
}
if ($admittedAt !== null && $endedAt !== null && $endedAt < $admittedAt) {
    $fail('A data de fim não pode ser anterior à data de início.');
}
if ($admittedAt !== null && $weaningAt !== null && $weaningAt < $admittedAt) {
    $fail('A data do desmame não pode ser anterior à data de início.');
}

// Opcional: também mudar a AGENDA (faz o desmame, igual ao Monitoramento).
$reschedule = (string)($_POST['reschedule'] ?? '') === '1';
$rescheduleReason = mb_substr(trim((string)($_POST['reschedule_reason'] ?? '')), 0, 1000);
$rescheduleNotify = (string)($_POST['reschedule_notify'] ?? '') === '1';
$rescheduleWeekdays = [];
$rescheduleMonthCfg = null;
if ($reschedule) {
    $desmameLink = ' Use o Desmame no Monitoramento (/monitoramento_desmame.php?assignment_id=' . $assignmentId . ').';
    if ($frequency === '' || !isset(FREQUENCY_LABELS[$frequency])) {
        $fail('Para mudar a agenda, escolha um PAD da lista.');
    }
    if ($rescheduleReason === '') {
        $fail('Informe o motivo do desmame para mudar a agenda.');
    }
    if (assignment_schedule_is_monthly($frequency)) {
        // Quinzenal/mensal precisam dos dias do mês, que só são escolhidos no Desmame.
        $rescheduleMonthCfg = $frequency === (string)($old['session_frequency'] ?? '')
            ? assignment_schedule_month_cfg_from_json($old['month_days'] ?? null)
            : null;
        if ($rescheduleMonthCfg === null) {
            $fail('Para quinzenal/mensal é preciso escolher os dias do mês.' . $desmameLink);
        }
    } else {
        $rescheduleWeekdays = assignment_schedule_weekdays_for($frequency, $old['session_frequency'] ?? null, $old['weekdays'] ?? null);
        if (!$rescheduleWeekdays) {
            $fail('Esse PAD não tem dias fixos na semana, então a agenda não pode ser remarcada automaticamente.' . $desmameLink);
        }
    }
}

// Cliente / operadora / polo (completa pelo cadastro da operadora quando faltar).
$org = org_resolve(
    (int)($_POST['client_id'] ?? 0),
    (int)($_POST['health_insurer_id'] ?? 0),
    (int)($_POST['polo_id'] ?? 0)
);
if ($org['health_insurer_id'] !== null) {
    $opClient = operator_client_id((int)$org['health_insurer_id']);
    if ($opClient !== null && $org['client_id'] !== null && $opClient !== (int)$org['client_id']) {
        $fail('A operadora escolhida não pertence ao cliente escolhido.');
    }
}
if ($org['polo_id'] !== null) {
    $polo = polos_find((int)$org['polo_id']);
    if ($polo && $polo['client_id'] !== null && $org['client_id'] !== null && (int)$polo['client_id'] !== (int)$org['client_id']) {
        $fail('O polo escolhido não pertence ao cliente escolhido.');
    }
}

// Bloqueio por fechamento já feito na competência (cliente ou operadora atual/nova).
$scoped = billing_closure_scoped_status($db, $month);
$curInsurer = $old['health_insurer_id'] !== null ? (int)$old['health_insurer_id'] : 0;
$curClient = $old['client_id'] !== null ? (int)$old['client_id'] : ($curInsurer > 0 ? (int)(operator_client_id($curInsurer) ?? 0) : 0);
foreach ([$curClient, (int)($org['client_id'] ?? 0)] as $cid) {
    if ($cid > 0 && isset($scoped['clients'][$cid])) {
        $fail('O cliente deste atendimento já foi consolidado em ' . date('m/Y', strtotime($month . '-01')) . '. Reabra o cliente para editar.');
    }
}
foreach ([$curInsurer, (int)($org['health_insurer_id'] ?? 0)] as $iid) {
    if ($iid > 0 && isset($scoped['operators'][$iid])) {
        $fail('A operadora deste atendimento já foi fechada em ' . date('m/Y', strtotime($month . '-01')) . '. Reabra a operadora para editar.');
    }
}

// Datas DATETIME: mantém a hora original se o dia não mudou.
$keepTime = static function (?string $newDate, $oldValue): ?string {
    if ($newDate === null) {
        return null;
    }
    $oldValue = (string)($oldValue ?? '');
    if ($oldValue !== '' && substr($oldValue, 0, 10) === $newDate) {
        return $oldValue;
    }
    return $newDate . ' 00:00:00';
};

$new = [
    'session_frequency' => $frequency !== '' ? $frequency : null,
    'session_quantity' => $quantity,
    'is_indefinite' => $isIndefinite,
    'specialty' => $specialty !== '' ? $specialty : null,
    'client_id' => $org['client_id'],
    'health_insurer_id' => $org['health_insurer_id'],
    'polo_id' => $org['polo_id'],
    'admitted_at' => $keepTime($admittedAt, $old['admitted_at'] ?? null),
    'weaning_at' => $weaningAt,
    'ended_at' => $keepTime($endedAt, $old['ended_at'] ?? null),
    'internal_notes' => $internalNotes !== '' ? $internalNotes : null,
];

// Frequência mudou para um código padrão semanal: atualiza os dias da semana
// de referência (as datas das sessões já geradas NÃO são recalculadas).
$frequencyChanged = (string)($old['session_frequency'] ?? '') !== (string)($new['session_frequency'] ?? '');
$setWeekdays = false;
$weekdaysJson = null;
if ($frequencyChanged && $frequency !== '' && isset(FREQUENCY_WEEKDAYS_MAP[$frequency])) {
    $wd = FREQUENCY_WEEKDAYS_MAP[$frequency];
    $weekdaysJson = $wd ? json_encode(array_values($wd)) : null;
    $setWeekdays = true;
}

// Só grava o que mudou.
$changes = [];
foreach ($new as $col => $val) {
    $oldVal = $old[$col] ?? null;
    $a = $oldVal === null ? null : (string)$oldVal;
    $b = $val === null ? null : (string)$val;
    if ($a !== $b) {
        $changes[$col] = $val;
    }
}
if ($setWeekdays) {
    $changes['weekdays'] = $weekdaysJson;
}

if ($reschedule && $weaningAt === null) {
    // Desmame sem data informada: registra hoje.
    $changes['weaning_at'] = date('Y-m-d');
}

if (empty($changes) && !$reschedule) {
    flash_set('success', 'Nenhuma alteração para salvar.');
    header('Location: ' . $redirect);
    exit;
}

$rescheduleResult = null;
try {
    $db->beginTransaction();

    if ($changes) {
        $set = [];
        $params = ['id' => $assignmentId];
        foreach ($changes as $col => $val) {
            $set[] = "`$col` = :$col";
            $params[$col] = $val;
        }
        $db->prepare('UPDATE patient_assignments SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    }

    // Desmame: remarca as sessões pendentes de hoje (ou da data do desmame) em diante.
    if ($reschedule) {
        $startDate = new DateTime((string)($changes['weaning_at'] ?? $weaningAt ?? 'today'));
        $rescheduleResult = assignment_reschedule(
            $db,
            $old, // quantidade anterior é a referência em tempo indeterminado
            $frequency,
            $isIndefinite,
            (int)($quantity ?? 0),
            $rescheduleWeekdays,
            $rescheduleMonthCfg,
            $startDate
        );
    }

    // Mantém o histórico de mudança de frequência (mesma tabela do desmame).
    if ($reschedule || $frequencyChanged || array_key_exists('session_quantity', $changes)) {
        try {
            $db->prepare(
                'INSERT INTO patient_frequency_changes (assignment_id, patient_id, old_frequency, new_frequency, old_session_quantity, new_session_quantity, reason, apply_to_all, changed_by_user_id)
                 VALUES (:aid, :pid, :of, :nf, :oq, :nq, :reason, 0, :uid)'
            )->execute([
                'aid' => $assignmentId,
                'pid' => (int)$old['patient_id'],
                'of' => ($old['session_frequency'] ?? '') !== '' ? $old['session_frequency'] : null,
                'nf' => (string)($new['session_frequency'] ?? ''),
                'oq' => (int)($old['session_quantity'] ?? 0) > 0 ? (int)$old['session_quantity'] : null,
                'nq' => $quantity !== null && $quantity > 0 ? $quantity : null,
                'reason' => $reschedule
                    ? mb_substr('Desmame pelo Fechamento Mensal: ' . $rescheduleReason, 0, 1000)
                    : 'Ajuste no Fechamento Mensal (sem mudar a agenda)',
                'uid' => auth_user_id(),
            ]);
        } catch (Throwable $e) {
            error_log('[FECHAMENTO_EDIT] log frequência: ' . $e->getMessage());
        }
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[FECHAMENTO_EDIT] #' . $assignmentId . ': ' . $e->getMessage());
    $fail('Erro ao salvar: ' . $e->getMessage());
}

$before = [];
foreach (array_keys($changes) as $col) {
    $before[$col] = $old[$col] ?? null;
}
$auditAfter = $changes + ['_origem' => 'faturamento_fechamento'];
if ($rescheduleResult !== null) {
    $auditAfter['_agenda_remarcada'] = [
        'pendentes' => $rescheduleResult['pending'],
        'criadas' => $rescheduleResult['created'],
        'motivo' => $rescheduleReason,
    ];
}
audit_log('update', 'patient_assignments', (string)$assignmentId, $before, $auditAfter);

// Aviso por WhatsApp (mesmo evento do desmame), só se marcado.
if ($rescheduleResult !== null && $rescheduleNotify) {
    try {
        $info = $db->prepare(
            'SELECT p.full_name AS patient_name, p.whatsapp AS patient_phone,
                    u.name AS professional_name, u.phone AS professional_phone
             FROM patient_assignments pa
             INNER JOIN patients p ON p.id = pa.patient_id
             LEFT JOIN users u ON u.id = pa.professional_user_id
             WHERE pa.id = :id'
        );
        $info->execute(['id' => $assignmentId]);
        $ir = $info->fetch(PDO::FETCH_ASSOC) ?: [];
        (new WhatsAppEventDispatcher())->dispatch('frequency_changed', [
            'patient_id' => (int)$old['patient_id'],
            'patient_name' => (string)($ir['patient_name'] ?? $old['patient_name']),
            'patient_phone' => (string)($ir['patient_phone'] ?? ''),
            'professional_id' => (int)($old['professional_user_id'] ?? 0),
            'professional_name' => (string)($ir['professional_name'] ?? ''),
            'professional_phone' => (string)($ir['professional_phone'] ?? ''),
            'old_frequency' => (string)($old['session_frequency'] ?? ''),
            'new_frequency' => $frequency,
            'reason' => $rescheduleReason,
        ]);
    } catch (Throwable $e) {
        error_log('[FECHAMENTO_EDIT] aviso desmame: ' . $e->getMessage());
    }
}

$msg = 'Atendimento de ' . (string)$old['patient_name'] . ' atualizado.';
if ($rescheduleResult !== null) {
    $msg .= ' Agenda remarcada (desmame): ' . (int)$rescheduleResult['dated'] . ' sessão(ões) pendente(s) com nova data';
    if (!empty($rescheduleResult['dates'][0])) {
        $msg .= ', a partir de ' . date('d/m/Y', strtotime($rescheduleResult['dates'][0]));
    }
    $msg .= '.';
    if ($rescheduleNotify) {
        $msg .= ' Profissional e paciente avisados no WhatsApp.';
    }
} elseif ($frequencyChanged || array_key_exists('session_quantity', $changes) || array_key_exists('is_indefinite', $changes)) {
    $msg .= ' O PAD mudou só no cadastro: as datas das sessões continuam as mesmas.';
}
flash_set('success', $msg);
header('Location: ' . $redirect . '#pa-' . $assignmentId);
exit;
