<?php

declare(strict_types=1);

final class OpenAiApi
{
    private $baseUrl;
    private $apiKey;
    private $model;

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

    /**
     * Transcreve áudio usando Whisper API
     * @param string $audioFilePath Caminho completo do arquivo de áudio
     * @param string $language Código do idioma (opcional, ex: 'pt' para português)
     * @return array Resposta da API com a transcrição
     */
    public function transcribeAudio(string $audioFilePath, string $language = 'pt'): array
    {
        if (!file_exists($audioFilePath)) {
            throw new RuntimeException("Arquivo de áudio não encontrado: $audioFilePath");
        }

        $url = $this->baseUrl . '/v1/audio/transcriptions';
        
        // Criar multipart/form-data manualmente
        $boundary = '----WebKitFormBoundary' . uniqid();
        $fileContent = file_get_contents($audioFilePath);
        $fileName = basename($audioFilePath);
        
        $body = "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"$fileName\"\r\n";
        $body .= "Content-Type: audio/ogg\r\n\r\n";
        $body .= $fileContent . "\r\n";
        
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
        $body .= "whisper-1\r\n";
        
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n";
        $body .= "$language\r\n";
        
        $body .= "--$boundary--\r\n";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);
        
        $ok = $httpCode >= 200 && $httpCode < 300;
        integration_log(
            'openai_whisper',
            'transcribe',
            $ok ? 'success' : 'error',
            $httpCode,
            ['file' => $fileName, 'language' => $language],
            $result,
            $ok ? null : 'HTTP ' . $httpCode,
            1
        );

        return [
            'status' => $httpCode,
            'json' => $result,
            'body_raw' => $response,
        ];
    }
}
