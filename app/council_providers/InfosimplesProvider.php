<?php

declare(strict_types=1);

require_once __DIR__ . '/CouncilProviderInterface.php';
require_once __DIR__ . '/AbstractProvider.php';

/**
 * Provedor: Infosimples
 *
 * Documentação: https://infosimples.com/consultas/
 * API de automação de consultas em portais públicos brasileiros.
 *
 * Autenticação: Token enviado como parâmetro na requisição
 * Base URL: https://api.infosimples.com/api/v2/consultas
 *
 * Conselhos suportados pela API:
 *   - CRM (CFM): /cfm/cadastro
 *   - CRP (CFP): /cfp/cadastro
 *   - CRO: /cro/{uf}/cadastro (por UF)
 *   - COREN: /coren/{uf}/cadastro (SP e PR disponíveis)
 *
 * Formato de resposta padrão:
 *   {
 *     "code": 200,
 *     "code_message": "...",
 *     "header": { "billable": true, "price": "2.07", ... },
 *     "data_count": 1,
 *     "data": [{ "nome": "...", "situacao": "...", ... }]
 *   }
 *
 * Códigos de resposta:
 *   200 = Sucesso
 *   601 = Token inválido
 *   602 = Créditos insuficientes
 *   600 = Registro não encontrado
 *   500 = Erro interno
 *
 * Configuração (admin_settings):
 *   council_provider.infosimples.api_token  — Token de API
 *   council_provider.infosimples.base_url   — URL base (padrão: https://api.infosimples.com/api/v2/consultas)
 *   council_provider.infosimples.enabled    — "1" para ativo
 *   council_provider.infosimples.priority   — Prioridade numérica (padrão: 20)
 */
class InfosimplesProvider extends AbstractProvider
{
    private const DEFAULT_BASE_URL = 'https://api.infosimples.com/api/v2/consultas';

    /** Conselhos suportados e seus endpoints */
    private const SUPPORTED_COUNCILS = ['CRM', 'CRP', 'CRO', 'COREN'];

    /** UFs com COREN disponível na Infosimples */
    private const COREN_UFS = ['SP', 'PR'];

    protected int $timeout = 120; // Infosimples pode demorar (automação de portais)

    public function getName(): string
    {
        return 'Infosimples';
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
        $uf   = strtoupper(trim($state));
        $num  = trim($number);

        if (!$this->supports($abbr)) {
            return $this->errorResult("Conselho '$abbr' não suportado pelo " . $this->getName() . ". Suportados: " . implode(', ', self::SUPPORTED_COUNCILS));
        }

        // COREN só disponível para SP e PR na Infosimples
        if ($abbr === 'COREN' && !in_array($uf, self::COREN_UFS, true)) {
            return $this->errorResult(
                $this->getName() . ': COREN disponível apenas para UFs: ' . implode(', ', self::COREN_UFS) . '. UF solicitada: ' . $uf
            );
        }

        if (!$this->isConfigured()) {
            return $this->errorResult('Provedor ' . $this->getName() . ' não está configurado (token ausente ou desabilitado).');
        }

        $baseUrl = $this->getBaseUrl();
        $token   = $this->getApiToken();

        // Monta endpoint conforme conselho
        $endpoint = $this->buildEndpoint($abbr, $uf);
        if ($endpoint === null) {
            return $this->errorResult("Endpoint não disponível para $abbr/$uf no " . $this->getName());
        }

        $url = $baseUrl . $endpoint;

        // Monta parâmetros da requisição
        $params = $this->buildParams($abbr, $num, $uf, $token);

        // Infosimples aceita GET com query params
        $fullUrl = $url . '?' . http_build_query($params);

        $response = $this->httpRequest('GET', $fullUrl, [
            'Accept: application/json',
        ]);

        // Timeout / erro de conexão
        if ($response['error'] !== '') {
            return $this->errorResult(
                'Timeout ou erro de conexão com ' . $this->getName() . ': ' . $response['error']
            );
        }

        // Parse da resposta JSON
        $json = json_decode($response['body'], true);

        if (!is_array($json)) {
            return $this->errorResult('Resposta inválida do ' . $this->getName() . ': não é JSON válido.');
        }

        $code = (int)($json['code'] ?? 0);
        $codeMessage = (string)($json['code_message'] ?? '');

        // Token inválido
        if ($code === 601) {
            return $this->errorResult($this->getName() . ': Token de autenticação inválido.');
        }

        // Créditos insuficientes
        if ($code === 602) {
            return $this->errorResult($this->getName() . ': Créditos insuficientes. Faça uma recarga.');
        }

        // Registro não encontrado
        if ($code === 600 || $code === 404) {
            return $this->notFoundResult();
        }

        // Erro interno
        if ($code >= 500 && $code < 600) {
            return $this->errorResult(
                'Erro temporário no ' . $this->getName() . ' (code ' . $code . '): ' . $codeMessage
            );
        }

        // Sucesso (code 200)
        if ($code === 200) {
            $data = $json['data'] ?? [];

            if (is_array($data) && !empty($data)) {
                // Pode ser array de resultados ou objeto direto
                $record = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;

                $name = $record['nome'] ?? $record['nome_razao_social'] ?? $record['nome_profissional'] ?? null;
                $situacao = $record['situacao'] ?? $record['situacao_cadastral'] ?? $record['status'] ?? 'DESCONHECIDO';

                if ($name === null) {
                    return $this->errorResult('Resposta do ' . $this->getName() . ' não contém nome do profissional.');
                }

                $extra = [];
                if (isset($record['especialidade']) || isset($record['especialidades'])) {
                    $extra['specialty'] = $record['especialidade'] ?? $record['especialidades'];
                }
                if (isset($record['tipo_inscricao'])) {
                    $extra['inscription_type'] = $record['tipo_inscricao'];
                }
                if (isset($record['categoria'])) {
                    $extra['category'] = $record['categoria'];
                }
                if (isset($record['crm']) || isset($record['numero_registro'])) {
                    $extra['registry_found'] = $record['crm'] ?? $record['numero_registro'] ?? $num;
                }

                return $this->successResult((string)$name, (string)$situacao, $extra);
            }

            // data vazio = não encontrado
            return $this->notFoundResult();
        }

        // Código não mapeado
        return $this->errorResult(
            $this->getName() . ': Código de resposta inesperado (' . $code . '): ' . $codeMessage
        );
    }

    /**
     * Monta o endpoint correto para cada conselho.
     */
    private function buildEndpoint(string $abbr, string $uf): ?string
    {
        $ufLower = strtolower($uf);

        return match ($abbr) {
            'CRM'    => '/cfm/cadastro',
            'CRP'    => '/cfp/cadastro',
            'CRO'    => '/cro/' . $ufLower . '/cadastro',
            'COREN'  => '/coren/' . $ufLower . '/cadastro',
            default  => null,
        };
    }

    /**
     * Monta os parâmetros da requisição conforme o conselho.
     */
    private function buildParams(string $abbr, string $number, string $uf, string $token): array
    {
        $base = ['token' => $token];

        return match ($abbr) {
            'CRM' => array_merge($base, [
                'crm' => $number,
                'uf'  => $uf,
            ]),
            'CRP' => array_merge($base, [
                'registro' => $number,
                'uf'       => $uf,
            ]),
            'CRO' => array_merge($base, [
                'registro' => $number,
            ]),
            'COREN' => array_merge($base, [
                'registro' => $number,
            ]),
            default => $base,
        };
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
