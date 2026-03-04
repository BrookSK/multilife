<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$expected = (string)admin_setting_get('cron.token', '');

if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
