<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /admin_whatsapp_templates.php');
    exit;
}

$db = db();

$stmt = $db->prepare('SELECT id, name FROM whatsapp_message_templates WHERE id = :id');
$stmt->execute(['id' => $id]);
$template = $stmt->fetch();

if (!$template) {
    flash_set('error', 'Template não encontrado.');
    header('Location: /admin_whatsapp_templates.php');
    exit;
}

$db->beginTransaction();

try {
    // Buscar e deletar arquivos físicos
    $stmt = $db->prepare('SELECT file_path FROM whatsapp_template_attachments WHERE template_id = :tid');
    $stmt->execute(['tid' => $id]);
    $attachments = $stmt->fetchAll();
    
    foreach ($attachments as $att) {
        $filePath = $att['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    // Deletar diretório de uploads
    $uploadDir = __DIR__ . '/uploads/whatsapp_templates/' . $id;
    if (is_dir($uploadDir)) {
        rmdir($uploadDir);
    }
    
    // Deletar template (anexos serão deletados por CASCADE)
    $stmt = $db->prepare('DELETE FROM whatsapp_message_templates WHERE id = :id');
    $stmt->execute(['id' => $id]);
    
    audit_log('delete', 'whatsapp_message_templates', (string)$id, null, [
        'name' => $template['name']
    ]);
    
    $db->commit();
    
    flash_set('success', 'Template excluído com sucesso!');
    header('Location: /admin_whatsapp_templates.php');
    exit;
    
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao excluir template: ' . $e->getMessage());
    header('Location: /admin_whatsapp_templates.php');
    exit;
}
