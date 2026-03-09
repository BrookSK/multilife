<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

header('Content-Type: application/json; charset=utf-8');

try {
    $eventId = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $systemEvent = trim($_POST['system_event'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $sendToProfessional = isset($_POST['send_to_professional']) ? 1 : 0;
    $sendToPatient = isset($_POST['send_to_patient']) ? 1 : 0;
    $templateProfessional = trim($_POST['template_professional'] ?? '');
    $templatePatient = trim($_POST['template_patient'] ?? '');
    
    // Validações
    if (empty($name)) {
        throw new Exception('Nome do evento é obrigatório');
    }
    
    if (empty($systemEvent)) {
        throw new Exception('Evento do sistema é obrigatório');
    }
    
    if (!$sendToProfessional && !$sendToPatient) {
        throw new Exception('Selecione pelo menos um destinatário');
    }
    
    if ($sendToProfessional && empty($templateProfessional)) {
        throw new Exception('Template para profissional é obrigatório');
    }
    
    if ($sendToPatient && empty($templatePatient)) {
        throw new Exception('Template para paciente é obrigatório');
    }
    
    db()->beginTransaction();
    
    // Inserir ou atualizar evento
    if ($eventId) {
        $stmt = db()->prepare("
            UPDATE whatsapp_events 
            SET name = ?,
                system_event = ?,
                status = ?,
                send_to_professional = ?,
                send_to_patient = ?,
                template_professional = ?,
                template_patient = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $name,
            $systemEvent,
            $status,
            $sendToProfessional,
            $sendToPatient,
            $sendToProfessional ? $templateProfessional : null,
            $sendToPatient ? $templatePatient : null,
            $eventId
        ]);
    } else {
        $stmt = db()->prepare("
            INSERT INTO whatsapp_events 
            (name, system_event, status, send_to_professional, send_to_patient, template_professional, template_patient)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            $systemEvent,
            $status,
            $sendToProfessional,
            $sendToPatient,
            $sendToProfessional ? $templateProfessional : null,
            $sendToPatient ? $templatePatient : null
        ]);
        $eventId = (int)db()->lastInsertId();
    }
    
    // Processar links
    if (!empty($_POST['link_name']) && is_array($_POST['link_name'])) {
        // Deletar links existentes
        $stmtDeleteLinks = db()->prepare("DELETE FROM whatsapp_event_links WHERE event_id = ?");
        $stmtDeleteLinks->execute([$eventId]);
        
        // Inserir novos links
        $linkNames = $_POST['link_name'];
        $linkUrls = $_POST['link_url'] ?? [];
        $linkRecipients = $_POST['link_recipient'] ?? [];
        
        $stmtInsertLink = db()->prepare("
            INSERT INTO whatsapp_event_links (event_id, link_name, link_url, recipient_type)
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($linkNames as $index => $linkName) {
            $linkName = trim($linkName);
            $linkUrl = trim($linkUrls[$index] ?? '');
            $linkRecipient = $linkRecipients[$index] ?? 'both';
            
            if (!empty($linkName) && !empty($linkUrl)) {
                $stmtInsertLink->execute([$eventId, $linkName, $linkUrl, $linkRecipient]);
            }
        }
    }
    
    // Processar upload de arquivos
    if (!empty($_FILES['new_files']['name'][0])) {
        $uploadDir = __DIR__ . '/uploads/whatsapp_events';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileRecipient = $_POST['file_recipient'] ?? 'both';
        $stmtInsertFile = db()->prepare("
            INSERT INTO whatsapp_event_files (event_id, file_name, file_path, file_type, file_size, recipient_type)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($_FILES['new_files']['name'] as $index => $fileName) {
            if ($_FILES['new_files']['error'][$index] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['new_files']['tmp_name'][$index];
                $fileSize = $_FILES['new_files']['size'][$index];
                $fileType = $_FILES['new_files']['type'][$index];
                
                // Gerar nome único
                $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                $uniqueName = uniqid('event_', true) . '.' . $extension;
                $filePath = $uploadDir . '/' . $uniqueName;
                
                if (move_uploaded_file($tmpName, $filePath)) {
                    $stmtInsertFile->execute([
                        $eventId,
                        $fileName,
                        '/uploads/whatsapp_events/' . $uniqueName,
                        $fileType,
                        $fileSize,
                        $fileRecipient
                    ]);
                }
            }
        }
    }
    
    db()->commit();
    
    echo json_encode([
        'success' => true,
        'event_id' => $eventId,
        'redirect' => '/admin_whatsapp_events.php'
    ]);
    
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    
    error_log("[WHATSAPP_EVENT_SAVE] Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
