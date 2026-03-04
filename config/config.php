<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'multilife',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => '',
        'session_name' => 'multilife_session',
        'session_lifetime_seconds' => 60 * 60,
    ],
];
