<?php

declare(strict_types=1);

/**
 * Remarcação da agenda de um atendimento (DESMAME / mudança de PAD).
 *
 * Regra única usada pelo Monitoramento (monitoramento_desmame_post.php) e pelo
 * Fechamento Mensal (faturamento_atendimento_update_post.php):
 *   - grava a nova frequência / quantidade / dias no atendimento;
 *   - pega as sessões PENDENTES de hoje em diante (as realizadas, enviadas ou
 *     aprovadas não mudam);
 *   - cria as sessões que faltarem para chegar na nova quantidade;
 *   - recalcula as datas das pendentes a partir da data de início informada.
 *
 * NÃO abre transação nem roda DDL: chame dentro da transação do chamador.
 */

/** Frequências que usam dia do mês (em vez de dia da semana). */
function assignment_schedule_is_monthly(string $frequency): bool
{
    return in_array($frequency, ['quinzenal', 'biweekly', 'mensal', 'monthly'], true);
}

/**
 * Lê a configuração mensal salva em patient_assignments.month_days.
 * Formatos: {"mode":"fixed_day","days":[5,20]} ou
 *           {"mode":"weekday_position","pairs":[{"pos":"1","weekday":4}]}.
 *
 * @return array{mode:string,days:int[],pairs:array<int,array{pos:string,weekday:int}>}|null
 */
function assignment_schedule_month_cfg_from_json(?string $json): ?array
{
    $json = trim((string)$json);
    if ($json === '') {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    // Formato antigo: lista simples de dias.
    if (array_is_list($data)) {
        $days = array_values(array_filter(array_map('intval', $data), static fn($d) => $d >= 1 && $d <= 31));
        return $days ? ['mode' => 'fixed_day', 'days' => $days, 'pairs' => []] : null;
    }
    if (($data['mode'] ?? '') === 'weekday_position' && !empty($data['pairs']) && is_array($data['pairs'])) {
        $pairs = [];
        foreach ($data['pairs'] as $p) {
            if (isset($p['pos'], $p['weekday'])) {
                $pairs[] = ['pos' => (string)$p['pos'], 'weekday' => (int)$p['weekday']];
            }
        }
        return $pairs ? ['mode' => 'weekday_position', 'days' => [], 'pairs' => $pairs] : null;
    }
    $days = array_values(array_filter(array_map('intval', (array)($data['days'] ?? [])), static fn($d) => $d >= 1 && $d <= 31));
    return $days ? ['mode' => 'fixed_day', 'days' => $days, 'pairs' => []] : null;
}

/**
 * Aplica a nova frequência e remarca as sessões pendentes futuras.
 *
 * @param array $assignment   linha de patient_assignments (precisa de id, patient_id,
 *                            professional_user_id, session_quantity)
 * @param int[] $weekdays     dias da semana (1=seg..7=dom) para frequências semanais
 * @param array|null $monthCfg configuração mensal (ver assignment_schedule_month_cfg_from_json)
 * @param DateTime|null $startDate início da nova agenda (padrão: hoje)
 * @return array{pending:int, created:int, dated:int, dates:string[]}
 */
function assignment_reschedule(
    PDO $db,
    array $assignment,
    string $newFrequency,
    int $isIndefinite,
    int $newSessionQty,
    array $weekdays,
    ?array $monthCfg,
    ?DateTime $startDate = null
): array {
    $assignmentId = (int)$assignment['id'];
    $patientId = (int)$assignment['patient_id'];
    $oldSessionQty = (int)($assignment['session_quantity'] ?? 0);
    $isMonthlyFreq = assignment_schedule_is_monthly($newFrequency);
    $newWeekdays = array_values(array_unique(array_map('intval', $weekdays)));

    // Atendimento: frequência, quantidade e dias de referência.
    $weekdaysJson = (!$isMonthlyFreq && count($newWeekdays) > 0) ? json_encode($newWeekdays) : null;
    $monthDaysJson = null;
    if ($isMonthlyFreq && $monthCfg !== null) {
        if ($monthCfg['mode'] === 'weekday_position' && !empty($monthCfg['pairs'])) {
            $monthDaysJson = json_encode(['mode' => 'weekday_position', 'pairs' => array_values($monthCfg['pairs'])]);
        } elseif (!empty($monthCfg['days'])) {
            $monthDaysJson = json_encode(['mode' => 'fixed_day', 'days' => array_values($monthCfg['days'])]);
        }
    }
    // Em tempo indeterminado, não fixa a quantidade (mantém a existente como referência).
    $qtyToSave = $isIndefinite ? $oldSessionQty : ($newSessionQty > 0 ? $newSessionQty : $oldSessionQty);
    $db->prepare('UPDATE patient_assignments SET session_frequency = :freq, session_quantity = :qty, weekdays = :wd, month_days = :md, is_indefinite = :indef WHERE id = :id')
        ->execute([
            'freq' => $newFrequency,
            'qty' => $qtyToSave,
            'wd' => $weekdaysJson,
            'md' => $monthDaysJson,
            'indef' => $isIndefinite,
            'id' => $assignmentId,
        ]);

    // Sessões pendentes de hoje em diante (as passadas/realizadas ficam como estão).
    $stmtPending = $db->prepare(
        "SELECT id, session_number FROM billing_document_requirements
         WHERE assignment_id = :aid AND status = 'pending' AND (session_date IS NULL OR session_date >= CURDATE())
         ORDER BY session_number ASC"
    );
    $stmtPending->execute(['aid' => $assignmentId]);
    $pendingSessions = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

    // Quantidade ALVO de sessões futuras.
    // - Tempo determinado: a nova quantidade informada (ou mantém as pendentes).
    // - Tempo indeterminado: mantém as pendentes; se não houver, cria um lote inicial (12).
    if ($isIndefinite) {
        $targetQty = count($pendingSessions) > 0 ? count($pendingSessions) : 12;
    } else {
        $targetQty = $newSessionQty > 0 ? $newSessionQty : count($pendingSessions);
    }

    $maxNumStmt = $db->prepare('SELECT COALESCE(MAX(session_number), 0) FROM billing_document_requirements WHERE assignment_id = :aid');
    $maxNumStmt->execute(['aid' => $assignmentId]);
    $maxSessionNumber = (int)$maxNumStmt->fetchColumn();

    $created = 0;
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
                $insSession->execute(['aid' => $assignmentId, 'pid' => $patientId, 'puid' => $profUserId, 'num' => $maxSessionNumber]);
                $created++;
            } catch (Throwable $e) { /* ignora duplicidade */ }
        }
        $stmtPending->execute(['aid' => $assignmentId]);
        $pendingSessions = $stmtPending->fetchAll(PDO::FETCH_ASSOC);
    }

    $newDates = [];
    if (count($pendingSessions) > 0) {
        $today = new DateTime('today');
        $start = $startDate !== null && $startDate > $today ? clone $startDate : $today;
        $startYmd = $start->format('Y-m-d');
        $needed = count($pendingSessions);

        if ($isMonthlyFreq && $monthCfg !== null) {
            $cursorMonth = (int)$start->format('n');
            $cursorYear = (int)$start->format('Y');
            $safety = 0;

            if ($monthCfg['mode'] === 'weekday_position' && !empty($monthCfg['pairs'])) {
                // Posição + dia da semana (ex.: "1ª quinta-feira do mês").
                while (count($newDates) < $needed && $safety < 400) {
                    $safety++;
                    foreach ($monthCfg['pairs'] as $pair) {
                        if (count($newDates) >= $needed) {
                            break;
                        }
                        $pos = (string)$pair['pos']; // '1','2','3','4','last'
                        $wday = (int)$pair['weekday']; // 1=seg..7=dom
                        $candidate = null;
                        $daysInMonth = (int)date('t', mktime(0, 0, 0, $cursorMonth, 1, $cursorYear));
                        if ($pos === 'last') {
                            for ($dd = $daysInMonth; $dd >= 1; $dd--) {
                                $dt = mktime(0, 0, 0, $cursorMonth, $dd, $cursorYear);
                                if ((int)date('N', $dt) === $wday) { $candidate = date('Y-m-d', $dt); break; }
                            }
                        } else {
                            $nth = (int)$pos;
                            $count = 0;
                            for ($dd = 1; $dd <= $daysInMonth; $dd++) {
                                $dt = mktime(0, 0, 0, $cursorMonth, $dd, $cursorYear);
                                if ((int)date('N', $dt) === $wday) {
                                    $count++;
                                    if ($count === $nth) { $candidate = date('Y-m-d', $dt); break; }
                                }
                            }
                        }
                        if ($candidate !== null && $candidate >= $startYmd) {
                            $newDates[] = $candidate;
                        }
                    }
                    $cursorMonth++;
                    if ($cursorMonth > 12) { $cursorMonth = 1; $cursorYear++; }
                }
            } elseif (!empty($monthCfg['days'])) {
                // Dia fixo do mês (ex.: todo dia 25).
                $monthDays = $monthCfg['days'];
                sort($monthDays);
                while (count($newDates) < $needed && $safety < 400) {
                    $safety++;
                    $daysInMonth = (int)date('t', mktime(0, 0, 0, $cursorMonth, 1, $cursorYear));
                    foreach ($monthDays as $dm) {
                        if ($dm > $daysInMonth) {
                            continue;
                        }
                        $candidate = sprintf('%04d-%02d-%02d', $cursorYear, $cursorMonth, $dm);
                        if ($candidate >= $startYmd && count($newDates) < $needed) {
                            $newDates[] = $candidate;
                        }
                    }
                    $cursorMonth++;
                    if ($cursorMonth > 12) { $cursorMonth = 1; $cursorYear++; }
                }
            }
        } elseif (!$isMonthlyFreq && count($newWeekdays) > 0) {
            // Semanal/diária: distribui nos dias da semana fixos.
            $currentDate = clone $start;
            sort($newWeekdays);
            while (count($newDates) < $needed) {
                $dayOfWeek = (int)$currentDate->format('N');
                if (in_array($dayOfWeek, $newWeekdays, true)) {
                    $newDates[] = $currentDate->format('Y-m-d');
                }
                $currentDate->modify('+1 day');
                if ($currentDate->diff($start)->days > 365) {
                    break;
                }
            }
        }

        if (count($newDates) > 0) {
            $updDate = $db->prepare('UPDATE billing_document_requirements SET session_date = :sd WHERE id = :id');
            foreach ($pendingSessions as $idx => $sess) {
                $updDate->execute(['sd' => $newDates[$idx] ?? null, 'id' => (int)$sess['id']]);
            }
        }
    }

    return [
        'pending' => count($pendingSessions),
        'created' => $created,
        'dated' => min(count($newDates), count($pendingSessions)),
        'dates' => array_slice($newDates, 0, count($pendingSessions)),
    ];
}

/**
 * Dias da semana a usar na remarcação: os já escolhidos no atendimento (se a
 * frequência não mudou) ou os da tabela padrão da frequência.
 *
 * @return int[]
 */
function assignment_schedule_weekdays_for(string $frequency, ?string $currentFrequency, ?string $currentWeekdaysJson): array
{
    if ($frequency === (string)$currentFrequency) {
        $cur = json_decode((string)$currentWeekdaysJson, true);
        if (is_array($cur)) {
            $cur = array_values(array_filter(array_map('intval', $cur), static fn($d) => $d >= 1 && $d <= 7));
            if ($cur) {
                return $cur;
            }
        }
    }
    return frequency_get_weekdays($frequency);
}
