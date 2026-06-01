<?php

declare(strict_types=1);

/**
 * Interface para provedores de validação de registros profissionais.
 *
 * Cada provedor (Consultar.IO, Infosimples, etc.) deve implementar esta interface.
 * Isso permite trocar ou adicionar provedores sem alterar regras de negócio.
 */
interface CouncilProviderInterface
{
    /**
     * Retorna o nome identificador do provedor.
     * Ex: "Consultar.IO", "Infosimples"
     */
    public function getName(): string;

    /**
     * Verifica se o provedor suporta o conselho informado.
     */
    public function supports(string $councilAbbr): bool;

    /**
     * Retorna a lista de conselhos suportados pelo provedor.
     * @return string[]
     */
    public function supportedCouncils(): array;

    /**
     * Verifica se o provedor está configurado (credenciais presentes).
     */
    public function isConfigured(): bool;

    /**
     * Executa a consulta de validação do registro profissional.
     *
     * @param string $councilAbbr  Sigla do conselho (CRP, CRN, COREN, CREFITO, CRM, CRO, CREA, OAB)
     * @param string $number       Número do registro
     * @param string $state        UF do conselho regional
     * @return array Resultado padronizado:
     *   [
     *     'success'         => bool,
     *     'valid'           => bool,
     *     'registry_type'   => string,
     *     'registry_number' => string,
     *     'name'            => ?string,
     *     'status'          => string,
     *     'state'           => string,
     *     'source'          => string,
     *     'consulted_at'    => string,
     *     'error'           => ?string,
     *   ]
     */
    public function validate(string $councilAbbr, string $number, string $state): array;

    /**
     * Retorna a prioridade do provedor (menor = maior prioridade).
     * Usado para ordenar provedores no sistema de fallback.
     */
    public function getPriority(): int;
}
