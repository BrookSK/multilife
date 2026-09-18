<?php

declare(strict_types=1);

/**
 * Insurer Document Types Helper
 *
 * Taxonomia dos TIPOS de documento das operadoras (health_insurer_documents.doc_type).
 *
 * Organização definida na operação (reunião 15/09):
 *   Operadora → Especialidade → Tipo de documento
 * Exemplo: Unimed → Fisioterapia → Avaliação / Relatório gerencial
 *
 * "Avaliação" e "Relatório gerencial" são os tipos CANÔNICOS/PRINCIPAIS.
 * Os demais tipos (Manual, Formulário, etc.) são LEGADOS: mantidos por
 * compatibilidade com documentos já cadastrados, sem quebrar o histórico.
 *
 * A coluna doc_type é VARCHAR (texto). Aqui o valor persistido é o próprio
 * label (ex.: "Avaliação"), o que mantém retrocompatibilidade com os dados
 * existentes e com a exibição atual da página pública do profissional.
 */

/**
 * Tipos principais (novos), na ordem em que devem ser exibidos por especialidade.
 */
const INSURER_DOC_TYPES_PRIMARY = [
    'Avaliação',
    'Relatório gerencial',
];

/**
 * Tipos legados, mantidos apenas por compatibilidade com documentos antigos.
 * Não são removidos para não descartar o histórico existente.
 */
const INSURER_DOC_TYPES_LEGACY = [
    'Manual',
    'Formulário',
    'Termo',
    'Tabela de valores',
    'Instrução',
];

/**
 * Rótulo usado quando o documento não tem tipo definido.
 */
const INSURER_DOC_TYPE_FALLBACK_LABEL = 'Documentos';

/**
 * Rótulo usado quando o documento não tem especialidade definida.
 */
const INSURER_DOC_SPECIALTY_FALLBACK_LABEL = 'Geral';

/**
 * Lista completa de tipos conhecidos (principais + legados), sem duplicatas.
 *
 * @return string[]
 */
function insurer_doc_types_all(): array
{
    return array_values(array_unique(array_merge(
        INSURER_DOC_TYPES_PRIMARY,
        INSURER_DOC_TYPES_LEGACY
    )));
}

/**
 * Normaliza um valor de tipo de documento vindo do formulário.
 *
 * Regras:
 * - vazio → null (documento sem tipo, cai no fallback "Documentos" na exibição);
 * - se casar (case-insensitive) com um tipo conhecido, retorna o label canônico;
 * - caso contrário, retorna o texto informado (limitado a 120 chars), preservando
 *   tipos legados/livres já existentes sem descartá-los.
 */
function insurer_doc_type_normalize(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $needle = mb_strtolower($value);
    foreach (insurer_doc_types_all() as $known) {
        if (mb_strtolower($known) === $needle) {
            return $known; // devolve na grafia canônica
        }
    }

    return mb_substr($value, 0, 120);
}

/**
 * Peso de ordenação de um tipo de documento para exibição.
 * Tipos principais primeiro (na ordem definida), depois legados, depois o resto.
 */
function insurer_doc_type_sort_weight(?string $type): int
{
    $type = trim((string)$type);
    if ($type === '') {
        // Sem tipo definido: exibir por último dentro da especialidade.
        return 9000;
    }

    $needle = mb_strtolower($type);

    foreach (INSURER_DOC_TYPES_PRIMARY as $i => $primary) {
        if (mb_strtolower($primary) === $needle) {
            return $i; // 0, 1, ...
        }
    }

    foreach (INSURER_DOC_TYPES_LEGACY as $i => $legacy) {
        if (mb_strtolower($legacy) === $needle) {
            return 1000 + $i;
        }
    }

    // Tipo livre desconhecido: depois dos conhecidos, mas antes do "sem tipo".
    return 5000;
}

/**
 * Opções de tipo de documento para popular selects no admin.
 * Retorna grupos: principais e legados.
 *
 * @return array{primary: string[], legacy: string[]}
 */
function insurer_doc_type_options(): array
{
    return [
        'primary' => INSURER_DOC_TYPES_PRIMARY,
        'legacy'  => INSURER_DOC_TYPES_LEGACY,
    ];
}
