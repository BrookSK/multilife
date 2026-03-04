<?php

declare(strict_types=1);

$env = static function (string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false) {
        return $default;
    }
    $v = trim((string)$v);
    return $v === '' ? $default : $v;
};

$envInt = static function (string $key, int $default): int {
    $v = getenv($key);
    if ($v === false) {
        return $default;
    }
    $v = trim((string)$v);
    if ($v === '' || !ctype_digit($v)) {
        return $default;
    }
    return (int)$v;
};

return [
    'db' => [
        'host' => $env('DB_HOST', 'localhost'),
        'port' => $envInt('DB_PORT', 3306),
        'name' => $env('DB_NAME', 'wp_hmimd'),
        'user' => $env('DB_USER', 'wp_xv2ov'),
        'pass' => $env('DB_PASS', '33537095a'),
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => $env('APP_BASE_URL', ''),
        'session_name' => $env('APP_SESSION_NAME', 'multilife_session') ?? 'multilife_session',
        'session_lifetime_seconds' => $envInt('APP_SESSION_LIFETIME_SECONDS', 60 * 60),
    ],
];
