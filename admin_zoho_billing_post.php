<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$webhookToken = trim((string)($_POST['webhook_token'] ?? ''));
$testMode = isset($_POST['test_mode']) && (string)$_POST['test_mode'] === '1' ? '1' : '0';
$testPhone = trim((string)($_POST['test_phone'] ?? ''));
$template = trim((string)($_POST['template'] ?? ''));
$sendIntervalMs = (int)($_POST['send_interval_ms'] ?? 1200);
if ($sendIntervalMs < 0) {
    $sendIntervalMs = 0;
}
$preferredInstance = trim((string)($_POST['preferred_instance'] ?? 'financeiro'));

$settings = [
    'zoho_billing.webhook_token' => $webhookToken,
    'zoho_billing.test_mode' => $testMode,
    'zoho_billing.test_phone' => $testPhone,
    'zoho_billing.send_interval_ms' => (string)$sendIntervalMs,
    'zoho_billing.preferred_instance' => $preferredInstance,
    'zoho_billing.template' => $template,
];

$db = db();
$db->beginTransaction();
try {
    admin_settings_set_many($settings, auth_user_id());
    audit_log('update', 'admin_settings', null, null, ['scope' => 'zoho_billing', 'count' => count($settings)]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Configurações do Zoho Faturamento salvas.');
header('Location: /admin_zoho_billing.php');
exit;
