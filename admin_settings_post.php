<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$settings = $_POST['settings'] ?? [];
if (!is_array($settings)) {
    $settings = [];
}

// Checkboxes não enviados quando desmarcados — garantir valor "0" para campos de habilitação
$checkboxDefaults = [
    'council_provider.consultario.enabled',
    'council_provider.infosimples.enabled',
    'council_provider.portal_direct.enabled',
];
foreach ($checkboxDefaults as $cbKey) {
    if (!isset($settings[$cbKey]) && isset($_POST['settings']) && is_array($_POST['settings'])) {
        // Só define "0" se a aba de Consultas API foi submetida (alguma key council_provider.* presente)
        $hasCouncilKeys = false;
        foreach (array_keys($settings) as $sk) {
            if (str_starts_with((string)$sk, 'council_provider.')) {
                $hasCouncilKeys = true;
                break;
            }
        }
        if ($hasCouncilKeys) {
            $settings[$cbKey] = '0';
        }
    }
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_by_user_id) VALUES (:k, :v, :uid) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_user_id = VALUES(updated_by_user_id)');

    foreach ($settings as $k => $v) {
        $key = trim((string)$k);
        $val = trim((string)$v);
        if ($key === '') {
            continue;
        }

        if (in_array($key, ['cron.token', 'smtp.in.password', 'smtp.out.password', 'evolution.api_key', 'council_provider.consultario.api_key', 'council_provider.infosimples.api_token'], true) && $val === '') {
            continue;
        }
        $stmt->execute(['k' => $key, 'v' => $val, 'uid' => auth_user_id()]);
    }

    audit_log('update', 'admin_settings', null, null, ['count' => count($settings)]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Configurações salvas.');
header('Location: /admin_settings.php');
exit;
