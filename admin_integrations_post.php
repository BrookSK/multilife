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
    admin_settings_set_many($settings, (int)auth_user_id());

    audit_log('update', 'admin_integrations', null, null, ['count' => count($settings)]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Integrações salvas.');
header('Location: /admin_integrations.php');
exit;
