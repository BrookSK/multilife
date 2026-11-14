<?php

declare(strict_types=1);

/**
 * Billing Monthly Closure Helper
 *
 * Consolidação do faturamento mensal por paciente (reunião 15/09).
 *
 * Fonte dos "atendimentos" do mês: billing_document_requirements (cada linha = uma
 * sessão), filtrando por session_date dentro da competência, status aprovado e
 * ainda NÃO faturada (monthly_closure_id IS NULL) — evita dupla contagem.
 *
 * Valores por sessão (patient_assignments):
 *   - authorized_value = valor a RECEBER da operadora (receita)
 *   - agreed_value     = valor a PAGAR ao profissional (despesa)
 */

/** Status de sessão que conta como "realizada e conferida" para faturar. */
const BILLING_CLOSURE_SESSION_STATUSES = ['approved'];

/**
 * Valida e normaliza uma competência no formato YYYY-MM.
 * Retorna a string normalizada ou null se inválida.
 */
function billing_closure_normalize_month(?string $month): ?string
{
    $month = trim((string)$month);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return null;
    }
    [$y, $m] = array_map('intval', explode('-', $month));
    if ($m < 1 || $m > 12 || $y < 2000 || $y > 2100) {
        return null;
    }
    return sprintf('%04d-%02d', $y, $m);
}

/**
 * Retorna [primeiroDia, ultimoDia] (YYYY-MM-DD) de uma competência YYYY-MM.
 *
 * @return array{0:string,1:string}
 */
function billing_closure_month_range(string $month): array
{
    $first = $month . '-01';
    $last = date('Y-m-t', strtotime($first));
    return [$first, $last];
}

/**
 * Placeholders SQL (IN) para os status de sessão faturáveis.
 * Retorna algo como "'approved'" (já escapado por serem constantes internas).
 */
function billing_closure_status_in_sql(): string
{
    $quoted = array_map(static fn(string $s): string => "'" . $s . "'", BILLING_CLOSURE_SESSION_STATUSES);
    return implode(',', $quoted);
}

/**
 * Busca as SESSÕES faturáveis de uma competência (linha a linha), já com os
 * valores e vínculos necessários. Não altera nada no banco.
 *
 * @param PDO $db
 * @param string $month Competência YYYY-MM (deve estar normalizada).
 * @param bool $includeAlreadyClosed Se true, ignora o filtro monthly_closure_id
 *        (usado apenas para inspeção; o fechamento sempre usa false).
 * @return array<int,array<string,mixed>>
 */
function billing_closure_fetch_sessions(PDO $db, string $month, bool $includeAlreadyClosed = false, array $filters = []): array
{
    [$first, $last] = billing_closure_month_range($month);
    $statusIn = billing_closure_status_in_sql();

    // Garante polo_id / internal_notes / weaning_at (idempotente; fora de transação).
    if (function_exists('clients_ensure_schema')) {
        clients_ensure_schema();
    }

    $closureFilter = $includeAlreadyClosed ? '' : ' AND bdr.monthly_closure_id IS NULL';

    $params = ['first' => $first, 'last' => $last];
    $extraWhere = '';

    // Cliente e polo EFETIVOS do atendimento: o que estiver no atendimento ou,
    // se vazio, o da operadora. (Antes o filtro usava só pa.client_id e não
    // achava atendimentos cujo cliente vinha apenas da operadora.)
    $clientExpr = 'COALESCE(pa.client_id, hi.client_id)';
    $poloExpr = 'COALESCE(pa.polo_id, hi.polo_id)';

    // Filtros opcionais (Fechamento Mensal).
    $patientQ = trim((string)($filters['patient_q'] ?? ''));
    if ($patientQ !== '') {
        // "\" é o escape padrão do LIKE no MySQL/MariaDB.
        $extraWhere .= ' AND p.full_name LIKE :patient_q';
        $params['patient_q'] = '%' . addcslashes($patientQ, '%_\\') . '%';
    }
    if (!empty($filters['insurer_id'])) {
        if ((int)$filters['insurer_id'] === -1) {
            $extraWhere .= ' AND pa.health_insurer_id IS NULL';
        } else {
            $extraWhere .= ' AND pa.health_insurer_id = :insurer_id';
            $params['insurer_id'] = (int)$filters['insurer_id'];
        }
    }
    if (!empty($filters['client_id'])) {
        if ((int)$filters['client_id'] === -1) {
            $extraWhere .= " AND $clientExpr IS NULL";
        } else {
            $extraWhere .= " AND $clientExpr = :client_id";
            $params['client_id'] = (int)$filters['client_id'];
        }
    }
    if (!empty($filters['polo_id'])) {
        if ((int)$filters['polo_id'] === -1) {
            $extraWhere .= " AND $poloExpr IS NULL";
        } else {
            $extraWhere .= " AND $poloExpr = :polo_id";
            $params['polo_id'] = (int)$filters['polo_id'];
        }
    }
    $specialtyF = trim((string)($filters['specialty'] ?? ''));
    if ($specialtyF !== '') {
        $extraWhere .= ' AND TRIM(pa.specialty) = :specialty';
        $params['specialty'] = $specialtyF;
    }
    $frequencyF = trim((string)($filters['frequency'] ?? ''));
    if ($frequencyF !== '') {
        $extraWhere .= ' AND pa.session_frequency = :frequency';
        $params['frequency'] = $frequencyF;
    }
    if (!empty($filters['professional_id'])) {
        $extraWhere .= ' AND pa.professional_user_id = :professional_id';
        $params['professional_id'] = (int)$filters['professional_id'];
    }
    if (!empty($filters['operator_id'])) {
        $extraWhere .= ' AND bdr.created_by_user_id = :operator_id';
        $params['operator_id'] = (int)$filters['operator_id'];
    }

    $sql = "
        SELECT
            bdr.id AS requirement_id,
            bdr.session_date,
            bdr.session_number,
            bdr.assignment_id,
            bdr.is_manual,
            bdr.created_by_user_id,
            pa.patient_id,
            pa.professional_user_id,
            pa.health_insurer_id,
            $clientExpr AS client_id,
            $poloExpr AS polo_id,
            pa.specialty,
            pa.session_frequency,
            pa.session_quantity,
            pa.is_indefinite,
            pa.admitted_at,
            pa.ended_at,
            pa.weaning_at,
            pa.internal_notes,
            pa.weekdays,
            pa.month_days,
            COALESCE(pa.authorized_value, pa.payment_value, 0) AS receivable_per_session,
            COALESCE(pa.agreed_value, pa.payment_value, 0) AS payable_per_session,
            p.full_name AS patient_name,
            u.name AS professional_name,
            op.name AS operator_name,
            hi.name AS insurer_name,
            cl.name AS client_name,
            po.name AS polo_name
        FROM billing_document_requirements bdr
        INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
        INNER JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN users u ON u.id = pa.professional_user_id
        LEFT JOIN users op ON op.id = bdr.created_by_user_id
        LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
        LEFT JOIN clients cl ON cl.id = $clientExpr
        LEFT JOIN polos po ON po.id = $poloExpr
        WHERE bdr.status IN ($statusIn)
          AND bdr.session_date IS NOT NULL
          AND bdr.session_date BETWEEN :first AND :last
          AND p.deleted_at IS NULL
          $closureFilter
          $extraWhere
        ORDER BY p.full_name ASC, u.name ASC, pa.specialty ASC, bdr.session_date ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Agrega as sessões por PACIENTE, e dentro de cada paciente por
 * profissional + especialidade (linhas de detalhe), calculando totais.
 *
 * Estrutura de retorno:
 * [
 *   patient_id => [
 *     'patient_id', 'patient_name',
 *     'insurers' => [insurer_id => insurer_name, ...],
 *     'total_sessions', 'total_receivable', 'total_payable',
 *     'lines' => [ ['professional_user_id','professional_name','specialty',
 *                    'insurer_name','sessions','receivable_per_session',
 *                    'payable_per_session','receivable','payable'], ... ],
 *   ], ...
 * ]
 *
 * @param array<int,array<string,mixed>> $sessions
 * @return array<int,array<string,mixed>>
 */
function billing_closure_group_by_patient(array $sessions): array
{
    $byPatient = [];

    foreach ($sessions as $s) {
        $pid = (int)$s['patient_id'];
        if (!isset($byPatient[$pid])) {
            $byPatient[$pid] = [
                'patient_id' => $pid,
                'patient_name' => (string)($s['patient_name'] ?? '-'),
                'insurers' => [],
                'total_sessions' => 0,
                'total_receivable' => 0.0,
                'total_payable' => 0.0,
                'lines' => [],
                'session_items' => [], // detalhamento sessão a sessão (para o pop-up)
            ];
        }

        // Guardar cada sessão individual para o detalhamento (pop-up).
        $byPatient[$pid]['session_items'][] = [
            'requirement_id' => (int)($s['requirement_id'] ?? 0),
            'session_number' => (int)($s['session_number'] ?? 0),
            'session_date' => (string)($s['session_date'] ?? ''),
            'professional_name' => (string)($s['professional_name'] ?? '-'),
            'specialty' => (string)($s['specialty'] ?? '-'),
            'insurer_name' => (string)($s['insurer_name'] ?? '') !== '' ? (string)$s['insurer_name'] : 'Sem operadora',
            'is_manual' => (int)($s['is_manual'] ?? 0),
            'operator_name' => (string)($s['operator_name'] ?? ''),
            'receivable' => (float)$s['receivable_per_session'],
            'payable' => (float)$s['payable_per_session'],
        ];

        $insurerId = $s['health_insurer_id'] !== null ? (int)$s['health_insurer_id'] : 0;
        $insurerName = (string)($s['insurer_name'] ?? '') !== '' ? (string)$s['insurer_name'] : 'Sem operadora';
        $byPatient[$pid]['insurers'][$insurerId] = $insurerName;

        // Chave de detalhe: profissional + especialidade.
        $profId = (int)($s['professional_user_id'] ?? 0);
        $specialty = (string)($s['specialty'] ?? '');
        $lineKey = $profId . '|' . $specialty;

        if (!isset($byPatient[$pid]['lines'][$lineKey])) {
            $byPatient[$pid]['lines'][$lineKey] = [
                'professional_user_id' => $profId,
                'professional_name' => (string)($s['professional_name'] ?? '-'),
                'specialty' => $specialty !== '' ? $specialty : '-',
                'insurer_name' => $insurerName,
                'sessions' => 0,
                'receivable_per_session' => (float)$s['receivable_per_session'],
                'payable_per_session' => (float)$s['payable_per_session'],
                'receivable' => 0.0,
                'payable' => 0.0,
                'sessions_detail' => [],
            ];
        }

        $recv = (float)$s['receivable_per_session'];
        $pay = (float)$s['payable_per_session'];

        $byPatient[$pid]['lines'][$lineKey]['sessions']++;
        $byPatient[$pid]['lines'][$lineKey]['receivable'] += $recv;
        $byPatient[$pid]['lines'][$lineKey]['payable'] += $pay;
        // Detalhe sessão-a-sessão (para o "ver detalhes")
        $byPatient[$pid]['lines'][$lineKey]['sessions_detail'][] = [
            'requirement_id' => (int)($s['requirement_id'] ?? 0),
            'session_date' => (string)($s['session_date'] ?? ''),
            'session_number' => (int)($s['session_number'] ?? 0),
            'is_manual' => (int)($s['is_manual'] ?? 0),
            'operator_name' => (string)($s['operator_name'] ?? ''),
        ];

        $byPatient[$pid]['total_sessions']++;
        $byPatient[$pid]['total_receivable'] += $recv;
        $byPatient[$pid]['total_payable'] += $pay;
    }

    return $byPatient;
}

/**
 * Totais gerais a partir do agrupamento por paciente.
 *
 * @param array<int,array<string,mixed>> $byPatient
 * @return array{patients:int,sessions:int,receivable:float,payable:float}
 */
function billing_closure_totals(array $byPatient): array
{
    $t = ['patients' => 0, 'sessions' => 0, 'receivable' => 0.0, 'payable' => 0.0];
    foreach ($byPatient as $p) {
        $t['patients']++;
        $t['sessions'] += (int)$p['total_sessions'];
        $t['receivable'] += (float)$p['total_receivable'];
        $t['payable'] += (float)$p['total_payable'];
    }
    return $t;
}

/**
 * Retorna o registro de fechamento de uma competência, ou null.
 */
function billing_closure_find(PDO $db, string $month): ?array
{
    try {
        $stmt = $db->prepare('SELECT * FROM billing_monthly_closures WHERE reference_month = :m LIMIT 1');
        $stmt->execute(['m' => $month]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Agrupa as sessões por CLIENTE -> OPERADORA -> (paciente/profissional/especialidade).
 * Usado na tela de Fechamento Mensal (acompanhamento por quantidade) e na tela
 * financeira (com valores).
 *
 * Estrutura de retorno:
 * [
 *   client_id => [
 *     'client_id', 'client_name', 'total_sessions', 'total_receivable', 'total_payable',
 *     'operators' => [
 *        insurer_id => [
 *           'insurer_id', 'insurer_name', 'total_sessions', 'total_receivable',
 *           'total_payable', 'patients_count',
 *           'lines' => [ ['patient_name','professional_name','specialty','sessions',
 *                         'receivable','payable'], ... ],
 *        ], ...
 *     ],
 *   ], ...
 * ]
 *
 * @param array<int,array<string,mixed>> $sessions
 * @return array<int,array<string,mixed>>
 */
function billing_closure_group_by_client(array $sessions): array
{
    $byClient = [];

    foreach ($sessions as $s) {
        $clientId = $s['client_id'] !== null ? (int)$s['client_id'] : 0;
        $clientName = (string)($s['client_name'] ?? '') !== '' ? (string)$s['client_name'] : 'Sem cliente';
        $insurerId = $s['health_insurer_id'] !== null ? (int)$s['health_insurer_id'] : 0;
        $insurerName = (string)($s['insurer_name'] ?? '') !== '' ? (string)$s['insurer_name'] : 'Sem operadora';

        if (!isset($byClient[$clientId])) {
            $byClient[$clientId] = [
                'client_id' => $clientId,
                'client_name' => $clientName,
                'total_sessions' => 0,
                'total_receivable' => 0.0,
                'total_payable' => 0.0,
                'operators' => [],
            ];
        }
        if (!isset($byClient[$clientId]['operators'][$insurerId])) {
            $byClient[$clientId]['operators'][$insurerId] = [
                'insurer_id' => $insurerId,
                'insurer_name' => $insurerName,
                'total_sessions' => 0,
                'total_receivable' => 0.0,
                'total_payable' => 0.0,
                'patients' => [],
                'lines' => [],
            ];
        }

        $recv = (float)$s['receivable_per_session'];
        $pay = (float)$s['payable_per_session'];
        $pid = (int)$s['patient_id'];
        $profId = (int)($s['professional_user_id'] ?? 0);
        $specialty = trim((string)($s['specialty'] ?? ''));
        $assignmentId = (int)($s['assignment_id'] ?? 0);
        // Uma linha por ATENDIMENTO (assignment): cada linha tem seu PAD, datas,
        // polo e observação, e pode ser editada individualmente.
        $lineKey = $assignmentId . '|' . $pid . '|' . $profId . '|' . $specialty;

        $op =& $byClient[$clientId]['operators'][$insurerId];
        $op['patients'][$pid] = true;
        if (!isset($op['lines'][$lineKey])) {
            $op['lines'][$lineKey] = [
                'assignment_id' => $assignmentId,
                'patient_id' => $pid,
                'patient_name' => (string)($s['patient_name'] ?? '-'),
                'professional_user_id' => $profId,
                'professional_name' => (string)($s['professional_name'] ?? '-'),
                'specialty' => $specialty !== '' ? $specialty : '-',
                'specialty_raw' => $specialty,
                'health_insurer_id' => $s['health_insurer_id'] !== null ? (int)$s['health_insurer_id'] : null,
                'client_id' => $s['client_id'] !== null ? (int)$s['client_id'] : null,
                'polo_id' => isset($s['polo_id']) && $s['polo_id'] !== null ? (int)$s['polo_id'] : null,
                'polo_name' => (string)($s['polo_name'] ?? ''),
                'session_frequency' => (string)($s['session_frequency'] ?? ''),
                'session_quantity' => $s['session_quantity'] !== null ? (int)$s['session_quantity'] : null,
                'is_indefinite' => (int)($s['is_indefinite'] ?? 0),
                'admitted_at' => (string)($s['admitted_at'] ?? ''),
                'ended_at' => (string)($s['ended_at'] ?? ''),
                'weaning_at' => (string)($s['weaning_at'] ?? ''),
                'internal_notes' => (string)($s['internal_notes'] ?? ''),
                'weekdays' => (string)($s['weekdays'] ?? ''),
                'month_days' => (string)($s['month_days'] ?? ''),
                'sessions' => 0,
                'receivable' => 0.0,
                'payable' => 0.0,
                'sessions_detail' => [],
            ];
        }
        $op['lines'][$lineKey]['sessions']++;
        $op['lines'][$lineKey]['receivable'] += $recv;
        $op['lines'][$lineKey]['payable'] += $pay;
        // Detalhe sessão-a-sessão para o "ver detalhes".
        $op['lines'][$lineKey]['sessions_detail'][] = [
            'requirement_id' => (int)($s['requirement_id'] ?? 0),
            'session_date' => (string)($s['session_date'] ?? ''),
            'session_number' => (int)($s['session_number'] ?? 0),
            'is_manual' => (int)($s['is_manual'] ?? 0),
            'operator_name' => (string)($s['operator_name'] ?? ''),
        ];
        $op['total_sessions']++;
        $op['total_receivable'] += $recv;
        $op['total_payable'] += $pay;

        $byClient[$clientId]['total_sessions']++;
        $byClient[$clientId]['total_receivable'] += $recv;
        $byClient[$clientId]['total_payable'] += $pay;
        unset($op);
    }

    // Consolidar contagem de pacientes por operadora e montar o nível de POLO.
    foreach ($byClient as &$c) {
        foreach ($c['operators'] as &$o) {
            $o['patients_count'] = count($o['patients']);
            unset($o['patients']);
        }
        unset($o);
        $c['polos'] = billing_closure_split_by_polo($c['operators']);
    }
    unset($c);

    return $byClient;
}

/**
 * Reagrupa as operadoras de um cliente por POLO: cliente -> polo -> operadora.
 *
 * O polo vem de cada atendimento (linha). Como a mesma operadora pode atender
 * mais de um polo, ela pode aparecer em vários polos; 'polos_count' informa em
 * quantos polos do cliente a operadora aparece (o fechamento continua sendo por
 * operadora inteira no mês).
 *
 * Retorno: [polo_id => ['polo_id','polo_name','total_sessions','total_receivable',
 *   'total_payable','patients_count','operators' => [insurer_id => [... 'lines' ...]]]]
 * "Sem polo" (id 0) fica por último; os demais em ordem alfabética.
 *
 * @param array<int,array<string,mixed>> $operators
 * @return array<int,array<string,mixed>>
 */
function billing_closure_split_by_polo(array $operators): array
{
    $polos = [];
    $patientsByPolo = [];
    $patientsByPoloOp = [];
    $polosByOp = [];

    foreach ($operators as $insurerId => $o) {
        foreach ($o['lines'] as $lineKey => $line) {
            $poloId = (int)($line['polo_id'] ?? 0);
            $poloName = trim((string)($line['polo_name'] ?? ''));
            if (!isset($polos[$poloId])) {
                $polos[$poloId] = [
                    'polo_id' => $poloId,
                    'polo_name' => $poloId > 0 && $poloName !== '' ? $poloName : 'Sem polo',
                    'total_sessions' => 0,
                    'total_receivable' => 0.0,
                    'total_payable' => 0.0,
                    'patients_count' => 0,
                    'operators' => [],
                ];
            }
            if (!isset($polos[$poloId]['operators'][$insurerId])) {
                $polos[$poloId]['operators'][$insurerId] = [
                    'insurer_id' => (int)$o['insurer_id'],
                    'insurer_name' => (string)$o['insurer_name'],
                    'total_sessions' => 0,
                    'total_receivable' => 0.0,
                    'total_payable' => 0.0,
                    'patients_count' => 0,
                    'polos_count' => 1,
                    'lines' => [],
                ];
            }
            $po =& $polos[$poloId]['operators'][$insurerId];
            $po['lines'][$lineKey] = $line;
            $po['total_sessions'] += (int)$line['sessions'];
            $po['total_receivable'] += (float)$line['receivable'];
            $po['total_payable'] += (float)$line['payable'];
            unset($po);

            $polos[$poloId]['total_sessions'] += (int)$line['sessions'];
            $polos[$poloId]['total_receivable'] += (float)$line['receivable'];
            $polos[$poloId]['total_payable'] += (float)$line['payable'];

            $pid = (int)$line['patient_id'];
            $patientsByPolo[$poloId][$pid] = true;
            $patientsByPoloOp[$poloId][$insurerId][$pid] = true;
            $polosByOp[$insurerId][$poloId] = true;
        }
    }

    foreach ($polos as $poloId => &$p) {
        $p['patients_count'] = count($patientsByPolo[$poloId] ?? []);
        foreach ($p['operators'] as $insurerId => &$po) {
            $po['patients_count'] = count($patientsByPoloOp[$poloId][$insurerId] ?? []);
            $po['polos_count'] = count($polosByOp[$insurerId] ?? []);
        }
        unset($po);
    }
    unset($p);

    uasort($polos, static function (array $a, array $b): int {
        if (($a['polo_id'] === 0) !== ($b['polo_id'] === 0)) {
            return $a['polo_id'] === 0 ? 1 : -1;
        }
        return strnatcasecmp((string)$a['polo_name'], (string)$b['polo_name']);
    });

    return $polos;
}

/**
 * Busca os fechamentos (billing_monthly_closures) de uma competência,
 * indexados por chave de escopo. Permite saber o que já foi fechado.
 *
 * @return array{operators: array<int,array>, clients: array<int,array>}
 */
function billing_closure_scoped_status(PDO $db, string $month): array
{
    $result = ['operators' => [], 'clients' => []];
    try {
        $stmt = $db->prepare("SELECT * FROM billing_monthly_closures WHERE reference_month = :m");
        $stmt->execute(['m' => $month]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $scope = (string)($row['scope'] ?? 'global');
            if ($scope === 'operator' && $row['health_insurer_id'] !== null) {
                $result['operators'][(int)$row['health_insurer_id']] = $row;
            } elseif ($scope === 'client' && $row['client_id'] !== null) {
                $result['clients'][(int)$row['client_id']] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('[BILLING] scoped_status: ' . $e->getMessage());
    }
    return $result;
}

/**
 * Rótulo do PAD (frequência do atendimento), ex.: "2x/Semana · 8 sessões".
 */
function billing_closure_pad_label(?string $frequency, ?int $quantity = null, int $isIndefinite = 0): string
{
    $frequency = trim((string)$frequency);
    $label = $frequency !== '' ? frequency_get_label($frequency) : '';
    $extra = '';
    if ($isIndefinite === 1) {
        $extra = 'contínuo';
    } elseif ($quantity !== null && $quantity > 0) {
        $extra = $quantity . ' sess.';
    }
    if ($label === '' && $extra === '') {
        return '-';
    }
    return $label !== '' ? ($extra !== '' ? $label . ' · ' . $extra : $label) : $extra;
}

/**
 * Lê e normaliza os filtros do fechamento a partir de um array (GET/POST).
 * Valor -1 em cliente/operadora/polo significa "sem cliente/operadora/polo".
 *
 * @return array<string,mixed>
 */
function billing_closure_filters_from(array $src): array
{
    $int = static function ($v): int {
        $n = (int)$v;
        return $n === -1 ? -1 : max(0, $n);
    };
    return [
        'patient_q' => mb_substr(trim((string)($src['patient_q'] ?? '')), 0, 120),
        'client_id' => $int($src['client_id'] ?? 0),
        'polo_id' => $int($src['polo_id'] ?? 0),
        'insurer_id' => $int($src['insurer_id'] ?? 0),
        'professional_id' => max(0, (int)($src['professional_id'] ?? 0)),
        'specialty' => mb_substr(trim((string)($src['specialty'] ?? '')), 0, 120),
        'frequency' => preg_replace('/[^a-z0-9_]/', '', (string)($src['frequency'] ?? '')),
    ];
}

/**
 * Query string (sem "?") com competência + filtros ativos, para manter os
 * filtros em links e redirects.
 */
function billing_closure_filters_qs(string $month, array $filters): string
{
    $qs = ['month' => $month];
    foreach ($filters as $k => $v) {
        if ($v === '' || $v === 0 || $v === null) {
            continue;
        }
        $qs[$k] = $v;
    }
    return http_build_query($qs);
}

/**
 * True se algum filtro (além da competência) estiver ativo.
 */
function billing_closure_filters_active(array $filters): bool
{
    foreach ($filters as $v) {
        if ($v !== '' && $v !== 0 && $v !== null) {
            return true;
        }
    }
    return false;
}
