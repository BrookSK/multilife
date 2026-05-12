<?php

declare(strict_types=1);

/**
 * Processador de Templates WhatsApp
 * Busca, processa variáveis e envia mensagens com anexos
 */

/**
 * Buscar template por evento e operadora
 */
function whatsapp_get_template(string $event, ?int $healthInsurerId = null): ?array
{
    $sql = 'SELECT id, name, message_body FROM whatsapp_message_templates 
            WHERE event_trigger = :event AND is_active = 1';
    
    $params = ['event' => $event];
    
    if ($healthInsurerId !== null) {
        $sql .= ' AND health_insurer_id = :insurer_id';
        $params['insurer_id'] = $healthInsurerId;
    } else {
        $sql .= ' AND health_insurer_id IS NULL';
    }
    
    $sql .= ' LIMIT 1';
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $template = $stmt->fetch();
    
    return $template ?: null;
}

/**
 * Buscar anexos de um template
 */
function whatsapp_get_template_attachments(int $templateId): array
{
    $stmt = db()->prepare(
        'SELECT id, file_name, file_path, file_size, mime_type 
         FROM whatsapp_template_attachments 
         WHERE template_id = :tid 
         ORDER BY display_order ASC'
    );
    $stmt->execute(['tid' => $templateId]);
    return $stmt->fetchAll();
}

/**
 * Processar variáveis no template
 */
function whatsapp_process_variables(string $message, array $variables): string
{
    $processed = $message;
    
    foreach ($variables as $key => $value) {
        $placeholder = '{' . $key . '}';
        $processed = str_replace($placeholder, (string)$value, $processed);
    }
    
    return $processed;
}

/**
 * Enviar mensagem com template
 * 
 * @param string $phone Telefone do destinatário (formato: 5511999999999)
 * @param string $event Evento que dispara (ex: 'pre_admission_confirmation')
 * @param array $variables Variáveis para substituir no template
 * @param int|null $healthInsurerId ID da operadora (obrigatório para alguns eventos)
 * @return array ['success' => bool, 'message' => string, 'template_id' => int|null]
 */
function whatsapp_send_template(
    string $phone, 
    string $event, 
    array $variables, 
    ?int $healthInsurerId = null,
    ?int $patientId = null
): array {
    // 0. Verificar se paciente pode receber notificações
    if ($patientId !== null && $patientId > 0) {
        $guardResult = notification_guard_check_patient($patientId);
        if (!$guardResult['allowed']) {
            error_log("[WHATSAPP_TEMPLATE] Bloqueado envio para paciente #$patientId: " . $guardResult['reason']);
            return [
                'success' => false,
                'message' => 'Envio bloqueado: ' . $guardResult['reason'],
                'template_id' => null,
                'blocked' => true
            ];
        }
    }

    // 1. Buscar template
    $template = whatsapp_get_template($event, $healthInsurerId);
    
    if (!$template) {
        return [
            'success' => false,
            'message' => 'Template não encontrado para evento: ' . $event,
            'template_id' => null
        ];
    }
    
    $templateId = (int)$template['id'];
    
    // 2. Processar variáveis
    $message = whatsapp_process_variables($template['message_body'], $variables);
    
    // 3. Enviar mensagem de texto
    $textResult = evolution_send_text($phone, $message);
    
    if (!$textResult['success']) {
        return [
            'success' => false,
            'message' => 'Erro ao enviar mensagem: ' . $textResult['error'],
            'template_id' => $templateId
        ];
    }
    
    // 4. Buscar e enviar anexos
    $attachments = whatsapp_get_template_attachments($templateId);
    $attachmentErrors = [];
    
    foreach ($attachments as $file) {
        $filePath = $file['file_path'];
        
        // Verificar se arquivo existe
        if (!file_exists($filePath)) {
            $attachmentErrors[] = 'Arquivo não encontrado: ' . $file['file_name'];
            continue;
        }
        
        // Enviar mídia
        $mediaResult = evolution_send_media($phone, $filePath, $file['mime_type'], $file['file_name']);
        
        if (!$mediaResult['success']) {
            $attachmentErrors[] = 'Erro ao enviar ' . $file['file_name'] . ': ' . $mediaResult['error'];
        }
    }
    
    // 5. Retornar resultado
    $success = empty($attachmentErrors);
    $message = $success 
        ? 'Mensagem e anexos enviados com sucesso' 
        : 'Mensagem enviada, mas alguns anexos falharam: ' . implode(', ', $attachmentErrors);
    
    return [
        'success' => $success,
        'message' => $message,
        'template_id' => $templateId,
        'attachments_sent' => count($attachments) - count($attachmentErrors),
        'attachments_failed' => count($attachmentErrors)
    ];
}

/**
 * Enviar texto via Evolution API
 */
function evolution_send_text(string $phone, string $message): array
{
    $baseUrl = trim((string)admin_setting_get('evolution.base_url', ''));
    $apiKey = (string)admin_setting_get('evolution.api_key', '');
    $instance = trim((string)admin_setting_get('evolution.instance', 'multilife'));
    
    if ($baseUrl === '' || $apiKey === '') {
        return ['success' => false, 'error' => 'Evolution API não configurada'];
    }
    
    $url = rtrim($baseUrl, '/') . '/message/sendText/' . $instance;
    
    $payload = [
        'number' => $phone,
        'text' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $apiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        return ['success' => false, 'error' => 'HTTP ' . $httpCode . ': ' . $response];
    }
    
    return ['success' => true, 'response' => $response];
}

/**
 * Enviar mídia via Evolution API
 */
function evolution_send_media(string $phone, string $filePath, string $mimeType, string $fileName): array
{
    $baseUrl = trim((string)admin_setting_get('evolution.base_url', ''));
    $apiKey = (string)admin_setting_get('evolution.api_key', '');
    $instance = trim((string)admin_setting_get('evolution.instance', 'multilife'));
    
    if ($baseUrl === '' || $apiKey === '') {
        return ['success' => false, 'error' => 'Evolution API não configurada'];
    }
    
    $url = rtrim($baseUrl, '/') . '/message/sendMedia/' . $instance;
    
    // Converter arquivo para base64
    $fileContent = file_get_contents($filePath);
    if ($fileContent === false) {
        return ['success' => false, 'error' => 'Erro ao ler arquivo'];
    }
    
    $base64 = base64_encode($fileContent);
    
    $payload = [
        'number' => $phone,
        'mediatype' => strpos($mimeType, 'image/') === 0 ? 'image' : 'document',
        'mimetype' => $mimeType,
        'caption' => $fileName,
        'media' => 'data:' . $mimeType . ';base64,' . $base64
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Timeout maior para upload
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        return ['success' => false, 'error' => 'HTTP ' . $httpCode . ': ' . $response];
    }
    
    return ['success' => true, 'response' => $response];
}

/**
 * Listar todos os eventos disponíveis
 */
function whatsapp_get_available_events(): array
{
    return [
        'pre_admission_confirmation' => 'Confirmação de Pré-Admissão',
        'appointment_created' => 'Agendamento Criado',
        'appointment_confirmed' => 'Agendamento Confirmado',
        'appointment_reminder' => 'Lembrete de Agendamento',
        'authorization_approved' => 'Autorização Aprovada',
        'authorization_denied' => 'Autorização Negada',
        'document_request' => 'Solicitação de Documentos'
    ];
}

/**
 * Verificar se evento requer operadora
 */
function whatsapp_event_requires_insurer(string $event): bool
{
    return $event === 'pre_admission_confirmation';
}
