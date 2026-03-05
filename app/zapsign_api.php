<?php

declare(strict_types=1);

final class ZapSignApi
{
    private $baseUrl;
    private $apiToken;

    public function __construct(?string $baseUrl = null, ?string $apiToken = null)
    {
        $this->baseUrl = rtrim((string)($baseUrl ?? admin_setting_get('zapsign.base_url', 'https://api.zapsign.com.br')), '/');
        $this->apiToken = (string)($apiToken ?? admin_setting_get('zapsign.api_token', ''));

        if ($this->apiToken === '') {
            throw new RuntimeException('ZapSign não configurado (api_token).');
        }
    }

    private function request(string $method, string $path, $body = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiToken,
        ];

        $res = http_json_request($method, $url, $headers, $body);
        $ok = $res['status'] >= 200 && $res['status'] < 300;

        integration_log(
            'zapsign',
            $method . ' ' . $path,
            $ok ? 'success' : 'error',
            (int)$res['status'],
            $body,
            $res['json'] ?? $res['body_raw'],
            $ok ? null : 'HTTP ' . (string)$res['status'],
            1
        );

        return $res;
    }

    // Docs: POST https://api.zapsign.com.br/api/v1/docs/
    public function createDoc(array $payload): array
    {
        return $this->request('POST', '/api/v1/docs/', $payload);
    }

    // Docs: GET https://api.zapsign.com.br/api/v1/docs/{{doc_token}}/
    public function detailDoc(string $docToken): array
    {
        $docToken = trim($docToken);
        if ($docToken === '') {
            throw new RuntimeException('doc_token inválido.');
        }
        return $this->request('GET', '/api/v1/docs/' . rawurlencode($docToken) . '/');
    }
}
