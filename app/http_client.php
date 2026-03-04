<?php

declare(strict_types=1);

function http_json_request(string $method, string $url, array $headers = [], $body = null, int $timeoutSeconds = 30): array
{
    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    $method = strtoupper($method);
    $hdrs = [];
    foreach ($headers as $k => $v) {
        $hdrs[] = $k . ': ' . $v;
    }

    $payload = null;
    if ($body !== null) {
        if (is_string($body)) {
            $payload = $body;
        } else {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $hdrs[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_HEADER => true,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    curl_close($ch);

    $headerRaw = substr($raw, 0, $headerSize);
    $bodyRaw = substr($raw, $headerSize);

    $decoded = null;
    $trim = trim($bodyRaw);
    if ($trim !== '' && (str_starts_with($trim, '{') || str_starts_with($trim, '['))) {
        $decoded = json_decode($trim, true);
    }

    return [
        'status' => $status,
        'headers_raw' => $headerRaw,
        'body_raw' => $bodyRaw,
        'json' => $decoded,
    ];
}
