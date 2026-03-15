<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /admin_email_templates.php');
    exit;
}

$stmt = db()->prepare('SELECT id, name FROM email_templates WHERE id = :id');
$stmt->execute(['id' => $id]);
$template = $stmt->fetch();

if (!$template) {
    flash_set('error', 'Template não encontrado.');
    header('Location: /admin_email_templates.php');
    exit;
}

$db = db();
$db->beginTransaction();

try {
    $stmt = $db->prepare('DELETE FROM email_templates WHERE id = :id');
    $stmt->execute(['id' => $id]);
    
    audit_log('delete', 'email_templates', (string)$id, null, [
        'name' => $template['name']
    ]);
    
    $db->commit();
    
    flash_set('success', 'Template excluído com sucesso!');
    header('Location: /admin_email_templates.php');
    exit;
    
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao excluir template: ' . $e->getMessage());
    header('Location: /admin_email_templates.php');
    exit;
}
