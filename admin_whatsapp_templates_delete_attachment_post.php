<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;

if ($id <= 0 || $templateId <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /admin_whatsapp_templates.php');
    exit;
}

$stmt = db()->prepare('SELECT file_path, file_name FROM whatsapp_template_attachments WHERE id = :id AND template_id = :tid');
$stmt->execute(['id' => $id, 'tid' => $templateId]);
$attachment = $stmt->fetch();

if (!$attachment) {
    flash_set('error', 'Anexo não encontrado.');
    header('Location: /admin_whatsapp_templates_edit.php?id=' . $templateId);
    exit;
}

$db = db();
$db->beginTransaction();

try {
    // Deletar arquivo físico
    $filePath = $attachment['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Deletar registro
    $stmt = $db->prepare('DELETE FROM whatsapp_template_attachments WHERE id = :id');
    $stmt->execute(['id' => $id]);
    
    audit_log('delete', 'whatsapp_template_attachments', (string)$id, null, [
        'template_id' => $templateId,
        'file_name' => $attachment['file_name']
    ]);
    
    $db->commit();
    
    flash_set('success', 'Anexo removido com sucesso!');
    header('Location: /admin_whatsapp_templates_edit.php?id=' . $templateId);
    exit;
    
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao remover anexo: ' . $e->getMessage());
    header('Location: /admin_whatsapp_templates_edit.php?id=' . $templateId);
    exit;
}
