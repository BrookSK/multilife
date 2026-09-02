<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Receber payload JSON
$payload = file_get_contents('php://input');
error_log('[WEBHOOK] chamado method:' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' payload_len:' . strlen($payload) . ' payload_sample:' . substr($payload, 0, 200));

$data = json_decode($payload, true);

if (!$data) {
    error_log('[WEBHOOK] payload invalido ou vazio - json_last_error:' . json_last_error_msg());
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

$event = $data['event'] ?? '';
// Normalizar: MESSAGES_UPSERT → messages.upsert (diferentes versões da Evolution API)
$event = strtolower(str_replace('_', '.', $event));
error_log("[WEBHOOK] event:'$event' instance:'" . ($data['instance'] ?? '') . "'");

// Guardar o nome da instância globalmente para uso nas funções de save
$GLOBALS['_webhookInstanceName'] = $data['instance'] ?? null;

// Ignorar eventos que não precisam de processamento
$ignoredEvents = [
    'connection.update',
    'presence.update',
    'chats.set',
    'chats.upsert',
    'chats.update',
    'chats.delete',
    'contacts.set',
    'groups.update',
    'groups.upsert',
    'group.participants.update',
    'messages.set',
    'messages.update',
    'call',
    'new.jwt.token',
    'application.startup',
    'qrcode.updated',
];

if (in_array($event, $ignoredEvents)) {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// Função auxiliar para garantir tabelas
function ensureChatTables(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        remote_jid VARCHAR(100) NOT NULL,
        message_text TEXT NOT NULL,
        from_me TINYINT(1) NOT NULL DEFAULT 0,
        message_timestamp INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_remote_jid (remote_jid),
        INDEX idx_timestamp (message_timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS chat_contacts (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS chat_reactions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        remote_jid VARCHAR(100) NOT NULL,
        message_id VARCHAR(255) NOT NULL,
        reactor_jid VARCHAR(100) NOT NULL,
        emoji VARCHAR(20) NOT NULL,
        reaction_timestamp INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_message_id (message_id),
        INDEX idx_remote_jid (remote_jid),
        UNIQUE INDEX idx_unique_reaction (message_id, reactor_jid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Normalizar JID para evitar duplicação de chats
// Remove sufixos e garante formato consistente: apenas número + @s.whatsapp.net (ou @g.us para grupos)
function normalizeJid(string $jid): string {
    // Extrair apenas o número base (sem sufixos)
    $numberOnly = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us|broadcast)$/', '', $jid);
    
    // Se é grupo, manter @g.us
    if (strpos($jid, '@g.us') !== false) {
        return $numberOnly . '@g.us';
    }
    
    // Para números individuais, sempre usar @s.whatsapp.net (padrão)
    return $numberOnly . '@s.whatsapp.net';
}

// Função para fazer download de mídia externa e salvar localmente
function downloadMedia(string $externalUrl, string $filename, string $mimeType): ?string {
    try {
        error_log("[DOWNLOAD_MEDIA] === INICIANDO DOWNLOAD ===");
        error_log("[DOWNLOAD_MEDIA] URL: $externalUrl");
        error_log("[DOWNLOAD_MEDIA] Filename: $filename");
        error_log("[DOWNLOAD_MEDIA] MIME: $mimeType");
        
        // Criar diretório se não existir
        $uploadDir = __DIR__ . '/uploads/chat_media/' . date('Y-m');
        if (!is_dir($uploadDir)) {
            error_log("[DOWNLOAD_MEDIA] Criando diretório: $uploadDir");
            if (!mkdir($uploadDir, 0755, true)) {
                error_log("[DOWNLOAD_MEDIA] ERRO: Falha ao criar diretório");
                return null;
            }
        }
        
        // Gerar nome único
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if (empty($extension)) {
            // Tentar extrair extensão do MIME type
            $mimeToExt = [
                'audio/ogg' => 'ogg',
                'audio/mpeg' => 'mp3',
                'audio/mp4' => 'm4a',
                'audio/aac' => 'aac',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'video/mp4' => 'mp4',
                'video/webm' => 'webm',
                'application/pdf' => 'pdf',
            ];
            $extension = $mimeToExt[$mimeType] ?? 'bin';
        }
        
        $uniqueFilename = uniqid('chat_', true) . '.' . $extension;
        $localPath = $uploadDir . '/' . $uniqueFilename;
        
        error_log("[DOWNLOAD_MEDIA] Path local: $localPath");
        
        // Fazer download com contexto customizado (timeout maior, aceitar qualquer conteúdo)
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'follow_location' => true,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);
        
        error_log("[DOWNLOAD_MEDIA] Iniciando download...");
        $content = @file_get_contents($externalUrl, false, $context);
        
        if ($content === false || empty($content)) {
            $error = error_get_last();
            error_log("[DOWNLOAD_MEDIA] ERRO: Falha ao baixar arquivo - " . ($error['message'] ?? 'unknown'));
            return null;
        }
        
        $contentSize = strlen($content);
        error_log("[DOWNLOAD_MEDIA] Download concluído: $contentSize bytes");
        
        // Validar se o conteúdo não é HTML de erro
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<html') !== false) {
            error_log("[DOWNLOAD_MEDIA] ERRO: Conteúdo baixado é HTML, não mídia válida");
            return null;
        }
        
        // Validar tamanho mínimo (evitar arquivos vazios ou muito pequenos)
        if ($contentSize < 100) {
            error_log("[DOWNLOAD_MEDIA] ERRO: Arquivo muito pequeno ($contentSize bytes), provavelmente corrompido");
            return null;
        }
        
        // Validar magic bytes para imagens
        if (strpos($mimeType, 'image/') === 0) {
            $magicBytes = substr($content, 0, 4);
            $isValidImage = false;
            
            // JPEG: FF D8 FF
            if (substr($magicBytes, 0, 3) === "\xFF\xD8\xFF") {
                $isValidImage = true;
            }
            // PNG: 89 50 4E 47
            elseif ($magicBytes === "\x89\x50\x4E\x47") {
                $isValidImage = true;
            }
            // GIF: 47 49 46 38
            elseif (substr($magicBytes, 0, 3) === "\x47\x49\x46") {
                $isValidImage = true;
            }
            // WebP: 52 49 46 46
            elseif ($magicBytes === "\x52\x49\x46\x46") {
                $isValidImage = true;
            }
            
            if (!$isValidImage) {
                error_log("[DOWNLOAD_MEDIA] ERRO: Arquivo não é uma imagem válida (magic bytes incorretos)");
                error_log("[DOWNLOAD_MEDIA] Magic bytes: " . bin2hex($magicBytes));
                error_log("[DOWNLOAD_MEDIA] Primeiros 100 bytes: " . substr($content, 0, 100));
                return null;
            }
        }
        
        // Salvar arquivo
        $saved = file_put_contents($localPath, $content);
        
        if ($saved === false) {
            error_log("[DOWNLOAD_MEDIA] ERRO: Falha ao salvar arquivo no disco");
            return null;
        }
        
        // Verificar se arquivo foi salvo
        if (!file_exists($localPath)) {
            error_log("[DOWNLOAD_MEDIA] ERRO: Arquivo não existe após salvar");
            return null;
        }
        
        $localUrl = '/uploads/chat_media/' . date('Y-m') . '/' . $uniqueFilename;
        error_log("[DOWNLOAD_MEDIA] ✅ SUCESSO - Arquivo salvo: $localPath");
        error_log("[DOWNLOAD_MEDIA] ✅ URL local: $localUrl");
        error_log("[DOWNLOAD_MEDIA] ✅ Tamanho: " . number_format($saved / 1024, 2) . " KB");
        
        return $localUrl;
    } catch (Throwable $e) {
        error_log("[DOWNLOAD_MEDIA] ERRO EXCEPTION: " . $e->getMessage());
        error_log("[DOWNLOAD_MEDIA] Stack trace: " . $e->getTraceAsString());
        return null;
    }
}

function saveMessage(string $remoteJid, string $text, int $fromMe, int $timestamp, array $mediaData = [], array $extraData = []): void {
    ensureChatTables();
    
    // NORMALIZAR JID para evitar duplicação
    $normalizedJid = normalizeJid($remoteJid);
    
    $messageType = $mediaData['type'] ?? 'text';
    $mediaUrl = $mediaData['url'] ?? null;
    $base64Data = $mediaData['base64'] ?? null;
    $mediaMimeType = $mediaData['mime_type'] ?? null;
    $mediaFilename = $mediaData['filename'] ?? null;
    $mediaSize = $mediaData['size'] ?? null;
    $audioTranscription = $mediaData['transcription'] ?? null;
    $thumbnailUrl = $mediaData['thumbnail'] ?? null;
    
    // Novos campos: quoted, mentions, sender, external_id
    $quotedMessageId = $extraData['quoted_message_id'] ?? null;
    $quotedMessageText = $extraData['quoted_message_text'] ?? null;
    $quotedMessageSender = $extraData['quoted_message_sender'] ?? null;
    $mentionedJids = $extraData['mentioned_jids'] ?? null; // JSON string
    $senderName = $extraData['sender_name'] ?? null;
    $participantJid = $extraData['participant_jid'] ?? null;
    $externalMessageId = $extraData['external_message_id'] ?? null;
    
    // Se tem base64, salvar diretamente (mais confiável que URL)
    if ($messageType !== 'text' && !empty($base64Data)) {
        error_log("[SAVE_MSG] Base64 detectado, salvando mídia localmente...");
        
        // Decodificar base64
        $binaryData = base64_decode($base64Data);
        
        if ($binaryData !== false && strlen($binaryData) > 0) {
            // Criar diretório
            $uploadDir = __DIR__ . '/uploads/chat_media/' . date('Y-m');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Gerar nome único
            $extension = pathinfo($mediaFilename ?? 'file', PATHINFO_EXTENSION);
            if (empty($extension)) {
                $mimeToExt = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'audio/ogg' => 'ogg',
                ];
                $extension = $mimeToExt[$mediaMimeType] ?? 'jpg';
            }
            
            $uniqueFilename = uniqid('chat_', true) . '.' . $extension;
            $localPath = $uploadDir . '/' . $uniqueFilename;
            
            // Salvar arquivo
            file_put_contents($localPath, $binaryData);
            
            $mediaUrl = '/uploads/chat_media/' . date('Y-m') . '/' . $uniqueFilename;
            error_log("[SAVE_MSG] ✅ Mídia salva via base64: $mediaUrl (" . strlen($binaryData) . " bytes)");
        } else {
            error_log("[SAVE_MSG] ERRO: Base64 inválido");
            $mediaUrl = null;
            $messageText = $messageText ?: '[Mídia não disponível]';
        }
    }
    // Se não tem base64, tentar URL
    elseif ($messageType !== 'text' && !empty($mediaUrl)) {
        $isExternalUrl = (strpos($mediaUrl, 'http://') === 0 || strpos($mediaUrl, 'https://') === 0);
        $isRelativeUrl = (strpos($mediaUrl, '/') !== 0 && !$isExternalUrl);
        
        if ($isRelativeUrl) {
            // URL relativa da Evolution API (ex: uploads/chat_media/...)
            // Adicionar barra inicial para tornar absoluta
            error_log("[SAVE_MSG] URL relativa detectada, adicionando barra inicial: $mediaUrl");
            $mediaUrl = '/' . $mediaUrl;
        } elseif ($isExternalUrl) {
            // URLs do WhatsApp (mmg.whatsapp.net) são criptografadas - usar Evolution API para descriptografar
            if (str_contains($mediaUrl, 'mmg.whatsapp.net') || str_contains($mediaUrl, '.enc')) {
                error_log("[SAVE_MSG] URL criptografada do WhatsApp detectada, usando Evolution API...");
                try {
                    $api = new EvolutionApiV1();
                    $mediaEndpoint = $api->getBaseUrl() . '/chat/getBase64FromMediaMessage/' . urlencode($api->getInstance());
                    
                    global $currentMessageData;
                    if (!empty($currentMessageData)) {
                        $chMedia = curl_init($mediaEndpoint);
                        curl_setopt($chMedia, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($chMedia, CURLOPT_POST, true);
                        curl_setopt($chMedia, CURLOPT_POSTFIELDS, json_encode(['message' => $currentMessageData, 'convertToMp4' => false]));
                        curl_setopt($chMedia, CURLOPT_HTTPHEADER, ['apikey: ' . $api->getApiKey(), 'Content-Type: application/json']);
                        curl_setopt($chMedia, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($chMedia, CURLOPT_TIMEOUT, 30);
                        $mediaResp = curl_exec($chMedia);
                        $mediaCode = curl_getinfo($chMedia, CURLINFO_HTTP_CODE);
                        curl_close($chMedia);
                        
                        if ($mediaCode >= 200 && $mediaCode < 300) {
                            $mediaResult = json_decode($mediaResp, true);
                            $b64 = (string)($mediaResult['base64'] ?? '');
                            if ($b64 !== '') {
                                $binaryData = base64_decode($b64);
                                if ($binaryData !== false && strlen($binaryData) > 0) {
                                    $uploadDir = __DIR__ . '/uploads/chat_media/' . date('Y-m');
                                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
                                    $ext = pathinfo($mediaFilename ?? 'media', PATHINFO_EXTENSION) ?: 'ogg';
                                    $uniqueFilename = uniqid('chat_', true) . '.' . $ext;
                                    file_put_contents($uploadDir . '/' . $uniqueFilename, $binaryData);
                                    $mediaUrl = '/uploads/chat_media/' . date('Y-m') . '/' . $uniqueFilename;
                                    error_log("[SAVE_MSG] ✅ Mídia descriptografada via Evolution API: $mediaUrl (" . strlen($binaryData) . " bytes)");
                                } else {
                                    $mediaUrl = null;
                                    $messageText = $messageText ?: '[Mídia não disponível]';
                                }
                            } else {
                                $mediaUrl = null;
                                $messageText = $messageText ?: '[Mídia não disponível]';
                            }
                        } else {
                            error_log("[SAVE_MSG] Evolution getBase64 falhou HTTP $mediaCode");
                            $mediaUrl = null;
                            $messageText = $messageText ?: '[Mídia não disponível]';
                        }
                    } else {
                        $mediaUrl = null;
                        $messageText = $messageText ?: '[Mídia não disponível]';
                    }
                } catch (Throwable $e) {
                    error_log("[SAVE_MSG] Erro Evolution API: " . $e->getMessage());
                    $mediaUrl = null;
                    $messageText = $messageText ?: '[Mídia não disponível]';
                }
            } else {
                // URL externa não-WhatsApp, fazer download normal
                error_log("[SAVE_MSG] Mídia externa detectada, fazendo download...");
                $localUrl = downloadMedia($mediaUrl, $mediaFilename ?? 'media', $mediaMimeType ?? 'application/octet-stream');
                if ($localUrl !== null) {
                    $mediaUrl = $localUrl;
                    error_log("[SAVE_MSG] Mídia salva localmente: $localUrl");
                } else {
                    $mediaUrl = null;
                    $messageText = $messageText ?: '[Mídia não disponível]';
                }
            }
        }
    }
    
    error_log("[SAVE_MSG] Original JID: '$remoteJid' | Normalized: '$normalizedJid' | fromMe: $fromMe | type: '$messageType' | text: '" . substr($text, 0, 30) . "'");
    error_log("[SAVE_MSG] MEDIA DATA - URL: " . ($mediaUrl ?? 'NULL') . " | MIME: " . ($mediaMimeType ?? 'NULL') . " | Filename: " . ($mediaFilename ?? 'NULL') . " | Size: " . ($mediaSize ?? 'NULL'));
    
    $stmt = db()->prepare("
        INSERT INTO chat_messages 
        (remote_jid, instance_name, message_text, message_type, media_url, media_mime_type, media_filename, media_size, audio_transcription, thumbnail_url, from_me, message_timestamp, quoted_message_id, quoted_message_text, quoted_message_sender, mentioned_jids, sender_name, participant_jid, external_message_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $webhookInstanceName = $data['instance'] ?? ($GLOBALS['_webhookInstanceName'] ?? null);
    $stmt->execute([
        $normalizedJid, 
        $webhookInstanceName,
        $text, 
        $messageType,
        $mediaUrl,
        $mediaMimeType,
        $mediaFilename,
        $mediaSize,
        $audioTranscription,
        $thumbnailUrl,
        $fromMe, 
        $timestamp,
        $quotedMessageId,
        $quotedMessageText,
        $quotedMessageSender,
        $mentionedJids,
        $senderName,
        $participantJid,
        $externalMessageId,
    ]);
    
    error_log("[SAVE_MSG] Mensagem salva no banco - ID: " . db()->lastInsertId());

    // Atualizar contato com preview da última mensagem
    $isGroup = strpos($normalizedJid, '@g.us') !== false ? 1 : 0;
    $contactName = str_replace(['@s.whatsapp.net', '@g.us', '@lid'], '', $normalizedJid);
    
    // Usar senderName (pushName) como nome do contato se disponível e não é grupo
    // Se fromMe=true, NÃO atualizar o nome do contato (seria o nosso próprio nome)
    $displayName = (!$fromMe && $senderName) ? $senderName : $contactName;
    
    $lastMessageText = $text;
    if ($messageType !== 'text' && empty($text)) {
        $lastMessageText = match($messageType) {
            'audio' => '[Áudio]',
            'image' => '[Imagem]',
            'video' => '[Vídeo]',
            'document' => '[Documento]',
            default => '[Mídia]'
        };
    }

    error_log("[SAVE_CONTACT] Salvando/atualizando contato - normalizedJid: '$normalizedJid' | displayName: '$displayName' | isGroup: $isGroup");

    $stmtContact = db()->prepare("
        INSERT INTO chat_contacts (remote_jid, instance_name, contact_name, is_group, last_message_timestamp, last_message_text, last_message_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            contact_name = CASE 
                WHEN VALUES(contact_name) != '' AND VALUES(contact_name) != REPLACE(REPLACE(REPLACE(remote_jid, '@s.whatsapp.net', ''), '@g.us', ''), '@lid', '')
                THEN VALUES(contact_name) 
                ELSE COALESCE(contact_name, VALUES(contact_name))
            END,
            instance_name = COALESCE(VALUES(instance_name), instance_name),
            last_message_timestamp = VALUES(last_message_timestamp),
            last_message_text = VALUES(last_message_text),
            last_message_type = VALUES(last_message_type),
            updated_at = CURRENT_TIMESTAMP
    ");
    $webhookInstanceName = $data['instance'] ?? ($GLOBALS['_webhookInstanceName'] ?? null);
    $stmtContact->execute([$normalizedJid, $webhookInstanceName, $displayName, $isGroup, $timestamp, $lastMessageText, $messageType]);
    
    // REATIVAR CONVERSA: se a mensagem é do CLIENTE (fromMe=0) e o contato estava
    // marcado como 'resolvido' (concluído), volta para 'aguardando' para reentrar no fluxo de atendimento.
    if (!$fromMe) {
        try {
            $stmtReactivate = db()->prepare("
                UPDATE chat_contacts
                SET status = 'aguardando', updated_at = CURRENT_TIMESTAMP
                WHERE remote_jid = ? AND status = 'resolvido'
            ");
            $stmtReactivate->execute([$normalizedJid]);
            if ($stmtReactivate->rowCount() > 0) {
                error_log("[SAVE_CONTACT] Conversa reativada (resolvido -> aguardando): '$normalizedJid'");
            }
        } catch (Exception $e) {
            error_log("[SAVE_CONTACT] Erro ao reativar conversa: " . $e->getMessage());
        }
    }
    
    error_log("[SAVE_CONTACT] Contato salvo com sucesso - normalizedJid: '$normalizedJid'");
}

// Tipos de mensagem que são sistema/protocolo e devem ser ignorados
function isSystemMessageType(array $message): bool {
    $systemTypes = [
        'protocolMessage',
        'ephemeralMessage',
        'senderKeyDistributionMessage',
        'pollCreationMessage',
        'pollUpdateMessage',
        'callLogMessage',
        'requestPhoneNumberMessage',
        'encReactionMessage',
    ];
    // reactionMessage removido - agora é processado como reação
    // messageContextInfo removido - é metadados de criptografia, não mensagem de sistema
    foreach ($systemTypes as $type) {
        if (isset($message[$type])) return true;
    }
    return false;
}

// Textos de sistema conhecidos do WhatsApp
function isSystemText(string $text): bool {
    $systemPatterns = [
        'Aguardando mensagem',
        'Waiting for message',
        'Essa mensagem foi eliminada',
        'This message was deleted',
        'Uma mensagem foi eliminada',
    ];
    foreach ($systemPatterns as $pattern) {
        if (stripos($text, $pattern) !== false) return true;
    }
    return false;
}

// Processar mensagens recebidas
// Evolution API V1: data.messages[] é um array de mensagens
if ($event === 'messages.upsert') {
    $rawData = $data['data'] ?? [];

    // Log da estrutura para diagnóstico
    $dataKeys = is_array($rawData) ? array_keys($rawData) : gettype($rawData);
    error_log('[WEBHOOK] messages.upsert data keys: ' . json_encode($dataKeys));
    error_log('[WEBHOOK] messages.upsert data sample: ' . substr(json_encode($rawData), 0, 300));

    // Detectar formato:
    // Formato A (V1 array direto): data = [{key:..., message:...}, ...]
    // Formato B (obj com messages): data = {messages:[...], type:"notify"}
    // Formato C (V2 obj único):     data = {key:..., message:...}
    if (is_array($rawData) && isset($rawData[0]) && is_array($rawData[0])) {
        $msgList = $rawData; // Formato A
    } elseif (isset($rawData['messages']) && is_array($rawData['messages'])) {
        $msgList = $rawData['messages']; // Formato B
    } elseif (isset($rawData['key'])) {
        $msgList = [$rawData]; // Formato C
    } else {
        $msgList = [];
        error_log('[WEBHOOK] messages.upsert: formato nao reconhecido');
    }

    error_log('[WEBHOOK] messages.upsert recebido: ' . count($msgList) . ' mensagem(ns)');

    foreach ($msgList as $messageData) {
        // LOG COMPLETO DO PAYLOAD PARA DIAGNÓSTICO
        error_log("[WEBHOOK] FULL MESSAGE DATA: " . json_encode($messageData));
        
        // Disponibilizar para fallback de download de mídia
        global $currentMessageData;
        $currentMessageData = $messageData;
        
        $remoteJid   = $messageData['key']['remoteJid'] ?? '';
        $senderPn    = $messageData['key']['senderPn'] ?? ''; // Número real do remetente
        $fromMe      = (bool)($messageData['key']['fromMe'] ?? false);
        $participant = $messageData['key']['participant'] ?? '';
        $participantPn = $messageData['key']['participantPn'] ?? ($messageData['key']['participantAlt'] ?? ''); // Número real do participante (em vez de LID)
        $externalMsgId = $messageData['key']['id'] ?? '';
        $pushName    = $messageData['pushName'] ?? '';
        $msgPayload  = $messageData['message'] ?? [];
        $messageText = $msgPayload['conversation']
                       ?? $msgPayload['extendedTextMessage']['text']
                       ?? '';
        $timestamp   = (int)($messageData['messageTimestamp'] ?? time());

        // ===== TRATAR REAÇÕES =====
        if (isset($msgPayload['reactionMessage'])) {
            $reaction = $msgPayload['reactionMessage'];
            $reactionKey = $reaction['key'] ?? [];
            $reactionMsgId = $reactionKey['id'] ?? '';
            $reactionEmoji = $reaction['text'] ?? '';
            // Quem reagiu: usar participantPn (número real) se disponível, senão participant (LID)
            $reactorJid = $fromMe ? 'me' : ($participantPn ?: $participant ?: $remoteJid);
            
            error_log("[WEBHOOK] REAÇÃO recebida: emoji='$reactionEmoji' msgId='$reactionMsgId' reactor='$reactorJid' participantPn='$participantPn' participant='$participant'");
            
            if (!empty($reactionMsgId)) {
                try {
                    ensureChatTables();
                    $normalizedChatJid = normalizeJid($remoteJid);
                    
                    if (empty($reactionEmoji)) {
                        // Reação vazia = remoção de reação
                        $stmtDel = db()->prepare("DELETE FROM chat_reactions WHERE message_id = ? AND reactor_jid = ?");
                        $stmtDel->execute([$reactionMsgId, $reactorJid]);
                        error_log("[WEBHOOK] Reação removida: msg='$reactionMsgId' reactor='$reactorJid'");
                    } else {
                        // Inserir ou atualizar reação
                        $stmtReact = db()->prepare("
                            INSERT INTO chat_reactions (remote_jid, message_id, reactor_jid, emoji, reaction_timestamp)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), reaction_timestamp = VALUES(reaction_timestamp)
                        ");
                        $stmtReact->execute([$normalizedChatJid, $reactionMsgId, $reactorJid, $reactionEmoji, $timestamp]);
                        error_log("[WEBHOOK] Reação salva: emoji='$reactionEmoji' msg='$reactionMsgId' reactor='$reactorJid'");
                        
                        // ===== VERIFICAR SE É REAÇÃO A UMA MENSAGEM DE CAPTAÇÃO =====
                        $isGroupChat = strpos($remoteJid, '@g.us') !== false;
                        if ($isGroupChat && !$fromMe) {
                            try {
                                // Buscar se a mensagem reagida é uma captação (pelo external_message_id)
                                $stmtDispatch = db()->prepare("
                                    SELECT dl.id, dl.demand_id, dl.capture_token, d.title, d.specialty, d.status as demand_status
                                    FROM demand_dispatch_logs dl
                                    LEFT JOIN demands d ON d.id = dl.demand_id
                                    WHERE dl.external_message_id = ?
                                    LIMIT 1
                                ");
                                $stmtDispatch->execute([$reactionMsgId]);
                                $dispatchRow = $stmtDispatch->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$dispatchRow) {
                                    error_log("[WEBHOOK] Reação em grupo mas msg '$reactionMsgId' NÃO é captação");
                                } elseif (in_array($dispatchRow['demand_status'], ['admitido', 'concluido', 'cancelado'])) {
                                    error_log("[WEBHOOK] Captação #{$dispatchRow['demand_id']} já encerrada (status: {$dispatchRow['demand_status']})");
                                } else {
                                    // É uma captação ativa! Registrar interesse do profissional
                                    $demandId = (int)$dispatchRow['demand_id'];
                                    $demandTitle = $dispatchRow['title'] ?? 'Captação #' . $demandId;
                                    $specialty = $dispatchRow['specialty'] ?? '';
                                    
                                    // Extrair telefone limpo do reactor
                                    // Preferir participantAlt (número real) sobre participant (LID)
                                    $reactorForPhone = $participantPn ?: $participant ?: $reactorJid;
                                    $cleanPhone = preg_replace('/[:@].+$/', '', $reactorForPhone);
                                    $cleanPhone = preg_replace('/[^0-9]/', '', $cleanPhone);
                                    
                                    // Se cleanPhone parece ser um LID (>13 dígitos), não é telefone real
                                    if (strlen($cleanPhone) > 13) {
                                        $cleanPhone = '';
                                        // Tentar extrair de participantAlt diretamente do payload
                                        $altJid = $messageData['key']['participantAlt'] ?? '';
                                        if ($altJid !== '') {
                                            $cleanPhone = preg_replace('/[:@].+$/', '', $altJid);
                                            $cleanPhone = preg_replace('/[^0-9]/', '', $cleanPhone);
                                        }
                                    }
                                    $phoneJid = $cleanPhone !== '' ? $cleanPhone . '@s.whatsapp.net' : '';
                                    
                                    error_log("[WEBHOOK] 🎉 INTERESSE EM CAPTAÇÃO! demand_id=$demandId phone=$cleanPhone pushName='$pushName'");
                                    
                                    // Buscar user_id do profissional pelo telefone
                                    $profUserId = null;
                                    try {
                                        $stmtProf = db()->prepare("
                                            SELECT id FROM users 
                                            WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?
                                            LIMIT 1
                                        ");
                                        $phoneLike = '%' . substr($cleanPhone, -8) . '%';
                                        $stmtProf->execute([$phoneLike]);
                                        $profRow = $stmtProf->fetch(PDO::FETCH_ASSOC);
                                        if ($profRow) {
                                            $profUserId = (int)$profRow['id'];
                                        }
                                    } catch (Exception $e) {}
                                    
                                    // Registrar interesse (INSERT IGNORE para não duplicar)
                                    $stmtInterest = db()->prepare("
                                        INSERT IGNORE INTO demand_interested_professionals 
                                        (demand_id, dispatch_log_id, phone, phone_jid, push_name, user_id, emoji, reacted_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                                    ");
                                    $stmtInterest->execute([
                                        $demandId,
                                        $dispatchRow['id'],
                                        $cleanPhone,
                                        $phoneJid,
                                        $pushName ?: null,
                                        $profUserId,
                                        $reactionEmoji,
                                    ]);
                                    
                                    $wasInserted = $stmtInterest->rowCount() > 0;
                                    
                                    if ($wasInserted) {
                                        // Interesse registrado — NÃO enviar mensagem privada
                                        // Os atendentes vão entrar em contato pelo chat via lista de espera
                                        error_log("[WEBHOOK] Interesse registrado para $cleanPhone na captação #$demandId (sem envio de msg privada)");
                                    } else {
                                        error_log("[WEBHOOK] Profissional $cleanPhone já havia registrado interesse na captação #$demandId");
                                    }
                                }
                            } catch (Exception $capErr) {
                                error_log("[WEBHOOK] Erro ao verificar captação por reação: " . $capErr->getMessage());
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("[WEBHOOK] Erro ao salvar reação: " . $e->getMessage());
                }
            }
            continue; // Reação processada, não salvar como mensagem
        }

        // Extrair dados de mídia
        $mediaData = [];
        $messageType = 'text';
        
        // ===== EXTRAIR CONTEXTO (Quoted/Reply + Mentions) =====
        $contextInfo = null;
        $quotedMessageId = null;
        $quotedMessageText = null;
        $quotedMessageSender = null;
        $mentionedJids = null;
        
        // contextInfo pode estar em extendedTextMessage, imageMessage, videoMessage, audioMessage, documentMessage, stickerMessage
        $contextSources = ['extendedTextMessage', 'imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage'];
        foreach ($contextSources as $src) {
            if (isset($msgPayload[$src]['contextInfo'])) {
                $contextInfo = $msgPayload[$src]['contextInfo'];
                break;
            }
        }
        // Fallback: contextInfo direto no payload
        if ($contextInfo === null && isset($msgPayload['contextInfo'])) {
            $contextInfo = $msgPayload['contextInfo'];
        }
        
        if ($contextInfo !== null) {
            // Mensagem citada (reply/quoted)
            $quotedMessageId = $contextInfo['stanzaId'] ?? null;
            $quotedMessageSender = $contextInfo['participant'] ?? null;
            
            // Extrair texto da mensagem citada
            $quotedMsg = $contextInfo['quotedMessage'] ?? [];
            if (!empty($quotedMsg)) {
                $quotedMessageText = $quotedMsg['conversation'] 
                    ?? $quotedMsg['extendedTextMessage']['text'] 
                    ?? $quotedMsg['imageMessage']['caption']
                    ?? $quotedMsg['videoMessage']['caption']
                    ?? $quotedMsg['documentMessage']['caption']
                    ?? null;
                
                // Se não tem texto, indicar o tipo
                if (empty($quotedMessageText)) {
                    if (isset($quotedMsg['audioMessage'])) $quotedMessageText = '[Áudio]';
                    elseif (isset($quotedMsg['imageMessage'])) $quotedMessageText = '[Imagem]';
                    elseif (isset($quotedMsg['videoMessage'])) $quotedMessageText = '[Vídeo]';
                    elseif (isset($quotedMsg['documentMessage'])) $quotedMessageText = '[Documento]';
                    elseif (isset($quotedMsg['stickerMessage'])) $quotedMessageText = '[Sticker]';
                }
            }
            
            // Menções (@)
            $mentions = $contextInfo['mentionedJid'] ?? [];
            if (!empty($mentions) && is_array($mentions)) {
                $mentionedJids = json_encode($mentions);
            }
            
            if ($quotedMessageId) {
                error_log("[WEBHOOK] REPLY detectado: quotedId='$quotedMessageId' quotedText='" . substr($quotedMessageText ?? '', 0, 30) . "'");
            }
            if ($mentionedJids) {
                error_log("[WEBHOOK] MENÇÕES detectadas: $mentionedJids");
            }
        }
        
        // LOG COMPLETO DO PAYLOAD DE MÍDIA
        error_log("[WEBHOOK] === PROCESSANDO MENSAGEM ===");
        error_log("[WEBHOOK] Message payload keys: " . json_encode(array_keys($msgPayload)));
        
        // Áudio
        if (isset($msgPayload['audioMessage'])) {
            $audio = $msgPayload['audioMessage'];
            $messageType = 'audio';
            
            error_log("[WEBHOOK] ===== ÁUDIO DETECTADO =====");
            
            // Verificar se tem base64 (mesmo padrão das imagens)
            $base64Data = null;
            if (!empty($msgPayload['base64'])) {
                $base64Data = $msgPayload['base64'];
                error_log("[WEBHOOK] ✅ Base64 de áudio encontrado em msgPayload['base64'] (" . strlen($base64Data) . " chars)");
            } elseif (!empty($audio['base64'])) {
                $base64Data = $audio['base64'];
                error_log("[WEBHOOK] ✅ Base64 de áudio encontrado em audio['base64']");
            } else {
                error_log("[WEBHOOK] ⚠️ Base64 de áudio NÃO encontrado, usando URL");
            }
            
            $rawUrl = $audio['url'] ?? null;
            error_log("[WEBHOOK] AUDIO RAW URL: " . ($rawUrl ?? 'NULL'));
            error_log("[WEBHOOK] AUDIO MIME: " . ($audio['mimetype'] ?? 'N/A'));
            
            $mediaData = [
                'type' => 'audio',
                'url' => $rawUrl,
                'base64' => $base64Data,
                'mime_type' => $audio['mimetype'] ?? 'audio/ogg; codecs=opus',
                'filename' => $audio['fileName'] ?? 'audio.ogg',
                'size' => $audio['fileLength'] ?? null,
            ];
            $messageText = $audio['caption'] ?? '[Áudio]';
        }
        // Imagem
        elseif (isset($msgPayload['imageMessage'])) {
            $image = $msgPayload['imageMessage'];
            $messageType = 'image';
            
            error_log("[WEBHOOK] ===== IMAGEM DETECTADA =====");
            error_log("[WEBHOOK] IMAGE FULL PAYLOAD: " . json_encode($image));
            error_log("[WEBHOOK] IMAGE KEYS: " . json_encode(array_keys($image)));
            error_log("[WEBHOOK] MESSAGE DATA KEYS: " . json_encode(array_keys($messageData)));
            
            // Priorizar base64 se disponível (mais confiável que URL)
            // Base64 está em msgPayload['base64'], não em image['base64']
            $base64Data = null;
            if (!empty($msgPayload['base64'])) {
                $base64Data = $msgPayload['base64'];
                error_log("[WEBHOOK] ✅ Base64 encontrado em msgPayload['base64'] (" . strlen($base64Data) . " chars)");
            } elseif (!empty($image['base64'])) {
                $base64Data = $image['base64'];
                error_log("[WEBHOOK] ✅ Base64 encontrado em image['base64']");
            } elseif (!empty($messageData['base64'])) {
                $base64Data = $messageData['base64'];
                error_log("[WEBHOOK] ✅ Base64 encontrado em messageData['base64']");
            } else {
                error_log("[WEBHOOK] ❌ Base64 NÃO encontrado");
            }
            
            $rawUrl = $image['url'] ?? null;
            error_log("[WEBHOOK] IMAGE RAW URL: " . ($rawUrl ?? 'NULL'));
            error_log("[WEBHOOK] IMAGE HAS BASE64: " . (!empty($base64Data) ? 'YES (' . strlen($base64Data) . ' chars)' : 'NO'));
            
            $mediaData = [
                'type' => 'image',
                'url' => $rawUrl,
                'base64' => $base64Data,
                'mime_type' => $image['mimetype'] ?? 'image/jpeg',
                'filename' => $image['fileName'] ?? 'image.jpg',
                'size' => $image['fileLength'] ?? null,
                'thumbnail' => $image['thumbnailUrl'] ?? null,
            ];
            $messageText = $image['caption'] ?? '[Imagem]';
        }
        // Vídeo
        elseif (isset($msgPayload['videoMessage'])) {
            $video = $msgPayload['videoMessage'];
            $messageType = 'video';
            $rawUrl = $video['url'] ?? null;
            error_log("[WEBHOOK] VIDEO RAW URL: " . ($rawUrl ?? 'NULL'));
            $mediaData = [
                'type' => 'video',
                'url' => $rawUrl,
                'mime_type' => $video['mimetype'] ?? 'video/mp4',
                'filename' => $video['fileName'] ?? 'video.mp4',
                'size' => $video['fileLength'] ?? null,
                'thumbnail' => $video['thumbnailUrl'] ?? null,
            ];
            $messageText = $video['caption'] ?? '[Vídeo]';
        }
        // Documento
        elseif (isset($msgPayload['documentMessage'])) {
            $doc = $msgPayload['documentMessage'];
            $messageType = 'document';
            $rawUrl = $doc['url'] ?? null;
            error_log("[WEBHOOK] DOCUMENT RAW URL: " . ($rawUrl ?? 'NULL'));
            $mediaData = [
                'type' => 'document',
                'url' => $rawUrl,
                'mime_type' => $doc['mimetype'] ?? 'application/pdf',
                'filename' => $doc['fileName'] ?? 'document.pdf',
                'size' => $doc['fileLength'] ?? null,
            ];
            $messageText = $doc['caption'] ?? '[Documento]';
        }
        // Sticker
        elseif (isset($msgPayload['stickerMessage'])) {
            $sticker = $msgPayload['stickerMessage'];
            $messageType = 'sticker';
            
            $base64Data = null;
            if (!empty($msgPayload['base64'])) {
                $base64Data = $msgPayload['base64'];
            } elseif (!empty($sticker['base64'])) {
                $base64Data = $sticker['base64'];
            } elseif (!empty($messageData['base64'])) {
                $base64Data = $messageData['base64'];
            }
            
            $rawUrl = $sticker['url'] ?? null;
            error_log("[WEBHOOK] STICKER RAW URL: " . ($rawUrl ?? 'NULL'));
            $mediaData = [
                'type' => 'sticker',
                'url' => $rawUrl,
                'base64' => $base64Data,
                'mime_type' => $sticker['mimetype'] ?? 'image/webp',
                'filename' => 'sticker.webp',
                'size' => $sticker['fileLength'] ?? null,
            ];
            $messageText = '[Sticker]';
        }

        error_log("[WEBHOOK] Tipo de mensagem detectado: '$messageType' | URL extraída: " . ($mediaData['url'] ?? 'N/A'));

        // CORREÇÃO: Se senderPn existe, usar ele em vez de remoteJid
        // Isso acontece quando remoteJid é um canal (@lid) mas senderPn tem o número real
        if (!empty($senderPn) && !$fromMe) {
            error_log("[WEBHOOK] CORREÇÃO: Usando senderPn '$senderPn' em vez de remoteJid '$remoteJid'");
            $remoteJid = $senderPn;
        }

        error_log("[WEBHOOK] msg jid:'$remoteJid' | senderPn:'$senderPn' | participant:'$participant' | fromMe:" . ($fromMe?'1':'0') . " text:'" . substr($messageText,0,50) . "'");
        error_log("[WEBHOOK] DIAGNOSTIC - remoteJid final: '$remoteJid' | length: " . strlen($remoteJid) . " | contains @: " . (strpos($remoteJid, '@') !== false ? 'yes' : 'no'));

        // Ignorar: status@broadcast, JIDs de sistema, tipos de protocolo, textos de sistema
        $isStatusBroadcast = strpos($remoteJid, 'status@broadcast') !== false
                           || strpos($remoteJid, 'broadcast') !== false;
        $isSystemType = isSystemMessageType($msgPayload);
        $isSystemMsg  = isSystemText($messageText);

        // Permitir salvar mídia mesmo sem texto (só verificar se tem remoteJid válido)
        $hasContent = !empty($messageText) || $messageType !== 'text';
        $isGroup = strpos($remoteJid, '@g.us') !== false;

        // Salvar TODAS as mensagens relevantes:
        // - Mensagens recebidas (fromMe=false)
        // - Mensagens enviadas pelo próprio número via celular (fromMe=true)
        // - Mensagens de grupo (todas)
        // Exceções: broadcast, sistema, sem conteúdo
        $shouldSave = !empty($remoteJid) && $hasContent
            && !$isStatusBroadcast && !$isSystemType && !$isSystemMsg;

        if ($shouldSave) {
            try {
                $fromMeInt = $fromMe ? 1 : 0;
                
                // Para mensagens fromMe: verificar se já existe (pode ter sido salva pelo chat_web.php)
                if ($fromMe) {
                    $normalizedCheckJid = normalizeJid($remoteJid);
                    $stmtDupCheck = db()->prepare("
                        SELECT id FROM chat_messages 
                        WHERE remote_jid = ? AND from_me = 1 AND message_text = ? 
                        AND message_timestamp BETWEEN ? AND ?
                        LIMIT 1
                    ");
                    $stmtDupCheck->execute([$normalizedCheckJid, $messageText, $timestamp - 10, $timestamp + 10]);
                    $existingMsg = $stmtDupCheck->fetch();
                    
                    if ($existingMsg) {
                        // Já existe, apenas atualizar external_message_id se necessário
                        if (!empty($externalMsgId)) {
                            $stmtUpd = db()->prepare("UPDATE chat_messages SET external_message_id = ? WHERE id = ? AND external_message_id IS NULL");
                            $stmtUpd->execute([$externalMsgId, $existingMsg['id']]);
                        }
                        error_log("[WEBHOOK] mensagem fromMe já existe (id={$existingMsg['id']}), skip duplicata");
                        continue;
                    }
                }
                
                $extraData = [
                    'quoted_message_id' => $quotedMessageId,
                    'quoted_message_text' => $quotedMessageText,
                    'quoted_message_sender' => $quotedMessageSender,
                    'mentioned_jids' => $mentionedJids,
                    'sender_name' => $pushName ?: null,
                    'participant_jid' => $participant ?: null,
                    'external_message_id' => $externalMsgId ?: null,
                ];
                saveMessage($remoteJid, $messageText, $fromMeInt, $timestamp, $mediaData, $extraData);
                error_log("[WEBHOOK] mensagem salva: jid='$remoteJid' type='$messageType' fromMe=$fromMeInt text='" . substr($messageText,0,50) . "'" . ($quotedMessageId ? " [REPLY to $quotedMessageId]" : ""));
            } catch (Exception $e) {
                error_log('[WEBHOOK] erro ao salvar mensagem: ' . $e->getMessage());
            }
        } else {
            $reason = [];
            if (empty($remoteJid)) $reason[] = 'jid_vazio';
            if (!$hasContent) $reason[] = 'sem_conteudo';
            if ($isStatusBroadcast) $reason[] = 'broadcast';
            if ($isSystemType) $reason[] = 'systemType';
            if ($isSystemMsg) $reason[] = 'systemMsg';
            error_log("[WEBHOOK] mensagem IGNORADA: " . implode(', ', $reason) . " | jid='$remoteJid'");
        }
    }
}

// Tratar mensagens apagadas
if ($event === 'messages.delete') {
    $deleteData = $data['data'] ?? [];
    
    // Formato pode variar: key.id ou messageId ou array de IDs
    $deletedMsgId = $deleteData['key']['id'] ?? $deleteData['id'] ?? $deleteData['messageId'] ?? '';
    $deletedJid = $deleteData['key']['remoteJid'] ?? $deleteData['remoteJid'] ?? '';
    
    // Pode vir como array de mensagens deletadas
    $idsToDelete = [];
    if (!empty($deletedMsgId)) {
        $idsToDelete[] = $deletedMsgId;
    }
    // Formato alternativo: data = [id1, id2, ...]
    if (is_array($deleteData) && isset($deleteData[0]) && is_string($deleteData[0])) {
        $idsToDelete = array_merge($idsToDelete, $deleteData);
    }
    // Formato: data.message.id ou data.messages = [...]
    if (isset($deleteData['message']['id'])) {
        $idsToDelete[] = $deleteData['message']['id'];
    }
    if (isset($deleteData['messages']) && is_array($deleteData['messages'])) {
        foreach ($deleteData['messages'] as $msg) {
            if (is_string($msg)) $idsToDelete[] = $msg;
            elseif (isset($msg['id'])) $idsToDelete[] = $msg['id'];
            elseif (isset($msg['key']['id'])) $idsToDelete[] = $msg['key']['id'];
        }
    }
    
    $idsToDelete = array_unique(array_filter($idsToDelete));
    
    error_log("[WEBHOOK] messages.delete: jid='$deletedJid' ids=" . json_encode($idsToDelete) . " raw_keys=" . json_encode(array_keys($deleteData)));
    
    if (!empty($idsToDelete)) {
        try {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmtDel = db()->prepare("DELETE FROM chat_messages WHERE external_message_id IN ($placeholders)");
            $stmtDel->execute(array_values($idsToDelete));
            $deleted = $stmtDel->rowCount();
            error_log("[WEBHOOK] messages.delete: deletadas=$deleted");
        } catch (Exception $e) {
            error_log("[WEBHOOK] Erro ao deletar mensagem: " . $e->getMessage());
        }
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// Salvar mensagens enviadas via telefone (para mostrar no chat)
if ($event === 'send.message') {
    $messageData = $data['data'] ?? [];
    $remoteJid   = $messageData['key']['remoteJid'] ?? '';
    $msgPayload  = $messageData['message'] ?? [];
    $messageText = $msgPayload['conversation']
                   ?? $msgPayload['extendedTextMessage']['text']
                   ?? '';
    $timestamp   = (int)($messageData['messageTimestamp'] ?? time());

    // Extrair dados de mídia
    $mediaData = [];
    $messageType = 'text';
    
    // Áudio
    if (isset($msgPayload['audioMessage'])) {
        $audio = $msgPayload['audioMessage'];
        $messageType = 'audio';
        $mediaData = [
            'type' => 'audio',
            'url' => $audio['url'] ?? null,
            'mime_type' => $audio['mimetype'] ?? 'audio/ogg',
            'filename' => $audio['fileName'] ?? 'audio.ogg',
            'size' => $audio['fileLength'] ?? null,
        ];
        $messageText = $audio['caption'] ?? '[Áudio]';
    }
    // Imagem
    elseif (isset($msgPayload['imageMessage'])) {
        $image = $msgPayload['imageMessage'];
        $messageType = 'image';
        $mediaData = [
            'type' => 'image',
            'url' => $image['url'] ?? null,
            'mime_type' => $image['mimetype'] ?? 'image/jpeg',
            'filename' => $image['fileName'] ?? 'image.jpg',
            'size' => $image['fileLength'] ?? null,
            'thumbnail' => $image['thumbnailUrl'] ?? null,
        ];
        $messageText = $image['caption'] ?? '[Imagem]';
    }
    // Vídeo
    elseif (isset($msgPayload['videoMessage'])) {
        $video = $msgPayload['videoMessage'];
        $messageType = 'video';
        $mediaData = [
            'type' => 'video',
            'url' => $video['url'] ?? null,
            'mime_type' => $video['mimetype'] ?? 'video/mp4',
            'filename' => $video['fileName'] ?? 'video.mp4',
            'size' => $video['fileLength'] ?? null,
            'thumbnail' => $video['thumbnailUrl'] ?? null,
        ];
        $messageText = $video['caption'] ?? '[Vídeo]';
    }
    // Documento
    elseif (isset($msgPayload['documentMessage'])) {
        $doc = $msgPayload['documentMessage'];
        $messageType = 'document';
        $mediaData = [
            'type' => 'document',
            'url' => $doc['url'] ?? null,
            'mime_type' => $doc['mimetype'] ?? 'application/pdf',
            'filename' => $doc['fileName'] ?? 'document.pdf',
            'size' => $doc['fileLength'] ?? null,
        ];
        $messageText = $doc['caption'] ?? '[Documento]';
    }

    $isStatusBroadcast = strpos($remoteJid, 'status@broadcast') !== false
                       || strpos($remoteJid, 'broadcast') !== false;

    $hasContent = !empty($messageText) || $messageType !== 'text';

    if (!empty($remoteJid) && $hasContent
        && !$isStatusBroadcast && !isSystemMessageType($msgPayload) && !isSystemText($messageText)) {
        try {
            $sendExternalId = $messageData['key']['id'] ?? null;
            $normalizedJid = normalizeJid($remoteJid);
            
            // Verificar se a mensagem já foi salva pelo chat_web.php (evitar duplicata)
            // Mensagens enviadas pelo sistema são salvas no momento do envio,
            // o webhook send.message chega depois e duplicaria
            $stmtCheck = db()->prepare("
                SELECT id FROM chat_messages 
                WHERE remote_jid = ? AND from_me = 1 AND message_text = ? 
                AND message_timestamp BETWEEN ? AND ?
                LIMIT 1
            ");
            $stmtCheck->execute([$normalizedJid, $messageText, $timestamp - 10, $timestamp + 10]);
            $existing = $stmtCheck->fetch();
            
            if ($existing) {
                // Mensagem já existe (salva pelo chat_web.php), apenas atualizar external_message_id se necessário
                if ($sendExternalId) {
                    $stmtUpdate = db()->prepare("UPDATE chat_messages SET external_message_id = ? WHERE id = ? AND external_message_id IS NULL");
                    $stmtUpdate->execute([$sendExternalId, $existing['id']]);
                }
                error_log("[WEBHOOK] mensagem ENVIADA já existe no banco (id={$existing['id']}), skip duplicata");
            } else {
                $sendExtraData = [
                    'external_message_id' => $sendExternalId,
                ];
                saveMessage($remoteJid, $messageText, 1, $timestamp, $mediaData, $sendExtraData);
                error_log("[WEBHOOK] mensagem ENVIADA salva: jid='$remoteJid' type='$messageType'");
            }
        } catch (Exception $e) {
            error_log("Webhook erro ao salvar mensagem enviada: " . $e->getMessage());
        }
    }
}

// Tratar atualização de contatos
if ($event === 'contacts.upsert' || $event === 'contacts.update') {
    $contacts = $data['data'] ?? [];
    if (!is_array($contacts)) $contacts = [$contacts];

    foreach ($contacts as $contact) {
        $jid  = $contact['id'] ?? '';
        $name = $contact['name'] ?? $contact['pushName'] ?? '';
        $pic  = $contact['profilePictureUrl'] ?? null;

        if (empty($jid)) continue;

        try {
            ensureChatTables();
            $isGroup = strpos($jid, '@g.us') !== false ? 1 : 0;
            $stmt = db()->prepare("
                INSERT INTO chat_contacts (remote_jid, contact_name, profile_picture_url, is_group)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    contact_name = COALESCE(NULLIF(VALUES(contact_name), ''), contact_name),
                    profile_picture_url = COALESCE(VALUES(profile_picture_url), profile_picture_url),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$jid, $name, $pic, $isGroup]);
        } catch (Exception $e) {
            error_log("Webhook erro ao atualizar contato: " . $e->getMessage());
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
