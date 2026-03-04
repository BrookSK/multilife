<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$settings = $_POST['settings'] ?? [];
if (!is_array($settings)) {
    $settings = [];
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

        if (in_array($key, ['cron.token', 'smtp.in.password', 'smtp.out.password'], true) && $val === '') {
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
