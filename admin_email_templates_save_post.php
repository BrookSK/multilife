<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$isEdit = $id > 0;

$name = trim((string)($_POST['name'] ?? ''));
$eventType = trim((string)($_POST['event_type'] ?? ''));
$healthInsurerId = isset($_POST['health_insurer_id']) && $_POST['health_insurer_id'] !== '' ? (int)$_POST['health_insurer_id'] : null;
$subject = trim((string)($_POST['subject'] ?? ''));
$bodyHtml = trim((string)($_POST['body_html'] ?? ''));
$bodyPlain = trim((string)($_POST['body_plain'] ?? ''));
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

if ($name === '' || $eventType === '' || $subject === '' || $bodyHtml === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /admin_email_templates_edit.php' . ($isEdit ? '?id=' . $id : ''));
    exit;
}

$db = db();
$db->beginTransaction();

try {
    if ($isEdit) {
        $stmt = $db->prepare(
            'UPDATE email_templates 
             SET name = :name, event_type = :event, health_insurer_id = :insurer, 
                 subject = :subject, body_html = :html, body_plain = :plain,
                 is_active = :active, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'event' => $eventType,
            'insurer' => $healthInsurerId,
            'subject' => $subject,
            'html' => $bodyHtml,
            'plain' => $bodyPlain,
            'active' => $isActive
        ]);
        
        $action = 'atualizado';
    } else {
        $stmt = $db->prepare(
            'INSERT INTO email_templates 
             (name, event_type, health_insurer_id, subject, body_html, body_plain, is_active, created_by_user_id)
             VALUES (:name, :event, :insurer, :subject, :html, :plain, :active, :uid)'
        );
        $stmt->execute([
            'name' => $name,
            'event' => $eventType,
            'insurer' => $healthInsurerId,
            'subject' => $subject,
            'html' => $bodyHtml,
            'plain' => $bodyPlain,
            'active' => $isActive,
            'uid' => auth_user_id()
        ]);
        
        $id = (int)$db->lastInsertId();
        $action = 'criado';
    }
    
    audit_log($isEdit ? 'update' : 'create', 'email_templates', (string)$id, null, [
        'name' => $name,
        'event_type' => $eventType
    ]);
    
    $db->commit();
    
    flash_set('success', 'Template ' . $action . ' com sucesso!');
    header('Location: /admin_email_templates.php');
    exit;
    
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao salvar template: ' . $e->getMessage());
    header('Location: /admin_email_templates_edit.php' . ($isEdit ? '?id=' . $id : ''));
    exit;
}
