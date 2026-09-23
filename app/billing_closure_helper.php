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

    $closureFilter = $includeAlreadyClosed ? '' : ' AND bdr.monthly_closure_id IS NULL';

    $params = ['first' => $first, 'last' => $last];
    $extraWhere = '';

    // Filtros opcionais (Fechamento Mensal): paciente, operadora, profissional, operador.
    if (!empty($filters['patient_q'])) {
        $extraWhere .= ' AND p.full_name LIKE :patient_q';
        $params['patient_q'] = '%' . $filters['patient_q'] . '%';
    }
    if (!empty($filters['insurer_id'])) {
        $extraWhere .= ' AND pa.health_insurer_id = :insurer_id';
        $params['insurer_id'] = (int)$filters['insurer_id'];
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
            pa.specialty,
            COALESCE(pa.authorized_value, pa.payment_value, 0) AS receivable_per_session,
            COALESCE(pa.agreed_value, pa.payment_value, 0) AS payable_per_session,
            p.full_name AS patient_name,
            u.name AS professional_name,
            op.name AS operator_name,
            hi.name AS insurer_name
        FROM billing_document_requirements bdr
        INNER JOIN patient_assignments pa ON pa.id = bdr.assignment_id
        INNER JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN users u ON u.id = pa.professional_user_id
        LEFT JOIN users op ON op.id = bdr.created_by_user_id
        LEFT JOIN health_insurers hi ON hi.id = pa.health_insurer_id
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
            ];
        }

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
