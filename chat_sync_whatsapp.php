<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

// Detectar chamada AJAX (fetch) para responder JSON em vez de redirecionar.
$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

/**
 * Finaliza a requisição: JSON quando AJAX, senão flash + redirect.
 */
function syncFinish(bool $success, string $message, string $requestedInstance, array $extra = []): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
        exit;
    }
    flash_set($success ? 'success' : 'error', $message);
    $redirect = '/chat_web.php?refresh=1&t=' . time();
    if ($requestedInstance !== '') {
        $redirect .= '&instance=' . rawurlencode($requestedInstance);
    }
    header('Location: ' . $redirect);
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$_currentUserId = (int)($_SESSION['auth_user_id'] ?? 0);

// Instância a sincronizar: respeitar o filtro por WhatsApp, se veio na URL.
$requestedInstance = isset($_GET['instance']) ? trim((string)$_GET['instance']) : '';
if ($requestedInstance !== '') {
    $instanceName = $requestedInstance;
} else {
    $_userInst = whatsapp_get_user_instance($_currentUserId);
    $instanceName = $_userInst ? $_userInst['instance_name'] : admin_setting_get('evolution.instance');
}

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    syncFinish(false, 'Evolution API não configurada.', $requestedInstance);
}

/**
 * Normaliza o JID no mesmo padrão do webhook/chat_web (número + sufixo padrão).
 */
function syncNormalizeJid(string $jid): string
{
    $numberOnly = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us|broadcast)$/', '', $jid);
    if (strpos($jid, '@g.us') !== false) {
        return $numberOnly . '@g.us';
    }
    return $numberOnly . '@s.whatsapp.net';
}

/**
 * Extrai o texto de exibição de um objeto lastMessage da Evolution API,
 * tentando os formatos mais comuns.
 */
function syncExtractLastMessageText($lastMessage): string
{
    if (!is_array($lastMessage)) {
        return '';
    }
    $msg = $lastMessage['message'] ?? [];
    if (is_array($msg)) {
        if (!empty($msg['conversation'])) {
            return (string)$msg['conversation'];
        }
        if (!empty($msg['extendedTextMessage']['text'])) {
            return (string)$msg['extendedTextMessage']['text'];
        }
        if (isset($msg['imageMessage'])) {
            return '📷 Imagem';
        }
        if (isset($msg['videoMessage'])) {
            return '🎬 Vídeo';
        }
        if (isset($msg['audioMessage'])) {
            return '🎤 Áudio';
        }
        if (isset($msg['documentMessage'])) {
            return '📄 Documento';
        }
    }
    return '';
}

try {
    // Sincronizar conversas via findChats
    $ch = curl_init($baseUrl . '/chat/findChats/' . urlencode($instanceName));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        $msg = ($httpCode === 404)
            ? 'Instância offline ou desconectada (404). Reconecte o WhatsApp.'
            : ('Erro ao sincronizar. Código HTTP: ' . $httpCode . ($curlError ? ' - ' . $curlError : ''));
        syncFinish(false, $msg, $requestedInstance);
    }

    $chats = json_decode((string)$response, true);

    // Alguns formatos encapsulam a lista em data/chats
    if (is_array($chats) && !isset($chats[0])) {
        if (isset($chats['data']) && is_array($chats['data'])) {
            $chats = $chats['data'];
        } elseif (isset($chats['chats']) && is_array($chats['chats'])) {
            $chats = $chats['chats'];
        }
    }

    if (!is_array($chats)) {
        syncFinish(false, 'Resposta inválida da Evolution API ao sincronizar conversas.', $requestedInstance);
    }

    // Garantir que a tabela e as colunas necessárias existem
    db()->exec("CREATE TABLE IF NOT EXISTS chat_contacts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        remote_jid VARCHAR(100) NOT NULL UNIQUE,
        instance_name VARCHAR(100) DEFAULT NULL,
        contact_name VARCHAR(255) DEFAULT NULL,
        profile_picture_url TEXT DEFAULT NULL,
        is_group TINYINT(1) NOT NULL DEFAULT 0,
        last_message_timestamp INT UNSIGNED DEFAULT NULL,
        last_message_text TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE INDEX idx_remote_jid (remote_jid),
        INDEX idx_last_message (last_message_timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Preparar statement de upsert (não sobrescreve nome manual/original com o número)
    $stmt = db()->prepare("
        INSERT INTO chat_contacts
            (remote_jid, instance_name, contact_name, is_group, last_message_timestamp, last_message_text)
        VALUES
            (:jid, :instance, :name, :is_group, :ts, :last_text)
        ON DUPLICATE KEY UPDATE
            contact_name = CASE
                WHEN VALUES(contact_name) != '' AND VALUES(contact_name) != REPLACE(REPLACE(REPLACE(remote_jid, '@s.whatsapp.net', ''), '@g.us', ''), '@lid', '')
                THEN VALUES(contact_name)
                ELSE COALESCE(NULLIF(contact_name, ''), VALUES(contact_name))
            END,
            instance_name = COALESCE(instance_name, VALUES(instance_name)),
            last_message_timestamp = GREATEST(COALESCE(last_message_timestamp, 0), COALESCE(VALUES(last_message_timestamp), 0)),
            last_message_text = COALESCE(VALUES(last_message_text), last_message_text),
            updated_at = CURRENT_TIMESTAMP
    ");

    $totalChats = 0;
    $groups = 0;
    $private = 0;
    $saved = 0;

    foreach ($chats as $chat) {
        if (!is_array($chat)) {
            continue;
        }

        // O JID pode vir em diferentes chaves conforme a versão
        $rawJid = $chat['remoteJid']
            ?? ($chat['id']
            ?? ($chat['jid']
            ?? ($chat['lastMessage']['key']['remoteJid'] ?? '')));
        $rawJid = (string)$rawJid;
        if ($rawJid === '' || strpos($rawJid, '@') === false) {
            continue;
        }

        // Ignorar broadcasts/status
        if (strpos($rawJid, '@broadcast') !== false || strpos($rawJid, 'status@') !== false) {
            continue;
        }

        $totalChats++;
        $isGroup = strpos($rawJid, '@g.us') !== false ? 1 : 0;
        if ($isGroup) {
            $groups++;
        } else {
            $private++;
        }

        $normalizedJid = syncNormalizeJid($rawJid);

        // Nome do contato: pushName / name / subject. Nunca usar apenas o número.
        $name = (string)($chat['pushName']
            ?? ($chat['name']
            ?? ($chat['subject']
            ?? ($chat['verifiedName'] ?? ''))));
        $numberOnly = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us|broadcast)$/', '', $normalizedJid);
        if ($name === $numberOnly) {
            $name = '';
        }

        // Última mensagem e timestamp (se disponíveis)
        $lastText = syncExtractLastMessageText($chat['lastMessage'] ?? null);
        $ts = (int)($chat['lastMessage']['messageTimestamp']
            ?? ($chat['updatedAt'] ?? ($chat['messageTimestamp'] ?? 0)));
        // Alguns retornos trazem timestamp em milissegundos
        if ($ts > 20000000000) {
            $ts = (int)($ts / 1000);
        }

        try {
            $stmt->execute([
                'jid' => $normalizedJid,
                'instance' => $instanceName,
                'name' => $name,
                'is_group' => $isGroup,
                'ts' => $ts > 0 ? $ts : null,
                'last_text' => $lastText !== '' ? $lastText : null,
            ]);
            $saved++;
        } catch (Throwable $e) {
            error_log('[CHAT_SYNC] Erro ao salvar contato ' . $normalizedJid . ': ' . $e->getMessage());
        }
    }

    audit_log('sync', 'whatsapp', '0', null, [
        'instance' => $instanceName,
        'total_chats' => $totalChats,
        'saved' => $saved,
        'groups' => $groups,
        'private' => $private,
    ]);

    page_history_log(
        '/chat_web.php',
        'Chat ao Vivo',
        'sync',
        'Sincronizou conversas do WhatsApp: ' . $saved . ' salvas de ' . $totalChats,
        'whatsapp',
        0
    );

    syncFinish(
        true,
        "Sincronização concluída! $saved conversa(s) atualizada(s) de $totalChats encontradas ($groups grupos, $private privadas).",
        $requestedInstance,
        ['saved' => $saved, 'total' => $totalChats, 'groups' => $groups, 'private' => $private]
    );
} catch (Exception $e) {
    error_log('[CHAT_SYNC] Erro: ' . $e->getMessage());
    syncFinish(false, 'Erro ao sincronizar: ' . $e->getMessage(), $requestedInstance);
}
