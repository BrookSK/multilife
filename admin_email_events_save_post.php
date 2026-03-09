<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: application/json; charset=utf-8');

try {
    $eventId = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $systemEvent = trim($_POST['system_event'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $sendToProfessional = isset($_POST['send_to_professional']) ? 1 : 0;
    $sendToPatient = isset($_POST['send_to_patient']) ? 1 : 0;
    
    $subjectProfessional = trim($_POST['subject_professional'] ?? '');
    $templateProfessionalHtml = trim($_POST['template_professional_html'] ?? '');
    $templateProfessionalText = trim($_POST['template_professional_text'] ?? '');
    
    $subjectPatient = trim($_POST['subject_patient'] ?? '');
    $templatePatientHtml = trim($_POST['template_patient_html'] ?? '');
    $templatePatientText = trim($_POST['template_patient_text'] ?? '');
    
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
    
    if ($sendToProfessional && empty($subjectProfessional)) {
        throw new Exception('Assunto para profissional é obrigatório');
    }
    
    if ($sendToPatient && empty($subjectPatient)) {
        throw new Exception('Assunto para paciente é obrigatório');
    }
    
    db()->beginTransaction();
    
    // Inserir ou atualizar evento
    if ($eventId) {
        $stmt = db()->prepare("
            UPDATE email_events 
            SET name = ?,
                system_event = ?,
                status = ?,
                send_to_professional = ?,
                send_to_patient = ?,
                subject_professional = ?,
                template_professional_html = ?,
                template_professional_text = ?,
                subject_patient = ?,
                template_patient_html = ?,
                template_patient_text = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([
            $name,
            $systemEvent,
            $status,
            $sendToProfessional,
            $sendToPatient,
            $sendToProfessional ? $subjectProfessional : null,
            $sendToProfessional ? $templateProfessionalHtml : null,
            $sendToProfessional ? $templateProfessionalText : null,
            $sendToPatient ? $subjectPatient : null,
            $sendToPatient ? $templatePatientHtml : null,
            $sendToPatient ? $templatePatientText : null,
            $eventId
        ]);
    } else {
        $stmt = db()->prepare("
            INSERT INTO email_events 
            (name, system_event, status, send_to_professional, send_to_patient, 
             subject_professional, template_professional_html, template_professional_text,
             subject_patient, template_patient_html, template_patient_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name,
            $systemEvent,
            $status,
            $sendToProfessional,
            $sendToPatient,
            $sendToProfessional ? $subjectProfessional : null,
            $sendToProfessional ? $templateProfessionalHtml : null,
            $sendToProfessional ? $templateProfessionalText : null,
            $sendToPatient ? $subjectPatient : null,
            $sendToPatient ? $templatePatientHtml : null,
            $sendToPatient ? $templatePatientText : null
        ]);
        $eventId = (int)db()->lastInsertId();
    }
    
    // Processar links
    if (!empty($_POST['link_name']) && is_array($_POST['link_name'])) {
        // Deletar links existentes
        $stmtDeleteLinks = db()->prepare("DELETE FROM email_event_links WHERE event_id = ?");
        $stmtDeleteLinks->execute([$eventId]);
        
        // Inserir novos links
        $linkNames = $_POST['link_name'];
        $linkUrls = $_POST['link_url'] ?? [];
        $linkRecipients = $_POST['link_recipient'] ?? [];
        
        $stmtInsertLink = db()->prepare("
            INSERT INTO email_event_links (event_id, link_name, link_url, recipient_type)
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
        $uploadDir = __DIR__ . '/uploads/email_events';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileRecipient = $_POST['file_recipient'] ?? 'both';
        $stmtInsertFile = db()->prepare("
            INSERT INTO email_event_files (event_id, file_name, file_path, file_type, file_size, recipient_type)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($_FILES['new_files']['name'] as $index => $fileName) {
            if ($_FILES['new_files']['error'][$index] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['new_files']['tmp_name'][$index];
                $fileSize = $_FILES['new_files']['size'][$index];
                $fileType = $_FILES['new_files']['type'][$index];
                
                // Gerar nome único
                $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                $uniqueName = uniqid('email_event_', true) . '.' . $extension;
                $filePath = $uploadDir . '/' . $uniqueName;
                
                if (move_uploaded_file($tmpName, $filePath)) {
                    $stmtInsertFile->execute([
                        $eventId,
                        $fileName,
                        '/uploads/email_events/' . $uniqueName,
                        $fileType,
                        $fileSize,
                        $fileRecipient
                    ]);
                }
            }
        }
    }
    
    db()->commit();
    
    header('Location: /admin_email_events.php');
    exit;
    
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    
    error_log("[EMAIL_EVENT_SAVE] Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
