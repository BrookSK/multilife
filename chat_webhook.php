<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Webhook para receber mensagens da Evolution API
// Este arquivo recebe notificações quando novas mensagens chegam

// Log de debug
error_log("=== WEBHOOK CHAMADO ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Headers: " . json_encode(getallheaders()));

// Receber payload JSON
$payload = file_get_contents('php://input');
error_log("Payload recebido: " . $payload);

// Decodificar JSON
$data = json_decode($payload, true);

if (!$data) {
    error_log("ERRO: Payload JSON inválido");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Verificar se é uma mensagem
if (isset($data['event']) && $data['event'] === 'messages.upsert') {
    $messageData = $data['data'] ?? [];
    
    // Extrair informações da mensagem
    $remoteJid = $messageData['key']['remoteJid'] ?? '';
    $fromMe = $messageData['key']['fromMe'] ?? false;
    $messageText = $messageData['message']['conversation'] 
                   ?? $messageData['message']['extendedTextMessage']['text'] 
                   ?? '';
    $timestamp = $messageData['messageTimestamp'] ?? time();
    
    error_log("Mensagem recebida de: " . $remoteJid);
    error_log("Texto: " . $messageText);
    error_log("De mim: " . ($fromMe ? 'sim' : 'não'));
    
    // Salvar apenas mensagens recebidas (não enviadas por mim)
    if (!$fromMe && !empty($remoteJid) && !empty($messageText)) {
        try {
            // Garantir que tabela existe
            $tableExists = db()->query("SHOW TABLES LIKE 'chat_messages'")->fetch();
            if (!$tableExists) {
                db()->exec("
                    CREATE TABLE chat_messages (
                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        remote_jid VARCHAR(100) NOT NULL,
                        message_text TEXT NOT NULL,
                        from_me TINYINT(1) NOT NULL DEFAULT 0,
                        message_timestamp INT UNSIGNED NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (id),
                        INDEX idx_remote_jid (remote_jid),
                        INDEX idx_timestamp (message_timestamp)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // Salvar mensagem recebida no banco
            $stmt = db()->prepare("
                INSERT INTO chat_messages (remote_jid, message_text, from_me, message_timestamp)
                VALUES (?, ?, 0, ?)
            ");
            $saved = $stmt->execute([$remoteJid, $messageText, $timestamp]);
            
            if ($saved) {
                error_log("Mensagem salva no banco com sucesso");
            } else {
                error_log("ERRO: Falha ao salvar mensagem no banco");
            }
            
        } catch (Exception $e) {
            error_log("ERRO ao salvar mensagem recebida: " . $e->getMessage());
        }
    }
}

// Responder com sucesso
http_response_code(200);
echo json_encode(['status' => 'ok']);
