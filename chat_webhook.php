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
    'messages.delete',
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

function saveMessage(string $remoteJid, string $text, int $fromMe, int $timestamp, array $mediaData = []): void {
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
            // URL externa completa, fazer download
            error_log("[SAVE_MSG] Mídia externa detectada, fazendo download...");
            $localUrl = downloadMedia($mediaUrl, $mediaFilename ?? 'media', $mediaMimeType ?? 'application/octet-stream');
            if ($localUrl !== null) {
                $mediaUrl = $localUrl;
                error_log("[SAVE_MSG] Mídia salva localmente: $localUrl");
            } else {
                // Se falhou ao baixar, salvar mensagem mas sem URL (não exibir mídia quebrada)
                error_log("[SAVE_MSG] AVISO: Falha ao baixar mídia, salvando mensagem sem URL");
                error_log("[SAVE_MSG] URL que falhou: $mediaUrl");
                $mediaUrl = null; // Limpar URL para não exibir mídia quebrada
                $messageText = $messageText ?: '[Mídia não disponível]';
            }
        }
    }
    
    error_log("[SAVE_MSG] Original JID: '$remoteJid' | Normalized: '$normalizedJid' | fromMe: $fromMe | type: '$messageType' | text: '" . substr($text, 0, 30) . "'");
    error_log("[SAVE_MSG] MEDIA DATA - URL: " . ($mediaUrl ?? 'NULL') . " | MIME: " . ($mediaMimeType ?? 'NULL') . " | Filename: " . ($mediaFilename ?? 'NULL') . " | Size: " . ($mediaSize ?? 'NULL'));
    
    $stmt = db()->prepare("
        INSERT INTO chat_messages 
        (remote_jid, message_text, message_type, media_url, media_mime_type, media_filename, media_size, audio_transcription, thumbnail_url, from_me, message_timestamp) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $normalizedJid, 
        $text, 
        $messageType,
        $mediaUrl,
        $mediaMimeType,
        $mediaFilename,
        $mediaSize,
        $audioTranscription,
        $thumbnailUrl,
        $fromMe, 
        $timestamp
    ]);
    
    error_log("[SAVE_MSG] Mensagem salva no banco - ID: " . db()->lastInsertId());

    // Atualizar contato com preview da última mensagem
    $isGroup = strpos($normalizedJid, '@g.us') !== false ? 1 : 0;
    $contactName = str_replace(['@s.whatsapp.net', '@g.us', '@lid'], '', $normalizedJid);
    
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

    error_log("[SAVE_CONTACT] Salvando/atualizando contato - normalizedJid: '$normalizedJid' | contactName: '$contactName' | isGroup: $isGroup");

    $stmtContact = db()->prepare("
        INSERT INTO chat_contacts (remote_jid, contact_name, is_group, last_message_timestamp, last_message_text, last_message_type)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_message_timestamp = VALUES(last_message_timestamp),
            last_message_text = VALUES(last_message_text),
            last_message_type = VALUES(last_message_type),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmtContact->execute([$normalizedJid, $contactName, $isGroup, $timestamp, $lastMessageText, $messageType]);
    
    error_log("[SAVE_CONTACT] Contato salvo com sucesso - normalizedJid: '$normalizedJid'");
}

// Tipos de mensagem que são sistema/protocolo e devem ser ignorados
function isSystemMessageType(array $message): bool {
    $systemTypes = [
        'protocolMessage',
        'ephemeralMessage',
        'senderKeyDistributionMessage',
        'reactionMessage',
        'pollCreationMessage',
        'pollUpdateMessage',
        'callLogMessage',
        'requestPhoneNumberMessage',
        'encReactionMessage',
    ];
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
        
        $remoteJid   = $messageData['key']['remoteJid'] ?? '';
        $senderPn    = $messageData['key']['senderPn'] ?? ''; // Número real do remetente
        $fromMe      = (bool)($messageData['key']['fromMe'] ?? false);
        $participant = $messageData['key']['participant'] ?? '';
        $msgPayload  = $messageData['message'] ?? [];
        $messageText = $msgPayload['conversation']
                       ?? $msgPayload['extendedTextMessage']['text']
                       ?? '';
        $timestamp   = (int)($messageData['messageTimestamp'] ?? time());

        // Extrair dados de mídia
        $mediaData = [];
        $messageType = 'text';
        
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

        // Salvar mensagem se:
        // - Não é fromMe (mensagem recebida de outro contato)
        // - OU é de grupo (mensagens em grupo são sempre relevantes, inclusive as próprias)
        $shouldSave = (!$fromMe || $isGroup) && !empty($remoteJid) && $hasContent
            && !$isStatusBroadcast && !$isSystemType && !$isSystemMsg;

        if ($shouldSave) {
            try {
                $fromMeInt = $fromMe ? 1 : 0;
                saveMessage($remoteJid, $messageText, $fromMeInt, $timestamp, $mediaData);
                error_log("[WEBHOOK] mensagem salva: jid='$remoteJid' type='$messageType' fromMe=$fromMeInt text='" . substr($messageText,0,50) . "'");
            } catch (Exception $e) {
                error_log('[WEBHOOK] erro ao salvar mensagem: ' . $e->getMessage());
            }
        } else {
            $reason = [];
            if ($fromMe) $reason[] = 'fromMe';
            if (empty($remoteJid)) $reason[] = 'jid_vazio';
            if (!$hasContent) $reason[] = 'sem_conteudo';
            if ($isStatusBroadcast) $reason[] = 'broadcast';
            if ($isSystemType) $reason[] = 'systemType';
            if ($isSystemMsg) $reason[] = 'systemMsg';
            error_log("[WEBHOOK] mensagem IGNORADA: " . implode(', ', $reason) . " | jid='$remoteJid'");
        }
    }
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
            saveMessage($remoteJid, $messageText, 1, $timestamp, $mediaData);
            error_log("[WEBHOOK] mensagem ENVIADA salva: jid='$remoteJid' type='$messageType'");
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
