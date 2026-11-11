<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set('America/Sao_Paulo');

// SESSÃO: o login dura 30 dias (contados a partir do último uso). Valores menores
// vindos do config, da variável de ambiente ou de Configurações não encurtam.
const APP_SESSION_MIN_LIFETIME = 30 * 24 * 60 * 60;
$sessionLifetime = max(APP_SESSION_MIN_LIFETIME, (int)$config['app']['session_lifetime_seconds']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $httpsVal = $_SERVER['HTTPS'] ?? '';
    $isSecure = false;
    if (is_string($httpsVal) || is_int($httpsVal) || is_bool($httpsVal)) {
        $httpsStr = strtolower(trim((string)$httpsVal));
        $isSecure = ($httpsStr !== '' && $httpsStr !== 'off' && $httpsStr !== '0');
    }

    // Arquivos de sessão em pasta própria. Na pasta padrão do servidor a limpeza
    // automática (24 min parado, ou o cron do sistema operacional) apagava a
    // sessão e o usuário era deslogado "do nada", mesmo com o cookie válido.
    $sessionDir = app_session_dir();
    if ($sessionDir !== '') {
        session_save_path($sessionDir);
        // Limpeza própria das sessões vencidas (1 a cada 1000 requisições)
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '1000');
    }
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);

    session_name($config['app']['session_name']);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isSecure,
    ]);
    session_start();

    // Validade do cookie renovada a cada uso (antes contava do login e vencia
    // mesmo com o usuário trabalhando).
    if (!empty($_SESSION['auth_user_id']) && !headers_sent()) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + $sessionLifetime,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $isSecure,
        ]);
    }

    // Requisições sem login e sem cookie (webhook, cron, robôs) não deixam arquivo
    // de sessão vazio guardado por 30 dias.
    $hadSessionCookie = isset($_COOKIE[session_name()]);
    register_shutdown_function(static function () use ($hadSessionCookie): void {
        if (!$hadSessionCookie && session_status() === PHP_SESSION_ACTIVE && empty($_SESSION)) {
            @session_destroy();
        }
    });
}

/**
 * Pasta dos arquivos de sessão do sistema. Prefere fora da pasta pública do site;
 * se não der para criar, usa storage/sessions (bloqueada para acesso pela web).
 * Retorna '' para usar o padrão do PHP se nenhuma for gravável.
 */
function app_session_dir(): string
{
    $candidates = [
        dirname(__DIR__, 2) . '/' . basename(dirname(__DIR__)) . '_sessions',
        dirname(__DIR__) . '/storage/sessions',
    ];
    foreach ($candidates as $i => $dir) {
        // "@": com open_basedir, checar pasta fora do site gera aviso
        if (!@is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (@is_dir($dir) && @is_writable($dir)) {
            if ($i === 1) {
                // Dentro do site: bloquear acesso direto pelo navegador
                $ht = dirname($dir) . '/.htaccess';
                if (!is_file($ht)) {
                    @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
                }
                if (!is_file($dir . '/index.html')) {
                    @file_put_contents($dir . '/index.html', '');
                }
            }
            return $dir;
        }
    }
    return '';
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
require_once __DIR__ . '/smtp_client.php';
require_once __DIR__ . '/notification_guard.php';
require_once __DIR__ . '/professional_links.php';
require_once __DIR__ . '/frequency_helper.php';
require_once __DIR__ . '/insurer_document_types.php';
require_once __DIR__ . '/attendance_resume_helper.php';
require_once __DIR__ . '/billing_closure_helper.php';
require_once __DIR__ . '/demand_followup_helper.php';
require_once __DIR__ . '/demand_dispatch_batch.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/zoho_billing.php';
require_once __DIR__ . '/whatsapp_event_dispatcher.php';
require_once __DIR__ . '/whatsapp_instances.php';
require_once __DIR__ . '/chat_message_store.php';
require_once __DIR__ . '/chat_format.php';
require_once __DIR__ . '/whatsapp_health.php';
require_once __DIR__ . '/clients_helper.php';
require_once __DIR__ . '/secure_store.php';
require_once __DIR__ . '/location_zones.php';
require_once __DIR__ . '/demand_form.php';
require_once __DIR__ . '/dispatch_targets.php';
require_once __DIR__ . '/dispatch_tracker_widget.php';
require_once __DIR__ . '/markdown_lite.php';
require_once __DIR__ . '/company_docs.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/navigation.php';
require_once __DIR__ . '/page_history.php';

// Tempo sem uso até deslogar: Configurações pode AUMENTAR, nunca baixar de 30 dias
// (o cookie e os arquivos de sessão acima já valem por esse tempo).
$sessionLifetime = max($sessionLifetime, (int)admin_setting_get('app.session_lifetime_seconds', '0'));

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
