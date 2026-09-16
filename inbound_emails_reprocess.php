<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /inbound_emails_list.php');
    exit;
}

// Verificar se o e-mail existe
$stmt = db()->prepare("SELECT id, body_text, body_html FROM inbound_emails WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$email = $stmt->fetch();

if (!$email) {
    flash_set('error', 'E-mail não encontrado.');
    header('Location: /inbound_emails_list.php');
    exit;
}

// Verificar se tem body
$hasBody = (trim((string)($email['body_text'] ?? '')) !== '' || trim((string)($email['body_html'] ?? '')) !== '');
if (!$hasBody) {
    flash_set('error', 'E-mail #' . $id . ' não tem corpo (body vazio). Re-encaminhe o e-mail para capturar novamente.');
    header('Location: /inbound_emails_list.php');
    exit;
}

// Resetar status — inclui também e-mails presos em 'processing' (travados por
// falha/timeout em execução anterior; o filtro normal do cron só pega received/error).
db()->prepare("UPDATE inbound_emails SET status = 'received', error_message = NULL, processed_at = NULL WHERE id = :id")
    ->execute(['id' => $id]);

$cronToken = trim((string)admin_setting_get('cron.token', ''));

if ($cronToken === '') {
    flash_set('error', 'Token do CRON não configurado (Configurações → Ajuda/CRON). E-mail #' . $id . ' foi marcado como "received" e será processado no próximo ciclo do cron.');
    header('Location: /inbound_emails_list.php');
    exit;
}

// Disparar o processamento via HTTP loopback (funciona sob PHP-FPM, ao contrário
// de exec() com PHP_BINARY, que sob fpm-fcgi aponta para o binário do FPM).
// O cron fecha a conexão com fastcgi_finish_request() e continua em background,
// então usamos um timeout curto apenas para disparar.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$url = $scheme . '://' . $host . '/cron/openai_extract_email_to_demand.php?'
    . http_build_query([
        'token' => $cronToken,
        'id' => $id,
        'force' => '1',
        'retry_errors' => '1',
    ]);

$dispatched = false;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        $dispatched = true;
    } else {
        error_log("[REPROCESS] Loopback falhou (HTTP $httpCode): $curlErr | URL base: $scheme://$host");
    }
}

if ($dispatched) {
    flash_set('success', 'E-mail #' . $id . ' enviado para processamento. Aguarde alguns segundos e recarregue a página.');
} else {
    flash_set('error', 'Não consegui disparar o processamento automático de #' . $id . '. Ele ficou marcado como "received" e será processado no próximo ciclo do cron. (Verifique se o CRON de extração está agendado.)');
}

header('Location: /inbound_emails_list.php');
exit;
