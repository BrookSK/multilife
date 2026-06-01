<?php

declare(strict_types=1);

require_once __DIR__ . '/CouncilProviderInterface.php';
require_once __DIR__ . '/AbstractProvider.php';

/**
 * Provedor: Infosimples
 *
 * API de consulta de registros profissionais brasileiros.
 * Documentação: https://infosimples.com/docs
 *
 * Configuração necessária (admin_settings):
 *   council_provider.infosimples.api_token  — Token de API
 *   council_provider.infosimples.base_url   — URL base (padrão: https://api.infosimples.com/api/v2)
 *   council_provider.infosimples.enabled    — "1" para ativo
 *   council_provider.infosimples.priority   — Prioridade numérica (padrão: 20)
 */
class InfosimplesProvider extends AbstractProvider
{
    private const DEFAULT_BASE_URL = 'https://api.infosimples.com/api/v2';

    /**
     * Mapeamento de conselhos para endpoints da Infosimples.
     * A Infosimples usa paths como /consultas/conselhos/{conselho}
     */
    private const COUNCIL_ENDPOINTS = [
        'CRP'     => '/consultas/conselhos/crp',
        'CRN'     => '/consultas/conselhos/crn',
        'COREN'   => '/consultas/conselhos/coren',
        'CREFITO' => '/consultas/conselhos/crefito',
        'CRM'     => '/consultas/conselhos/crm',
        'CRO'     => '/consultas/conselhos/cro',
        'CREA'    => '/consultas/conselhos/crea',
        'OAB'     => '/consultas/conselhos/oab',
    ];

    public function getName(): string
    {
        return 'Infosimples';
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
        $token   = $this->getApiToken();
        $enabled = $this->getSetting('council_provider.infosimples.enabled', '0');
        return $token !== '' && $enabled === '1';
    }

    public function getPriority(): int
    {
        $p = $this->getSetting('council_provider.infosimples.priority', '20');
        return (int)$p;
    }

    public function validate(string $councilAbbr, string $number, string $state): array
    {
        $abbr = strtoupper(trim($councilAbbr));

        if (!$this->supports($abbr)) {
            return $this->errorResult("Conselho '$abbr' não suportado pelo provedor " . $this->getName());
        }

        if (!$this->isConfigured()) {
            return $this->errorResult('Provedor ' . $this->getName() . ' não está configurado (token ausente ou desabilitado).');
        }

        $baseUrl  = $this->getBaseUrl();
        $endpoint = self::COUNCIL_ENDPOINTS[$abbr];
        $token    = $this->getApiToken();

        // Infosimples usa POST com JSON body
        $payload = json_encode([
            'token'    => $token,
            'registro' => trim($number),
            'uf'       => strtoupper(trim($state)),
        ], JSON_UNESCAPED_UNICODE);

        $url = $baseUrl . $endpoint;

        $response = $this->httpRequest('POST', $url, [
            'Content-Type: application/json',
            'Accept: application/json',
        ], $payload);

        // Timeout
        if ($response['error'] !== '') {
            return $this->errorResult(
                'Timeout ou erro de conexão com ' . $this->getName() . ': ' . $response['error']
            );
        }

        // Autenticação
        if ($response['status'] === 401 || $response['status'] === 403) {
            return $this->errorResult(
                'Falha de autenticação no ' . $this->getName() . '. Verifique o token de API.'
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

        // Infosimples retorna code/code_message para status
        $code = (int)($json['code'] ?? 0);

        // Código 200 = sucesso, 600+ = não encontrado, 400+ = erro
        if ($code >= 600) {
            return $this->notFoundResult();
        }

        if ($code >= 400 && $code < 600) {
            $msg = $json['code_message'] ?? $json['message'] ?? 'Erro desconhecido';
            return $this->errorResult('Erro retornado pelo ' . $this->getName() . ': ' . (string)$msg);
        }

        // Extrai dados do array 'data'
        $data = $json['data'] ?? $json;
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            $data = $data[0]; // Infosimples pode retornar array de resultados
        }

        $name   = $data['nome'] ?? $data['name'] ?? $data['nome_profissional'] ?? null;
        $status = $data['situacao'] ?? $data['status'] ?? $data['situacao_cadastral'] ?? 'DESCONHECIDO';

        if ($name === null) {
            if ($code === 200 && empty($data)) {
                return $this->notFoundResult();
            }
            return $this->errorResult(
                'Resposta do ' . $this->getName() . ' não contém nome do profissional.'
            );
        }

        $extra = [];
        if (isset($data['especialidade'])) {
            $extra['specialty'] = $data['especialidade'];
        }
        if (isset($data['tipo_inscricao'])) {
            $extra['inscription_type'] = $data['tipo_inscricao'];
        }

        return $this->successResult((string)$name, (string)$status, $extra);
    }

    private function getApiToken(): string
    {
        return trim((string)$this->getSetting('council_provider.infosimples.api_token', ''));
    }

    private function getBaseUrl(): string
    {
        $url = trim((string)$this->getSetting('council_provider.infosimples.base_url', self::DEFAULT_BASE_URL));
        return $url !== '' ? rtrim($url, '/') : self::DEFAULT_BASE_URL;
    }
}
