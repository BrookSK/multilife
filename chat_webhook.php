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

function saveMessage(string $remoteJid, string $text, int $fromMe, int $timestamp): void {
    ensureChatTables();
    
    // NORMALIZAR JID para evitar duplicação
    $normalizedJid = normalizeJid($remoteJid);
    
    error_log("[SAVE_MSG] Original JID: '$remoteJid' | Normalized: '$normalizedJid' | fromMe: $fromMe | text: '" . substr($text, 0, 30) . "'");
    
    $stmt = db()->prepare("INSERT INTO chat_messages (remote_jid, message_text, from_me, message_timestamp) VALUES (?, ?, ?, ?)");
    $stmt->execute([$normalizedJid, $text, $fromMe, $timestamp]);

    // Atualizar contato
    $isGroup = strpos($normalizedJid, '@g.us') !== false ? 1 : 0;
    $contactName = str_replace(['@s.whatsapp.net', '@g.us', '@lid'], '', $normalizedJid);

    error_log("[SAVE_CONTACT] Salvando/atualizando contato - normalizedJid: '$normalizedJid' | contactName: '$contactName' | isGroup: $isGroup");

    $stmtContact = db()->prepare("
        INSERT INTO chat_contacts (remote_jid, contact_name, is_group, last_message_timestamp)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_message_timestamp = VALUES(last_message_timestamp),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmtContact->execute([$normalizedJid, $contactName, $isGroup, $timestamp]);
    
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

        if (!$fromMe && !empty($remoteJid) && !empty($messageText)
            && !$isStatusBroadcast && !$isSystemType && !$isSystemMsg) {
            try {
                saveMessage($remoteJid, $messageText, 0, $timestamp);
                error_log("[WEBHOOK] mensagem salva: jid='$remoteJid' text='" . substr($messageText,0,50) . "'");
            } catch (Exception $e) {
                error_log('[WEBHOOK] erro ao salvar mensagem: ' . $e->getMessage());
            }
        } else {
            $reason = [];
            if ($fromMe) $reason[] = 'fromMe';
            if (empty($remoteJid)) $reason[] = 'jid_vazio';
            if (empty($messageText)) $reason[] = 'text_vazio';
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

    $isStatusBroadcast = strpos($remoteJid, 'status@broadcast') !== false
                       || strpos($remoteJid, 'broadcast') !== false;

    if (!empty($remoteJid) && !empty($messageText)
        && !$isStatusBroadcast && !isSystemMessageType($msgPayload) && !isSystemText($messageText)) {
        try {
            saveMessage($remoteJid, $messageText, 1, $timestamp);
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
