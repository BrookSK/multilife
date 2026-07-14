<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * Dispara o processamento de e-mail em background e retorna imediatamente.
 * Evita timeout do nginx (504) em e-mails grandes.
 */

$idFilter = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($idFilter <= 0) {
    flash_set('error', 'ID do e-mail não informado.');
    header('Location: /inbound_emails_list.php');
    exit;
}

// Verificar se o e-mail existe
$stmt = db()->prepare("SELECT id, status FROM inbound_emails WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $idFilter]);
$email = $stmt->fetch();

if (!$email) {
    flash_set('error', 'E-mail não encontrado.');
    header('Location: /inbound_emails_list.php');
    exit;
}

// Marcar como 'processing' para feedback visual
db()->prepare("UPDATE inbound_emails SET status = 'processing', error_message = NULL WHERE id = :id")
    ->execute(['id' => $idFilter]);

// Disparar processamento em background via HTTP non-blocking
$publicUrl = trim((string)admin_setting_get('app.public_base_url', ''));
if ($publicUrl === '') {
    $publicUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$token = '49$cpFu92';
$targetUrl = rtrim($publicUrl, '/') . '/cron/openai_extract_email_to_demand.php?token=' . urlencode($token) . '&id=' . $idFilter . '&retry_errors=1&force=1';

// Usar curl async (timeout 1s para não esperar resposta)
$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Timeout mínimo - só dispara e desconecta
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
@curl_exec($ch); // Ignora resultado/erro (vai timeout de propósito)
curl_close($ch);

flash_set('success', 'Processamento do e-mail #' . $idFilter . ' iniciado em background. Aguarde ~60 segundos e recarregue a página para ver o resultado.');
header('Location: /inbound_emails_list.php');
exit;
