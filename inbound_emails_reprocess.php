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

// Resetar status
db()->prepare("UPDATE inbound_emails SET status = 'received', error_message = NULL, processed_at = NULL WHERE id = :id")
    ->execute(['id' => $id]);

// Tentar disparar processamento em background via exec (Linux)
$cronToken = trim((string)admin_setting_get('cron.token', ''));
$scriptPath = realpath(__DIR__ . '/cron/openai_extract_email_to_demand.php');

if ($scriptPath && $cronToken !== '') {
    $phpBin = PHP_BINARY ?: '/usr/bin/php';
    // Escapar o token para shell
    $escapedToken = escapeshellarg("token=$cronToken");
    $cmd = "$phpBin $scriptPath $escapedToken " . escapeshellarg("id=$id") . " " . escapeshellarg("force=1") . " " . escapeshellarg("retry_errors=1") . " > /dev/null 2>&1 &";
    
    error_log("[REPROCESS] Disparando em background: $cmd");
    @exec($cmd);
    
    flash_set('success', 'E-mail #' . $id . ' sendo processado em background. Aguarde ~60 segundos e recarregue.');
} else {
    flash_set('success', 'E-mail #' . $id . ' marcado para reprocessamento. Será processado no próximo ciclo do cron.');
}

header('Location: /inbound_emails_list.php');
exit;
