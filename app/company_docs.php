<?php

declare(strict_types=1);

/**
 * Documentação interna da empresa (arquivos .md em docs/) exibida na aba
 * "Empresa" da Gestão Documental. Só os arquivos listados aqui podem ser
 * abertos (lista fixa: nunca monta caminho a partir do que vem na URL).
 */
function company_system_docs(): array
{
    return [
        'tecnica' => [
            'file' => 'DOCUMENTACAO_TECNICA.md',
            'title' => 'Documentação técnica de entrega',
            'description' => 'Fluxos internos, regras automáticas, rotinas, configurações e diagnóstico.',
        ],
        'processos' => [
            'file' => 'MultiLife_Care_Processos_v1.0.md',
            'title' => 'Processos operacionais',
            'description' => 'Descrição dos processos da operação MultiLife Care.',
        ],
    ];
}

/** Caminho absoluto do documento (ou null se não existir). */
function company_system_doc_path(string $key): ?string
{
    $docs = company_system_docs();
    if (!isset($docs[$key])) {
        return null;
    }
    $path = dirname(__DIR__) . '/docs/' . $docs[$key]['file'];
    return is_file($path) ? $path : null;
}
