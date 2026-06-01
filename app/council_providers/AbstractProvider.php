<?php

declare(strict_types=1);

/**
 * Classe base abstrata para provedores de validação.
 * Fornece métodos utilitários comuns a todos os provedores.
 */
abstract class AbstractProvider implements CouncilProviderInterface
{
    protected int $timeout = 30;

    /**
     * Realiza requisição HTTP via cURL.
     *
     * @return array ['status' => int, 'body' => string, 'headers' => array, 'error' => string, 'time_ms' => int]
     */
    protected function httpRequest(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?int $timeout = null
    ): array {
        $ch = curl_init();
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'curl_init falhou', 'time_ms' => 0];
        }

        $startTime = microtime(true);
        $effectiveTimeout = $timeout ?? $this->timeout;

        $defaultHeaders = [
            'Accept: application/json',
            'User-Agent: MultiLife/1.0 CouncilValidator',
        ];
        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min($effectiveTimeout, 10),
            CURLOPT_TIMEOUT        => $effectiveTimeout,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw       = curl_exec($ch);
        $curlError = curl_error($ch);
        $status    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSz  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);

        if ($raw === false) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => $curlError, 'time_ms' => $elapsedMs];
        }

        $rawHeaders = substr($raw, 0, $headerSz);
        $bodyRaw    = substr($raw, $headerSz);

        $parsedHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        return [
            'status'  => $status,
            'body'    => $bodyRaw,
            'headers' => $parsedHeaders,
            'error'   => '',
            'time_ms' => $elapsedMs,
        ];
    }

    /**
     * Monta resultado de sucesso padronizado.
     */
    protected function successResult(string $name, string $status, array $extra = []): array
    {
        return array_merge([
            'success' => true,
            'valid'   => true,
            'name'    => $name,
            'status'  => strtoupper($status),
            'source'  => $this->getName(),
        ], $extra);
    }

    /**
     * Monta resultado de "não encontrado" padronizado.
     */
    protected function notFoundResult(): array
    {
        return [
            'success' => true,
            'valid'   => false,
            'name'    => null,
            'status'  => 'NÃO ENCONTRADO',
            'source'  => $this->getName(),
        ];
    }

    /**
     * Monta resultado de erro padronizado.
     */
    protected function errorResult(string $reason, array $extra = []): array
    {
        return array_merge([
            'success' => false,
            'valid'   => false,
            'name'    => null,
            'status'  => 'ERRO',
            'source'  => $this->getName(),
            'error'   => $reason,
        ], $extra);
    }

    /**
     * Obtém configuração do provedor via admin_settings.
     */
    protected function getSetting(string $key, ?string $default = null): ?string
    {
        return admin_setting_get($key, $default);
    }
}
