<?php

declare(strict_types=1);

function integration_log(
    string $provider,
    string $action,
    string $status,
    ?int $httpStatus,
    $requestPayload,
    $responsePayload,
    ?string $errorMessage,
    int $attempts = 1
): void {
    $stmt = db()->prepare(
        'INSERT INTO integration_logs (provider, action, status, http_status, request_payload, response_payload, error_message, attempts)
         VALUES (:p, :a, :s, :hs, :req, :res, :err, :att)'
    );

    $stmt->execute([
        'p' => $provider,
        'a' => $action,
        's' => in_array($status, ['success', 'error'], true) ? $status : 'error',
        'hs' => $httpStatus,
        'req' => $requestPayload === null ? null : json_encode($requestPayload, JSON_UNESCAPED_UNICODE),
        'res' => $responsePayload === null ? null : json_encode($responsePayload, JSON_UNESCAPED_UNICODE),
        'err' => $errorMessage,
        'att' => max(1, $attempts),
    ]);
}

function integration_job_enqueue(string $provider, string $action, $payload, ?string $nextRunAt = null): int
{
    $stmt = db()->prepare(
        'INSERT INTO integration_jobs (provider, action, status, payload, next_run_at) VALUES (:p, :a, \'pending\', :pl, :nra)'
    );

    $stmt->execute([
        'p' => $provider,
        'a' => $action,
        'pl' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        'nra' => $nextRunAt,
    ]);

    return (int)db()->lastInsertId();
}
