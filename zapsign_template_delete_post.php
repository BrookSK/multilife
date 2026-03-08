<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$templateId = (int)($_POST['template_id'] ?? 0);

$stmt = db()->prepare('DELETE FROM zapsign_contract_templates WHERE id = :id');
$stmt->execute(['id' => $templateId]);

flash_set('success', 'Template excluído com sucesso!');
header('Location: /zapsign_config.php');
exit;
