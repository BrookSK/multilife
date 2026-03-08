<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$apiToken = trim((string)($_POST['api_token'] ?? ''));
$sandboxMode = isset($_POST['sandbox_mode']) ? 1 : 0;
$webhookUrl = trim((string)($_POST['webhook_url'] ?? ''));

if ($apiToken === '') {
    flash_set('error', 'Informe o Token da API ZapSign.');
    header('Location: /zapsign_config.php');
    exit;
}

// Atualizar ou inserir configuração
$stmt = db()->query('SELECT id FROM zapsign_config LIMIT 1');
$existing = $stmt->fetch();

if ($existing) {
    $sql = 'UPDATE zapsign_config SET api_token = :api_token, sandbox_mode = :sandbox_mode, webhook_url = :webhook_url WHERE id = :id';
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'id' => $existing['id'],
        'api_token' => $apiToken,
        'sandbox_mode' => $sandboxMode,
        'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
    ]);
} else {
    $sql = 'INSERT INTO zapsign_config (api_token, sandbox_mode, webhook_url) VALUES (:api_token, :sandbox_mode, :webhook_url)';
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'api_token' => $apiToken,
        'sandbox_mode' => $sandboxMode,
        'webhook_url' => $webhookUrl !== '' ? $webhookUrl : null,
    ]);
}

flash_set('success', 'Configurações do ZapSign salvas com sucesso!');
header('Location: /zapsign_config.php');
exit;
