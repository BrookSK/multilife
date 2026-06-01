<?php

declare(strict_types=1);

require_once __DIR__ . '/CouncilProviderInterface.php';
require_once __DIR__ . '/AbstractProvider.php';

/**
 * Provedor: Consultar.IO
 *
 * Documentação oficial: https://docs.consultar.io/
 * APIs RESTful para consulta de registros profissionais.
 *
 * Autenticação: Header "Authorization: Token <seu-token>"
 * Base URL: https://consultar.io/api/v1
 *
 * Conselhos suportados pela API:
 *   - CRM: GET /crm/consultar?uf={uf}&numero_registro={numero}
 *   - CRO: GET /cro/consultar?uf={uf}&numero_registro={numero}&categoria=cd
 *
 * Códigos de status HTTP:
 *   200 = Sucesso (registro encontrado)
 *   400 = Requisição inválida (REQUISICAO_INVALIDA)
 *   403 = Plano inativo ou créditos insuficientes (PLANO_INATIVO / CREDITOS_INSUFICIENTES)
 *   404 = Registro não encontrado (NAO_ENCONTRADO)
 *   500 = Erro interno (ERRO / ERRO_INTERNO)
 *   503 = Serviço indisponível (SERVICO_INDISPONIVEL)
 *
 * Custo: R$ 0,20 por consulta (cobrado em 200 e 404)
 * Timeout recomendado: 300 segundos
 *
 * Configuração (admin_settings):
 *   council_provider.consultario.api_key   — Token de API
 *   council_provider.consultario.base_url  — URL base (padrão: https://consultar.io/api/v1)
 *   council_provider.consultario.enabled   — "1" para ativo
 *   council_provider.consultario.priority  — Prioridade numérica (padrão: 10)
 */
class ConsultarIoProvider extends AbstractProvider
{
    private const DEFAULT_BASE_URL = 'https://consultar.io/api/v1';

    /** Conselhos suportados pela API do Consultar.IO */
    private const SUPPORTED_COUNCILS = ['CRM', 'CRO'];

    protected int $timeout = 60; // Consultar.IO recomenda até 300s, usamos 60s como padrão

    public function getName(): string
    {
        return 'Consultar.IO';
    }

    public function supports(string $councilAbbr): bool
    {
        return in_array(strtoupper($councilAbbr), self::SUPPORTED_COUNCILS, true);
    }

    public function supportedCouncils(): array
    {
        return self::SUPPORTED_COUNCILS;
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
            return $this->errorResult("Conselho '$abbr' não suportado pelo " . $this->getName() . ". Suportados: " . implode(', ', self::SUPPORTED_COUNCILS));
        }

        if (!$this->isConfigured()) {
            return $this->errorResult('Provedor ' . $this->getName() . ' não está configurado (token ausente ou desabilitado).');
        }

        $baseUrl = $this->getBaseUrl();
        $apiKey  = $this->getApiKey();
        $uf      = strtolower(trim($state));
        $numero  = trim($number);

        // Monta URL conforme documentação oficial
        $url = match ($abbr) {
            'CRM' => $baseUrl . '/crm/consultar?' . http_build_query([
                'uf'              => $uf,
                'numero_registro' => $numero,
            ]),
            'CRO' => $baseUrl . '/cro/consultar?' . http_build_query([
                'uf'              => $uf,
                'numero_registro' => $numero,
                'categoria'       => 'cd', // Cirurgião-Dentista (padrão)
            ]),
            default => '',
        };

        if ($url === '') {
            return $this->errorResult("Endpoint não configurado para conselho '$abbr'.");
        }

        $response = $this->httpRequest('GET', $url, [
            'Authorization: Token ' . $apiKey,
            'Accept: application/json',
        ]);

        // Timeout / erro de conexão
        if ($response['error'] !== '') {
            return $this->errorResult(
                'Timeout ou erro de conexão com ' . $this->getName() . ': ' . $response['error']
            );
        }

        // Créditos insuficientes ou plano inativo
        if ($response['status'] === 403) {
            $json = json_decode($response['body'], true);
            $errorCode = $json['error'] ?? 'FORBIDDEN';
            $errorMsg  = $json['message'] ?? 'Acesso negado';
            return $this->errorResult(
                $this->getName() . ': ' . $errorCode . ' — ' . $errorMsg
            );
        }

        // Requisição inválida
        if ($response['status'] === 400) {
            $json = json_decode($response['body'], true);
            $errorMsg = $json['message'] ?? 'Requisição inválida';
            return $this->errorResult(
                $this->getName() . ': REQUISICAO_INVALIDA — ' . $errorMsg
            );
        }

        // Registro não encontrado (HTTP 404)
        if ($response['status'] === 404) {
            return $this->notFoundResult();
        }

        // Erro do servidor
        if ($response['status'] >= 500) {
            $json = json_decode($response['body'], true);
            $errorCode = $json['error'] ?? 'ERRO';
            $errorMsg  = $json['message'] ?? 'Erro interno';
            return $this->errorResult(
                'Erro temporário no ' . $this->getName() . ': ' . $errorCode . ' — ' . $errorMsg
            );
        }

        // Sucesso (HTTP 200)
        if ($response['status'] === 200) {
            $json = json_decode($response['body'], true);

            if (!is_array($json)) {
                return $this->errorResult('Resposta inválida do ' . $this->getName() . ': não é JSON válido.');
            }

            // Campos retornados pela API conforme documentação
            $name     = $json['nome_razao_social'] ?? null;
            $situacao = $json['situacao'] ?? 'DESCONHECIDO';

            if ($name === null) {
                return $this->errorResult('Resposta do ' . $this->getName() . ' não contém nome do profissional.');
            }

            $extra = [];
            if (isset($json['especialidades']) && $json['especialidades'] !== null) {
                $extra['specialty'] = $json['especialidades'];
            }
            if (isset($json['tipo_inscricao'])) {
                $extra['inscription_type'] = $json['tipo_inscricao'];
            }
            if (isset($json['categoria'])) {
                $extra['category'] = $json['categoria'];
            }

            return $this->successResult((string)$name, (string)$situacao, $extra);
        }

        // Status HTTP inesperado
        return $this->errorResult(
            'Status HTTP inesperado do ' . $this->getName() . ': ' . $response['status']
        );
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
