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
                
                // Salvar/atualizar contato também
                try {
                    db()->exec("
                        CREATE TABLE IF NOT EXISTS chat_contacts (
                            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            remote_jid VARCHAR(100) NOT NULL UNIQUE,
                            contact_name VARCHAR(255) DEFAULT NULL,
                            profile_picture_url TEXT DEFAULT NULL,
                            is_group TINYINT(1) NOT NULL DEFAULT 0,
                            last_message_timestamp INT UNSIGNED DEFAULT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            PRIMARY KEY (id),
                            UNIQUE INDEX idx_remote_jid (remote_jid),
                            INDEX idx_last_message (last_message_timestamp)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    
                    $isGroup = strpos($remoteJid, '@g.us') !== false ? 1 : 0;
                    $contactName = str_replace(['@s.whatsapp.net', '@g.us'], '', $remoteJid);
                    
                    $stmtContact = db()->prepare("
                        INSERT INTO chat_contacts (remote_jid, contact_name, is_group, last_message_timestamp)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            last_message_timestamp = VALUES(last_message_timestamp),
                            updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmtContact->execute([$remoteJid, $contactName, $isGroup, $timestamp]);
                    error_log("Contato salvo/atualizado com sucesso");
                    
                    // Buscar perfil do contato da Evolution API
                    $baseUrl = admin_setting_get('evolution.base_url');
                    $apiKey = admin_setting_get('evolution.api_key');
                    $instanceName = admin_setting_get('evolution.instance');
                    
                    if ($baseUrl && $apiKey && $instanceName) {
                        try {
                            $profileUrl = $baseUrl . '/chat/fetchProfile/' . urlencode($instanceName);
                            $profilePayload = json_encode(['number' => $remoteJid]);
                            
                            $ch = curl_init($profileUrl);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $profilePayload);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'apikey: ' . $apiKey,
                                'Content-Type: application/json'
                            ]);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                            
                            $profileResponse = curl_exec($ch);
                            $profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($profileHttpCode === 200 && $profileResponse) {
                                $profileData = json_decode($profileResponse, true);
                                // API retorna 'picture' ao invés de 'profilePictureUrl'
                                $profilePic = $profileData['picture'] ?? null;
                                
                                if ($profilePic) {
                                    $updateStmt = db()->prepare("
                                        UPDATE chat_contacts 
                                        SET profile_picture_url = ?
                                        WHERE remote_jid = ?
                                    ");
                                    $updateStmt->execute([$profilePic, $remoteJid]);
                                    error_log("Foto de perfil atualizada no webhook: " . $profilePic);
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Erro ao buscar perfil no webhook: " . $e->getMessage());
                        }
                    }
                } catch (Exception $e) {
                    error_log("Erro ao salvar contato: " . $e->getMessage());
                }
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
