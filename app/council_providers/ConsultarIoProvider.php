<?php

declare(strict_types=1);

require_once __DIR__ . '/CouncilProviderInterface.php';
require_once __DIR__ . '/AbstractProvider.php';

/**
 * Provedor: Consultar.IO
 *
 * API de consulta de registros profissionais brasileiros.
 * Documentação: https://consultar.io/docs
 *
 * Configuração necessária (admin_settings):
 *   council_provider.consultario.api_key   — Chave de API
 *   council_provider.consultario.base_url  — URL base (padrão: https://api.consultar.io/v1)
 *   council_provider.consultario.enabled   — "1" para ativo
 *   council_provider.consultario.priority  — Prioridade numérica (padrão: 10)
 */
class ConsultarIoProvider extends AbstractProvider
{
    private const DEFAULT_BASE_URL = 'https://api.consultar.io/v1';

    private const COUNCIL_ENDPOINTS = [
        'CRP'     => '/conselhos/crp',
        'CRN'     => '/conselhos/crn',
        'COREN'   => '/conselhos/coren',
        'CREFITO' => '/conselhos/crefito',
        'CRM'     => '/conselhos/crm',
        'CRO'     => '/conselhos/cro',
        'CREA'    => '/conselhos/crea',
        'OAB'     => '/conselhos/oab',
    ];

    public function getName(): string
    {
        return 'Consultar.IO';
    }

    public function supports(string $councilAbbr): bool
    {
        return isset(self::COUNCIL_ENDPOINTS[strtoupper($councilAbbr)]);
    }

    public function supportedCouncils(): array
    {
        return array_keys(self::COUNCIL_ENDPOINTS);
    }

    public function isConfigured(): bool
    {
        $apiKey  = $this->getApiKey();
        $enabled = $this->getSetting('council_provider.consultario.enabled', '0');
        return $apiKey !== '' && $enabled === '1';
    }

    public function getPriority(): int
    {
        $p = $this->getSetting('council_provider.consultario.priority', '10');
        return (int)$p;
    }

    public function validate(string $councilAbbr, string $number, string $state): array
    {
        $abbr = strtoupper(trim($councilAbbr));

        if (!$this->supports($abbr)) {
            return $this->errorResult("Conselho '$abbr' não suportado pelo provedor " . $this->getName());
        }

        if (!$this->isConfigured()) {
            return $this->errorResult('Provedor ' . $this->getName() . ' não está configurado (API key ausente ou desabilitado).');
        }

        $baseUrl  = $this->getBaseUrl();
        $endpoint = self::COUNCIL_ENDPOINTS[$abbr];
        $apiKey   = $this->getApiKey();

        $url = $baseUrl . $endpoint . '?' . http_build_query([
            'registro' => trim($number),
            'uf'       => strtoupper(trim($state)),
        ]);

        $response = $this->httpRequest('GET', $url, [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ]);

        // Timeout
        if ($response['error'] !== '') {
            return $this->errorResult(
                'Timeout ou erro de conexão com ' . $this->getName() . ': ' . $response['error']
            );
        }

        // Autenticação
        if ($response['status'] === 401 || $response['status'] === 403) {
            return $this->errorResult(
                'Falha de autenticação no ' . $this->getName() . '. Verifique a API key.'
            );
        }

        // Rate limit
        if ($response['status'] === 429) {
            return $this->errorResult(
                'Limite de consultas atingido no ' . $this->getName() . '. Tente novamente mais tarde.'
            );
        }

        // Erro do servidor
        if ($response['status'] >= 500) {
            return $this->errorResult(
                'Erro temporário no servidor ' . $this->getName() . ' (HTTP ' . $response['status'] . ').'
            );
        }

        // Parse da resposta
        $json = json_decode($response['body'], true);

        if (!is_array($json)) {
            return $this->errorResult(
                'Resposta inválida do ' . $this->getName() . ': não é JSON válido.'
            );
        }

        // Registro não encontrado (HTTP 404 ou campo específico)
        if ($response['status'] === 404 || ($json['found'] ?? true) === false || ($json['status'] ?? '') === 'not_found') {
            return $this->notFoundResult();
        }

        // Resposta com erro explícito
        if (isset($json['error'])) {
            return $this->errorResult(
                'Erro retornado pelo ' . $this->getName() . ': ' . (string)$json['error']
            );
        }

        // Extrai dados normalizados
        $name   = $json['nome'] ?? $json['name'] ?? $json['data']['nome'] ?? $json['data']['name'] ?? null;
        $status = $json['situacao'] ?? $json['status'] ?? $json['data']['situacao'] ?? $json['data']['status'] ?? 'DESCONHECIDO';

        if ($name === null) {
            // Pode ser que a API retornou sucesso mas sem dados (registro não encontrado)
            if (($json['found'] ?? null) === false || empty($json['data'])) {
                return $this->notFoundResult();
            }
            return $this->errorResult(
                'Resposta do ' . $this->getName() . ' não contém nome do profissional.'
            );
        }

        $extra = [];
        if (isset($json['especialidade']) || isset($json['data']['especialidade'])) {
            $extra['specialty'] = $json['especialidade'] ?? $json['data']['especialidade'];
        }
        if (isset($json['tipo_inscricao']) || isset($json['data']['tipo_inscricao'])) {
            $extra['inscription_type'] = $json['tipo_inscricao'] ?? $json['data']['tipo_inscricao'];
        }

        return $this->successResult((string)$name, (string)$status, $extra);
    }

    private function getApiKey(): string
    {
        return trim((string)$this->getSetting('council_provider.consultario.api_key', ''));
    }

    private function getBaseUrl(): string
    {
        $url = trim((string)$this->getSetting('council_provider.consultario.base_url', self::DEFAULT_BASE_URL));
        return $url !== '' ? rtrim($url, '/') : self::DEFAULT_BASE_URL;
    }
}
