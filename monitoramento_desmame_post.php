<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$newFrequency = trim((string)($_POST['new_frequency'] ?? ''));
$isIndefinite = isset($_POST['is_indefinite']) && (string)$_POST['is_indefinite'] === '1' ? 1 : 0;
// Em tempo indeterminado não há total de sessões definido; ignora o campo de quantidade.
$newSessionQty = $isIndefinite ? 0 : (int)($_POST['new_session_quantity'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$applyToAll = isset($_POST['apply_to_all']) && $_POST['apply_to_all'] === '1';
$newWeekdays = isset($_POST['weekdays']) && is_array($_POST['weekdays']) ? array_map('intval', $_POST['weekdays']) : [];
// Dias do mês (para quinzenal/mensal): valores 1..31
$newMonthDays = isset($_POST['month_days']) && is_array($_POST['month_days'])
    ? array_values(array_unique(array_filter(array_map('intval', $_POST['month_days']), fn($d) => $d >= 1 && $d <= 31)))
    : [];

// Modo "posição + dia da semana" (para quinzenal/mensal): ex.: 1ª quinta-feira do mês
$monthMode = isset($_POST['month_mode']) ? (string)$_POST['month_mode'] : 'fixed_day';
$weekPositions = isset($_POST['week_position']) && is_array($_POST['week_position']) ? $_POST['week_position'] : [];
$weekWeekdays = isset($_POST['week_weekday']) && is_array($_POST['week_weekday']) ? array_map('intval', $_POST['week_weekday']) : [];

// Se não veio weekdays do formulário mas temos frequência padronizada, usar a tabela
if (count($newWeekdays) === 0 && $newFrequency !== '' && function_exists('frequency_get_weekdays')) {
    $freqCode = $newFrequency;
    if (!isset(FREQUENCY_WEEKDAYS_MAP[$freqCode])) {
        $freqCode = frequency_normalize($newFrequency);
    }
    if ($freqCode !== '') {
        $newWeekdays = frequency_get_weekdays($freqCode);
    }
}

// Garantir coluna month_days (fallback)
try { db()->exec("ALTER TABLE patient_assignments ADD COLUMN month_days VARCHAR(120) NULL"); } catch (Throwable $e) {}
// Garantir coluna is_indefinite (tempo indeterminado)
try { db()->exec("ALTER TABLE patient_assignments ADD COLUMN is_indefinite TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// Frequências mensais (usam dia do mês em vez de dia da semana)
$isMonthlyFreq = in_array($newFrequency, ['quinzenal', 'biweekly', 'mensal', 'monthly'], true);

if ($assignmentId <= 0 || $newFrequency === '' || $reason === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /monitoramento_desmame.php?assignment_id=' . $assignmentId);
    exit;
}

$stmt = db()->prepare(
    "SELECT pa.*, p.full_name as patient_name, p.whatsapp as patient_phone, p.id as patient_id,
            u.name as professional_name, u.phone as professional_phone
     FROM patient_assignments pa
     INNER JOIN patients p ON p.id = pa.patient_id
     LEFT JOIN users u ON u.id = pa.professional_user_id
     WHERE pa.id = :id"
);
$stmt->execute(['id' => $assignmentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    flash_set('error', 'Atendimento não encontrado.');
    header('Location: /monitoramento.php');
    exit;
}

$oldFrequency = (string)($assignment['session_frequency'] ?? '');
$oldSessionQty = (int)($assignment['session_quantity'] ?? 0);
$patientId = (int)$assignment['patient_id'];

$db = db();
$db->beginTransaction();
try {
    // Atualizar o atendimento principal
    $weekdaysJson = (!$isMonthlyFreq && count($newWeekdays) > 0) ? json_encode(array_values(array_unique($newWeekdays))) : null;
    // month_days guarda a configuração mensal: dia fixo OU posição+dia da semana
    $monthDaysJson = null;
    if ($isMonthlyFreq) {
        if ($monthMode === 'weekday_position' && count($weekPositions) > 0) {
            $pairs = [];
            for ($wp = 0; $wp < count($weekPositions); $wp++) {
                if (isset($weekWeekdays[$wp])) {
                    $pairs[] = ['pos' => (string)$weekPositions[$wp], 'weekday' => (int)$weekWeekdays[$wp]];
                }
            }
            $monthDaysJson = json_encode(['mode' => 'weekday_position', 'pairs' => $pairs]);
        } elseif (count($newMonthDays) > 0) {
            $monthDaysJson = json_encode(['mode' => 'fixed_day', 'days' => array_values($newMonthDays)]);
        }
    }
    // Em tempo indeterminado, não fixa a quantidade de sessões (mantém a existente como referência).
    $qtyToSave = $isIndefinite ? $oldSessionQty : ($newSessionQty > 0 ? $newSessionQty : $oldSessionQty);
    $upd = $db->prepare('UPDATE patient_assignments SET session_frequency = :freq, session_quantity = :qty, weekdays = :wd, month_days = :md, is_indefinite = :indef WHERE id = :id');
    $upd->execute([
        'freq' => $newFrequency,
        'qty' => $qtyToSave,
        'wd' => $weekdaysJson,
        'md' => $monthDaysJson,
        'indef' => $isIndefinite,
        'id' => $assignmentId,
    ]);

    // Recalcular sessões futuras (manter as passadas/já realizadas)
    $stmtPending = $db->prepare(
        "SELECT id, session_number FROM billing_document_requirements 
         WHERE assignment_id = :aid AND status = 'pending' AND (session_date IS NULL OR session_date >= CURDATE())
         ORDER BY session_number ASC"
    );
    $stmtPending->execute(['aid' => $assignmentId]);
    $pendingSessions = $stmtPending->fetchAll();

    // Quantidade ALVO de sessões futuras.
    // - Tempo determinado: usa a nova quantidade informada (ou mantém as pendentes).
    // - Tempo indeterminado: não há total fixo. Mantém as pendentes existentes; se não houver
    //   nenhuma, cria um lote inicial (12) para o paciente ter agenda. Novas sessões podem ser
    //   geradas depois, já que o atendimento não tem prazo final.
    if ($isIndefinite) {
        $targetQty = count($pendingSessions) > 0 ? count($pendingSessions) : 12;
    } else {
        $targetQty = $newSessionQty > 0 ? $newSessionQty : count($pendingSessions);
    }

    // Descobrir o maior session_number já existente (para numerar as novas sem colidir)
    $maxNumStmt = $db->prepare("SELECT COALESCE(MAX(session_number), 0) FROM billing_document_requirements WHERE assignment_id = :aid");
    $maxNumStmt->execute(['aid' => $assignmentId]);
    $maxSessionNumber = (int)$maxNumStmt->fetchColumn();

    // Se faltam sessões para atingir o alvo, criar os registros que faltam.
    $faltam = $targetQty - count($pendingSessions);
    if ($faltam > 0) {
        $insSession = $db->prepare(
            "INSERT INTO billing_document_requirements (assignment_id, patient_id, professional_user_id, session_number, session_date, status)
             VALUES (:aid, :pid, :puid, :num, NULL, 'pending')"
        );
        $profUserId = (int)($assignment['professional_user_id'] ?? 0);
        for ($k = 1; $k <= $faltam; $k++) {
            $maxSessionNumber++;
            try {
                $insSession->execute([
                    'aid' => $assignmentId,
                    'pid' => $patientId,
                    'puid' => $profUserId,
                    'num' => $maxSessionNumber,
                ]);
            } catch (Throwable $e) { /* ignora duplicidade */ }
        }
        // Recarregar a lista de pendentes já com as novas
        $stmtPending->execute(['aid' => $assignmentId]);
        $pendingSessions = $stmtPending->fetchAll();
    }

    if (count($pendingSessions) > 0) {
        $startDate = new DateTime();
        $newDates = [];
        $needed = count($pendingSessions);

        if ($isMonthlyFreq) {
            // Frequência mensal/quinzenal: duas modalidades de seleção de datas.
            sort($newMonthDays);
            $cursorMonth = (int)$startDate->format('n');
            $cursorYear = (int)$startDate->format('Y');
            $safety = 0;

            if ($monthMode === 'weekday_position' && count($weekPositions) > 0) {
                // MODO 2: posição + dia da semana (ex.: "1ª quinta-feira do mês")
                // Para cada par posição+dia, calcular a data exata em cada mês.
                $wdPairs = [];
                for ($wp = 0; $wp < count($weekPositions); $wp++) {
                    if (isset($weekWeekdays[$wp])) {
                        $wdPairs[] = ['pos' => (string)$weekPositions[$wp], 'day' => (int)$weekWeekdays[$wp]];
                    }
                }
                while (count($newDates) < $needed && $safety < 400) {
                    $safety++;
                    foreach ($wdPairs as $pair) {
                        if (count($newDates) >= $needed) break;
                        $pos = $pair['pos']; // '1','2','3','4','last'
                        $wday = $pair['day']; // 1=seg..7=dom
                        $candidate = null;
                        if ($pos === 'last') {
                            // Última ocorrência do dia da semana no mês
                            $lastDay = (int)date('t', mktime(0, 0, 0, $cursorMonth, 1, $cursorYear));
                            for ($dd = $lastDay; $dd >= 1; $dd--) {
                                $dt = mktime(0, 0, 0, $cursorMonth, $dd, $cursorYear);
                                if ((int)date('N', $dt) === $wday) { $candidate = date('Y-m-d', $dt); break; }
                            }
                        } else {
                            // N-ésima ocorrência (1ª, 2ª, 3ª, 4ª)
                            $nth = (int)$pos;
                            $count = 0;
                            $daysInMonth = (int)date('t', mktime(0, 0, 0, $cursorMonth, 1, $cursorYear));
                            for ($dd = 1; $dd <= $daysInMonth; $dd++) {
                                $dt = mktime(0, 0, 0, $cursorMonth, $dd, $cursorYear);
                                if ((int)date('N', $dt) === $wday) {
                                    $count++;
                                    if ($count === $nth) { $candidate = date('Y-m-d', $dt); break; }
                                }
                            }
                        }
                        if ($candidate !== null && $candidate >= $startDate->format('Y-m-d')) {
                            $newDates[] = $candidate;
                        }
                    }
                    // avançar um mês
                    $cursorMonth++;
                    if ($cursorMonth > 12) { $cursorMonth = 1; $cursorYear++; }
                }
            } elseif (count($newMonthDays) > 0) {
                // MODO 1: dia fixo do mês (ex.: todo dia 25)
                while (count($newDates) < $needed && $safety < 400) {
                    $safety++;
                    $daysInMonth = (int)date('t', mktime(0, 0, 0, $cursorMonth, 1, $cursorYear));
                    foreach ($newMonthDays as $dm) {
                        if ($dm > $daysInMonth) { continue; }
                        $candidate = sprintf('%04d-%02d-%02d', $cursorYear, $cursorMonth, $dm);
                        if ($candidate >= $startDate->format('Y-m-d') && count($newDates) < $needed) {
                            $newDates[] = $candidate;
                        }
                    }
                    $cursorMonth++;
                    if ($cursorMonth > 12) { $cursorMonth = 1; $cursorYear++; }
                }
            }
        } elseif (!$isMonthlyFreq && count($newWeekdays) > 0) {
            // Frequência semanal/diária: distribuir nos DIAS DA SEMANA fixos.
            $currentDate = clone $startDate;
            sort($newWeekdays);
            while (count($newDates) < $needed) {
                $dayOfWeek = (int)$currentDate->format('N');
                if (in_array($dayOfWeek, $newWeekdays, true)) {
                    $newDates[] = $currentDate->format('Y-m-d');
                }
                $currentDate->modify('+1 day');
                if ($currentDate->diff($startDate)->days > 365) break;
            }
        }

        // Atualizar datas das sessões pendentes (se calculamos alguma)
        if (count($newDates) > 0) {
            $updDate = $db->prepare('UPDATE billing_document_requirements SET session_date = :sd WHERE id = :id');
            foreach ($pendingSessions as $idx => $sess) {
                $newDate = isset($newDates[$idx]) ? $newDates[$idx] : null;
                $updDate->execute(['sd' => $newDate, 'id' => (int)$sess['id']]);
            }
        }
    }

    // Registrar no log
    $ins = $db->prepare(
        'INSERT INTO patient_frequency_changes (assignment_id, patient_id, old_frequency, new_frequency, old_session_quantity, new_session_quantity, reason, apply_to_all, changed_by_user_id)
         VALUES (:aid, :pid, :of, :nf, :oq, :nq, :reason, :ata, :uid)'
    );
    $ins->execute([
        'aid' => $assignmentId,
        'pid' => $patientId,
        'of' => $oldFrequency !== '' ? $oldFrequency : null,
        'nf' => $newFrequency,
        'oq' => $oldSessionQty > 0 ? $oldSessionQty : null,
        'nq' => $newSessionQty > 0 ? $newSessionQty : null,
        'reason' => $reason,
        'ata' => $applyToAll ? 1 : 0,
        'uid' => auth_user_id(),
    ]);

    // Se aplicar a todos, atualizar outros atendimentos do paciente
    if ($applyToAll) {
        $updAll = $db->prepare(
            "UPDATE patient_assignments SET session_frequency = :freq, session_quantity = :qty, is_indefinite = :indef
             WHERE patient_id = :pid AND id != :aid
             AND status IN ('admitted','awaiting_documents','awaiting_financial_approval','confirmed','approved')"
        );
        $updAll->execute([
            'freq' => $newFrequency,
            'qty' => $isIndefinite ? $oldSessionQty : ($newSessionQty > 0 ? $newSessionQty : $oldSessionQty),
            'indef' => $isIndefinite,
            'pid' => $patientId,
            'aid' => $assignmentId,
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao salvar: ' . $e->getMessage());
    header('Location: /monitoramento_desmame.php?assignment_id=' . $assignmentId);
    exit;
}

// Notificar via WhatsApp (profissional e paciente)
try {
    $dispatcher = new WhatsAppEventDispatcher();
    $dispatcher->dispatch('frequency_changed', [
        'patient_id' => $patientId,
        'patient_name' => (string)$assignment['patient_name'],
        'patient_phone' => (string)($assignment['patient_phone'] ?? ''),
        'professional_id' => (int)($assignment['professional_user_id'] ?? 0),
        'professional_name' => (string)($assignment['professional_name'] ?? ''),
        'professional_phone' => (string)($assignment['professional_phone'] ?? ''),
        'old_frequency' => $oldFrequency,
        'new_frequency' => $newFrequency,
        'reason' => $reason,
    ]);
} catch (Throwable $e) {
    error_log('[DESMAME] Erro ao notificar: ' . $e->getMessage());
}

audit_log('update', 'patient_assignment_frequency', (string)$assignmentId, [
    'frequency' => $oldFrequency,
    'session_quantity' => $oldSessionQty,
], [
    'frequency' => $newFrequency,
    'session_quantity' => $newSessionQty,
]);

flash_set('success', 'Frequência alterada com sucesso! De "' . $oldFrequency . '" para "' . $newFrequency . '".');
header('Location: /monitoramento.php');
exit;
