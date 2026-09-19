<?php

declare(strict_types=1);

/**
 * Attendance Resume Helper
 *
 * Regras de RETORNO de um atendimento que foi suspenso/finalizado (patient_assignments
 * com status 'completed' + end_reason_id).
 *
 * Regra operacional (reunião 15/09):
 *   - Retorno por INTERNAÇÃO / HOSPITALIZAÇÃO:
 *       • até 3 dias suspenso  -> volta para MONITORAMENTO (reativa o mesmo atendimento);
 *       • mais de 3 dias        -> volta para CAPTAÇÃO (cria NOVO card e reinicia o fluxo).
 *   - Qualquer OUTRO motivo de suspensão -> volta direto para MONITORAMENTO.
 *
 * A data-base para contar os dias é a data em que o atendimento foi finalizado como
 * hospitalização (patient_assignments.ended_at).
 */

/**
 * Slug do motivo de encerramento que representa internação/hospitalização.
 * Definido em treatment_end_reasons (migration 2026-09-18_0001).
 */
const RESUME_HOSPITALIZATION_SLUG = 'hospitalizacao';

/**
 * Limite (em dias) da hospitalização para retornar ao Monitoramento.
 * Até este valor (inclusive) -> Monitoramento; acima -> Captação.
 */
const RESUME_HOSPITALIZATION_MAX_DAYS = 3;

/** Destinos possíveis do retorno. */
const RESUME_DEST_MONITORING = 'monitoramento';
const RESUME_DEST_CAPTATION = 'captacao';

/**
 * Calcula quantos dias inteiros se passaram entre a data de suspensão e hoje.
 *
 * @param string|null $endedAt Data/hora da suspensão (patient_assignments.ended_at).
 * @param string|null $referenceDate Data de referência (default: agora). Formato aceito por strtotime.
 * @return int Número de dias (>= 0). Retorna 0 se a data for inválida/ausente.
 */
function resume_days_since(?string $endedAt, ?string $referenceDate = null): int
{
    $endedAt = trim((string)$endedAt);
    if ($endedAt === '') {
        return 0;
    }

    $endTs = strtotime($endedAt);
    if ($endTs === false) {
        return 0;
    }

    $refTs = $referenceDate !== null ? strtotime((string)$referenceDate) : time();
    if ($refTs === false) {
        $refTs = time();
    }

    // Comparar por DATA (ignora horas) para "dias suspensos" ser intuitivo.
    $endDay = strtotime(date('Y-m-d', $endTs));
    $refDay = strtotime(date('Y-m-d', $refTs));

    $diff = (int)floor(($refDay - $endDay) / 86400);
    return $diff > 0 ? $diff : 0;
}

/**
 * Indica se o motivo (slug de treatment_end_reasons) é internação/hospitalização.
 */
function resume_is_hospitalization(?string $reasonSlug): bool
{
    return mb_strtolower(trim((string)$reasonSlug)) === RESUME_HOSPITALIZATION_SLUG;
}

/**
 * Decide o destino do retorno de um atendimento suspenso.
 *
 * @param string|null $reasonSlug Slug do motivo de suspensão (treatment_end_reasons.slug).
 * @param string|null $endedAt Data/hora da suspensão (patient_assignments.ended_at).
 * @param string|null $referenceDate Data de referência opcional (default: agora).
 * @return array{destination: string, is_hospitalization: bool, days: int, max_days: int}
 */
function resume_decide_destination(?string $reasonSlug, ?string $endedAt, ?string $referenceDate = null): array
{
    $isHospitalization = resume_is_hospitalization($reasonSlug);
    $days = resume_days_since($endedAt, $referenceDate);

    // Só a hospitalização acima do limite vai para a Captação; todo o resto vai
    // para o Monitoramento.
    if ($isHospitalization && $days > RESUME_HOSPITALIZATION_MAX_DAYS) {
        $destination = RESUME_DEST_CAPTATION;
    } else {
        $destination = RESUME_DEST_MONITORING;
    }

    return [
        'destination' => $destination,
        'is_hospitalization' => $isHospitalization,
        'days' => $days,
        'max_days' => RESUME_HOSPITALIZATION_MAX_DAYS,
    ];
}
