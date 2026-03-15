<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings');

require_once __DIR__ . '/app/whatsapp_template_processor.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$isEdit = $id > 0;

$name = trim((string)($_POST['name'] ?? ''));
$eventTrigger = trim((string)($_POST['event_trigger'] ?? ''));
$healthInsurerId = isset($_POST['health_insurer_id']) && $_POST['health_insurer_id'] !== '' ? (int)$_POST['health_insurer_id'] : null;
$messageBody = trim((string)($_POST['message_body'] ?? ''));
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

if ($name === '' || $eventTrigger === '' || $messageBody === '') {
    flash_set('error', 'Preencha todos os campos obrigatórios.');
    header('Location: /admin_whatsapp_templates_edit.php' . ($isEdit ? '?id=' . $id : ''));
    exit;
}

if (whatsapp_event_requires_insurer($eventTrigger) && $healthInsurerId === null) {
    flash_set('error', 'Operadora é obrigatória para o evento "Confirmação de Pré-Admissão".');
    header('Location: /admin_whatsapp_templates_edit.php' . ($isEdit ? '?id=' . $id : ''));
    exit;
}

$db = db();
$db->beginTransaction();

try {
    if ($isEdit) {
        $stmt = $db->prepare(
            'UPDATE whatsapp_message_templates 
             SET name = :name, event_trigger = :event, health_insurer_id = :insurer, 
                 message_body = :body, is_active = :active, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'event' => $eventTrigger,
            'insurer' => $healthInsurerId,
            'body' => $messageBody,
            'active' => $isActive
        ]);
        
        $templateId = $id;
        $action = 'atualizado';
    } else {
        $stmt = $db->prepare(
            'INSERT INTO whatsapp_message_templates 
             (name, event_trigger, health_insurer_id, message_body, is_active, created_by_user_id)
             VALUES (:name, :event, :insurer, :body, :active, :uid)'
        );
        $stmt->execute([
            'name' => $name,
            'event' => $eventTrigger,
            'insurer' => $healthInsurerId,
            'body' => $messageBody,
            'active' => $isActive,
            'uid' => auth_user_id()
        ]);
        
        $templateId = (int)$db->lastInsertId();
        $action = 'criado';
    }
    
    // Processar uploads de arquivos
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $uploadDir = __DIR__ . '/uploads/whatsapp_templates/' . $templateId;
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $currentCount = (int)$db->query(
            'SELECT COUNT(*) FROM whatsapp_template_attachments WHERE template_id = ' . $templateId
        )->fetchColumn();
        
        $maxFiles = 5;
        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg',
            'image/png',
            'text/plain'
        ];
        $maxSize = 10 * 1024 * 1024; // 10MB
        
        $uploadedCount = 0;
        
        foreach ($_FILES['attachments']['name'] as $key => $fileName) {
            if ($_FILES['attachments']['error'][$key] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            if ($currentCount + $uploadedCount >= $maxFiles) {
                flash_set('warning', 'Limite de 5 arquivos atingido. Alguns arquivos não foram enviados.');
                break;
            }
            
            $fileSize = $_FILES['attachments']['size'][$key];
            $fileTmp = $_FILES['attachments']['tmp_name'][$key];
            
            if ($fileSize > $maxSize) {
                flash_set('warning', 'Arquivo ' . $fileName . ' excede 10MB e foi ignorado.');
                continue;
            }
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $fileTmp);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedMimes, true)) {
                flash_set('warning', 'Tipo de arquivo ' . $fileName . ' não permitido.');
                continue;
            }
            
            $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            $uniqueName = time() . '_' . $safeFileName;
            $destination = $uploadDir . '/' . $uniqueName;
            
            if (move_uploaded_file($fileTmp, $destination)) {
                $displayOrder = $currentCount + $uploadedCount + 1;
                
                $stmt = $db->prepare(
                    'INSERT INTO whatsapp_template_attachments 
                     (template_id, file_name, file_path, file_size, mime_type, display_order)
                     VALUES (:tid, :fname, :fpath, :fsize, :mime, :order)'
                );
                $stmt->execute([
                    'tid' => $templateId,
                    'fname' => $fileName,
                    'fpath' => $destination,
                    'fsize' => $fileSize,
                    'mime' => $mimeType,
                    'order' => $displayOrder
                ]);
                
                $uploadedCount++;
            }
        }
        
        if ($uploadedCount > 0) {
            flash_set('success', 'Template ' . $action . ' com sucesso! ' . $uploadedCount . ' arquivo(s) anexado(s).');
        }
    }
    
    if (!isset($uploadedCount) || $uploadedCount === 0) {
        flash_set('success', 'Template ' . $action . ' com sucesso!');
    }
    
    audit_log($isEdit ? 'update' : 'create', 'whatsapp_message_templates', (string)$templateId, null, [
        'name' => $name,
        'event' => $eventTrigger
    ]);
    
    $db->commit();
    
    header('Location: /admin_whatsapp_templates.php');
    exit;
    
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Erro ao salvar template: ' . $e->getMessage());
    header('Location: /admin_whatsapp_templates_edit.php' . ($isEdit ? '?id=' . $id : ''));
    exit;
}
