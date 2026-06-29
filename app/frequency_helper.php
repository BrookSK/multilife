<?php

declare(strict_types=1);

/**
 * Frequency Helper
 * 
 * Tabela de padronização dos dias de atendimento/sessões.
 * Regras definidas pela operação:
 * - 1x/Semana = 4ª Feira (conta quantas quartas tem o mês)
 * - 2x/Semana = 3ª e 5ª Feira (conta quantas terças e quintas tem o mês)
 * - 3x/Semana = 2ª, 4ª e 6ª Feira
 * - 4x/Semana = 2ª, 3ª, 4ª e 5ª Feira
 * - 5x/Semana = 2ª a 6ª Feira
 * - 6x/Semana = 2ª a Sábado
 * - 7x/Semana = Diário
 * - Quinzenal = 1 na 1ª quinzena + 1 na 2ª quinzena
 * - Mensal = 1x/Mês
 */

/**
 * Mapeamento estático de frequência → dias da semana
 * 1=Segunda, 2=Terça, 3=Quarta, 4=Quinta, 5=Sexta, 6=Sábado, 7=Domingo
 */
const FREQUENCY_WEEKDAYS_MAP = [
    '1x_semana' => [3],              // Quarta
    '2x_semana' => [2, 4],           // Terça, Quinta
    '3x_semana' => [1, 3, 5],        // Segunda, Quarta, Sexta
    '4x_semana' => [1, 2, 3, 4],     // Segunda, Terça, Quarta, Quinta
    '5x_semana' => [1, 2, 3, 4, 5],  // Segunda a Sexta
    '6x_semana' => [1, 2, 3, 4, 5, 6], // Segunda a Sábado
    '7x_semana' => [1, 2, 3, 4, 5, 6, 7], // Diário
    'quinzenal' => [],                // 2x mês (sem dia fixo da semana)
    'mensal'    => [],                // 1x mês (sem dia fixo da semana)
];

/**
 * Labels amigáveis para cada frequência
 */
const FREQUENCY_LABELS = [
    '1x_semana' => '1x/Semana',
    '2x_semana' => '2x/Semana',
    '3x_semana' => '3x/Semana',
    '4x_semana' => '4x/Semana',
    '5x_semana' => '5x/Semana',
    '6x_semana' => '6x/Semana',
    '7x_semana' => '7x/Semana (Diário)',
    'quinzenal' => 'Quinzenal (2x/Mês)',
    'mensal'    => 'Mensal (1x/Mês)',
];

/**
 * Dias da semana por extenso
 */
const WEEKDAY_NAMES = [
    1 => 'Segunda',
    2 => 'Terça',
    3 => 'Quarta',
    4 => 'Quinta',
    5 => 'Sexta',
    6 => 'Sábado',
    7 => 'Domingo',
];

const WEEKDAY_SHORT = [
    1 => 'Seg',
    2 => 'Ter',
    3 => 'Qua',
    4 => 'Qui',
    5 => 'Sex',
    6 => 'Sáb',
    7 => 'Dom',
];

/**
 * Retorna os dias da semana para uma dada frequência.
 * 
 * @param string $frequencyCode Código da frequência (ex: '3x_semana')
 * @return int[] Array de dias (1=Seg..7=Dom), vazio se quinzenal/mensal
 */
function frequency_get_weekdays(string $frequencyCode): array
{
    return FREQUENCY_WEEKDAYS_MAP[$frequencyCode] ?? [];
}

/**
 * Retorna o label amigável de uma frequência.
 */
function frequency_get_label(string $frequencyCode): string
{
    return FREQUENCY_LABELS[$frequencyCode] ?? $frequencyCode;
}

/**
 * Retorna a descrição dos dias da semana para exibição.
 * Ex: "2ª, 4ª e 6ª Feira"
 */
function frequency_get_weekday_description(string $frequencyCode): string
{
    $days = FREQUENCY_WEEKDAYS_MAP[$frequencyCode] ?? [];
    
    if (empty($days)) {
        if ($frequencyCode === 'quinzenal') return '2x/Mês (1ª e 2ª quinzena)';
        if ($frequencyCode === 'mensal') return '1x/Mês';
        return '-';
    }
    
    if (count($days) === 7) return 'Diário';
    
    $names = array_map(fn(int $d) => WEEKDAY_SHORT[$d] ?? '', $days);
    
    if (count($names) === 1) return $names[0];
    
    $last = array_pop($names);
    return implode(', ', $names) . ' e ' . $last;
}

/**
 * Calcula a quantidade de sessões em um mês específico com base na frequência.
 * Conta quantas ocorrências dos dias definidos existem no mês.
 * 
 * @param string $frequencyCode Código da frequência
 * @param int $year Ano
 * @param int $month Mês (1-12)
 * @return int Número de sessões no mês
 */
function frequency_count_sessions_in_month(string $frequencyCode, int $year, int $month): int
{
    if ($frequencyCode === 'quinzenal') return 2;
    if ($frequencyCode === 'mensal') return 1;
    
    $days = FREQUENCY_WEEKDAYS_MAP[$frequencyCode] ?? [];
    if (empty($days)) return 0;
    
    $count = 0;
    $daysInMonth = (int)(new DateTime("$year-$month-01"))->format('t');
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dt = new DateTime("$year-$month-$day");
        $dow = (int)$dt->format('N'); // 1=Seg..7=Dom
        if (in_array($dow, $days, true)) {
            $count++;
        }
    }
    
    return $count;
}

/**
 * Gera as datas de sessão para um período específico com base na frequência.
 * 
 * @param string $frequencyCode Código da frequência
 * @param DateTime $startDate Data de início
 * @param int $totalSessions Total de sessões a gerar
 * @return DateTime[] Array de datas das sessões
 */
function frequency_generate_session_dates(string $frequencyCode, DateTime $startDate, int $totalSessions): array
{
    $dates = [];
    $days = FREQUENCY_WEEKDAYS_MAP[$frequencyCode] ?? [];
    
    if ($frequencyCode === 'quinzenal') {
        // 1 na primeira quinzena, 1 na segunda quinzena
        $current = clone $startDate;
        while (count($dates) < $totalSessions) {
            $dayOfMonth = (int)$current->format('j');
            if ($dayOfMonth <= 15) {
                // Primeira quinzena - usar dia atual ou primeiro dia útil
                $dates[] = clone $current;
                // Pular para segunda quinzena
                $current->setDate((int)$current->format('Y'), (int)$current->format('n'), 16);
            } else {
                // Segunda quinzena
                $dates[] = clone $current;
                // Pular para próximo mês, primeira quinzena
                $current->modify('first day of next month');
            }
        }
        return $dates;
    }
    
    if ($frequencyCode === 'mensal') {
        // 1x por mês, mesmo dia (ou próximo disponível)
        $current = clone $startDate;
        while (count($dates) < $totalSessions) {
            $dates[] = clone $current;
            $current->modify('+1 month');
        }
        return $dates;
    }
    
    if (empty($days)) return $dates;
    
    // Para frequências semanais: avançar dia a dia e pegar os que caem nos dias definidos
    $current = clone $startDate;
    sort($days);
    
    while (count($dates) < $totalSessions) {
        $dow = (int)$current->format('N');
        if (in_array($dow, $days, true)) {
            $dates[] = clone $current;
        }
        $current->modify('+1 day');
    }
    
    return $dates;
}

/**
 * Converte frequência texto livre (legado) para o código padronizado.
 * Ex: "3x por semana", "3 vezes", "três vezes" → "3x_semana"
 */
function frequency_normalize(string $input): string
{
    $input = mb_strtolower(trim($input));
    
    if ($input === '' || $input === '-') return '';
    
    // Já é um código válido
    if (isset(FREQUENCY_WEEKDAYS_MAP[$input])) return $input;
    
    // Quinzenal
    if (str_contains($input, 'quinzenal') || str_contains($input, '2x/m') || str_contains($input, '2x mes') || str_contains($input, '2x mês')) {
        return 'quinzenal';
    }
    
    // Mensal
    if (str_contains($input, 'mensal') || str_contains($input, '1x/m') || str_contains($input, '1x mes') || str_contains($input, '1x mês')) {
        return 'mensal';
    }
    
    // Diário
    if (str_contains($input, 'diário') || str_contains($input, 'diario') || str_contains($input, '7x')) {
        return '7x_semana';
    }
    
    // Extrair número
    $numbers = ['um' => 1, 'uma' => 1, 'dois' => 2, 'duas' => 2, 'três' => 3, 'tres' => 3,
                'quatro' => 4, 'cinco' => 5, 'seis' => 6, 'sete' => 7];
    
    foreach ($numbers as $word => $num) {
        if (str_contains($input, $word)) {
            return $num . 'x_semana';
        }
    }
    
    // Tentar extrair dígito
    if (preg_match('/(\d+)\s*x/', $input, $m)) {
        $n = (int)$m[1];
        if ($n >= 1 && $n <= 7) return $n . 'x_semana';
    }
    
    if (preg_match('/(\d+)\s*vez/', $input, $m)) {
        $n = (int)$m[1];
        if ($n >= 1 && $n <= 7) return $n . 'x_semana';
    }
    
    return '';
}

/**
 * Retorna todas as opções de frequência para uso em selects/dropdowns.
 * @return array [['code' => '...', 'label' => '...', 'weekdays' => [...], 'description' => '...'], ...]
 */
function frequency_get_options(): array
{
    $options = [];
    foreach (FREQUENCY_LABELS as $code => $label) {
        $options[] = [
            'code' => $code,
            'label' => $label,
            'weekdays' => FREQUENCY_WEEKDAYS_MAP[$code],
            'description' => frequency_get_weekday_description($code),
        ];
    }
    return $options;
}
