<?php

declare(strict_types=1);

final class OpenAiApi
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?string $model = null)
    {
        $rawBase = (string)($baseUrl ?? admin_setting_get('openai.base_url', 'https://api.openai.com'));
        $rawBase = trim($rawBase);
        if ($rawBase === '') {
            $rawBase = 'https://api.openai.com';
        }
        if (!preg_match('~^https?://~i', $rawBase)) {
            $rawBase = 'https://api.openai.com';
        }
        $this->baseUrl = rtrim($rawBase, '/');
        $this->apiKey = (string)($apiKey ?? admin_setting_get('openai.api_key', ''));
        $this->model = (string)($model ?? admin_setting_get('openai.model', 'gpt-4o-mini'));

        if ($this->apiKey === '') {
            throw new RuntimeException('OpenAI não configurada (api_key).');
        }
    }

    private function request(string $method, string $path, $body = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        $res = http_json_request($method, $url, $headers, $body);
        $ok = $res['status'] >= 200 && $res['status'] < 300;

        integration_log(
            'openai',
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

    public function chatCompletions(array $messages, ?string $model = null, array $extra = []): array
    {
        $payload = array_merge([
            'model' => $model ?? $this->model,
            'messages' => $messages,
        ], $extra);

        return $this->request('POST', '/v1/chat/completions', $payload);
    }
}
