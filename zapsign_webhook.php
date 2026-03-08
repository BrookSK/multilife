<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Webhook do ZapSign - Recebe notificações automáticas de eventos
// Este arquivo NÃO requer autenticação pois é chamado pelo ZapSign

// Log de debug
$logFile = __DIR__ . '/logs/zapsign_webhook.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function logWebhook($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Receber dados do webhook
$rawPayload = file_get_contents('php://input');
logWebhook("Webhook recebido: $rawPayload");

// Decodificar JSON
$payload = json_decode($rawPayload, true);

if (!$payload) {
    logWebhook("Erro: Payload inválido");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

// Validar estrutura do payload
if (!isset($payload['event']) || !isset($payload['doc_token'])) {
    logWebhook("Erro: Estrutura inválida - event ou doc_token ausente");
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$event = $payload['event'];
$docToken = $payload['doc_token'];

logWebhook("Evento: $event | Token: $docToken");

// Buscar contrato pelo token
$stmt = db()->prepare('SELECT * FROM hr_employee_contracts WHERE zapsign_doc_token = :token');
$stmt->execute(['token' => $docToken]);
$contract = $stmt->fetch();

if (!$contract) {
    logWebhook("Aviso: Contrato não encontrado para token $docToken");
    // Retornar 200 mesmo assim para não reenviar o webhook
    http_response_code(200);
    echo json_encode(['status' => 'contract_not_found']);
    exit;
}

logWebhook("Contrato encontrado: ID {$contract['id']} | Funcionário: {$contract['employee_id']}");

// Processar evento
$newStatus = null;
$signedAt = null;
$pdfUrl = null;

switch ($event) {
    case 'doc_signed':
    case 'all_signed':
        $newStatus = 'signed';
        $signedAt = date('Y-m-d H:i:s');
        
        // Extrair URL do PDF assinado se disponível
        if (isset($payload['signed_file_url'])) {
            $pdfUrl = $payload['signed_file_url'];
        } elseif (isset($payload['pdf_url'])) {
            $pdfUrl = $payload['pdf_url'];
        }
        
        logWebhook("Contrato assinado! PDF URL: " . ($pdfUrl ?? 'não disponível'));
        break;
        
    case 'doc_expired':
        $newStatus = 'expired';
        logWebhook("Contrato expirado");
        break;
        
    case 'doc_cancelled':
    case 'doc_deleted':
        $newStatus = 'cancelled';
        logWebhook("Contrato cancelado/deletado");
        break;
        
    default:
        logWebhook("Evento não tratado: $event");
        http_response_code(200);
        echo json_encode(['status' => 'event_ignored']);
        exit;
}

if ($newStatus) {
    // Atualizar status do contrato
    $updateFields = ['zapsign_status = :status'];
    $params = [
        'id' => (int)$contract['id'],
        'status' => $newStatus,
    ];
    
    if ($signedAt) {
        $updateFields[] = 'signed_at = :signed_at';
        $params['signed_at'] = $signedAt;
    }
    
    if ($pdfUrl) {
        $updateFields[] = 'pdf_signed_url = :pdf_url';
        $params['pdf_url'] = $pdfUrl;
    }
    
    $sql = 'UPDATE hr_employee_contracts SET ' . implode(', ', $updateFields) . ' WHERE id = :id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    
    logWebhook("Status atualizado para: $newStatus");
    
    // Registrar no histórico do funcionário
    if ($newStatus === 'signed') {
        $stmt = db()->prepare('SELECT full_name FROM hr_employees WHERE id = :id');
        $stmt->execute(['id' => (int)$contract['employee_id']]);
        $employee = $stmt->fetch();
        
        if ($employee) {
            $stmt = db()->prepare('SELECT name FROM zapsign_contract_templates WHERE id = :id');
            $stmt->execute(['id' => (int)$contract['template_id']]);
            $template = $stmt->fetch();
            
            $templateName = $template ? $template['name'] : 'Contrato';
            
            $historyStmt = db()->prepare('INSERT INTO hr_employee_history (employee_id, change_type, change_date, description, created_by_user_id) VALUES (:employee_id, :change_type, NOW(), :description, NULL)');
            $historyStmt->execute([
                'employee_id' => (int)$contract['employee_id'],
                'change_type' => 'outro',
                'description' => "Contrato assinado digitalmente via ZapSign: $templateName",
            ]);
            
            logWebhook("Histórico registrado para funcionário {$employee['full_name']}");
        }
    }
    
    // Criar notificação interna para RH
    try {
        $notificationMessage = '';
        $notificationType = 'info';
        
        if ($newStatus === 'signed') {
            $stmt = db()->prepare('SELECT full_name FROM hr_employees WHERE id = :id');
            $stmt->execute(['id' => (int)$contract['employee_id']]);
            $employee = $stmt->fetch();
            
            $employeeName = $employee ? $employee['full_name'] : 'Funcionário';
            $notificationMessage = "✅ Contrato assinado: $employeeName assinou o contrato digitalmente.";
            $notificationType = 'success';
        } elseif ($newStatus === 'expired') {
            $notificationMessage = "⏰ Contrato expirado: O prazo para assinatura expirou.";
            $notificationType = 'warning';
        } elseif ($newStatus === 'cancelled') {
            $notificationMessage = "❌ Contrato cancelado: O contrato foi cancelado no ZapSign.";
            $notificationType = 'error';
        }
        
        if ($notificationMessage) {
            // Buscar usuários com permissão de RH para notificar
            $stmt = db()->query("
                SELECT DISTINCT u.id
                FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.id
                INNER JOIN roles r ON r.id = ur.role_id
                INNER JOIN role_permissions rp ON rp.role_id = r.id
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE p.slug = 'hr.manage' AND u.status = 'active'
            ");
            $hrUsers = $stmt->fetchAll();
            
            foreach ($hrUsers as $user) {
                // Criar notificação (se o sistema de notificações existir)
                $notifStmt = db()->prepare('INSERT INTO notifications (user_id, type, message, link, created_at) VALUES (:user_id, :type, :message, :link, NOW())');
                $notifStmt->execute([
                    'user_id' => (int)$user['id'],
                    'type' => $notificationType,
                    'message' => $notificationMessage,
                    'link' => '/hr_contract_generate.php?employee_id=' . (int)$contract['employee_id'],
                ]);
            }
            
            logWebhook("Notificações criadas para " . count($hrUsers) . " usuários de RH");
        }
    } catch (Exception $e) {
        logWebhook("Erro ao criar notificação: " . $e->getMessage());
        // Não falhar o webhook por causa de notificação
    }
}

// Retornar sucesso
logWebhook("Webhook processado com sucesso");
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'event' => $event,
    'contract_id' => (int)$contract['id'],
    'new_status' => $newStatus,
]);
exit;
