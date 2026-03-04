<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set('America/Sao_Paulo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['app']['session_name']);
    session_set_cookie_params([
        'lifetime' => (int)$config['app']['session_lifetime_seconds'],
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/evolution_api_v1.php';
require_once __DIR__ . '/openai_api.php';
require_once __DIR__ . '/zapsign_api.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/view.php';

$sessionLifetime = (int)admin_setting_get('app.session_lifetime_seconds', (string)(int)$config['app']['session_lifetime_seconds']);
if ($sessionLifetime <= 0) {
    $sessionLifetime = (int)$config['app']['session_lifetime_seconds'];
}

if (isset($_SESSION['auth_user_id'])) {
    $last = isset($_SESSION['_last_activity']) ? (int)$_SESSION['_last_activity'] : 0;
    $now = time();
    if ($last > 0 && ($now - $last) > $sessionLifetime) {
        auth_logout();
        unset($_SESSION['_last_activity']);
        flash_set('error', 'Sessão expirada. Faça login novamente.');
        header('Location: /login.php');
        exit;
    }
    $_SESSION['_last_activity'] = $now;
}
