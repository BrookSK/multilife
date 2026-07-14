<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * Re-busca o body de um e-mail do servidor IMAP e atualiza no banco.
 * Uso: php cron/smtp_refetch_body.php?id=2
 * Útil quando o e-mail foi capturado com body vazio (parser antigo).
 */

$emailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($emailId <= 0) {
    echo "ERROR: informe ?id=N\n";
    exit;
}

$db = db();

// Buscar o registro
$stmt = $db->prepare("SELECT id, message_id FROM inbound_emails WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $emailId]);
$email = $stmt->fetch();

if (!$email) {
    echo "ERROR: e-mail #$emailId não encontrado\n";
    exit;
}

$targetMessageId = (string)$email['message_id'];
echo "Re-buscando body para e-mail #$emailId (Message-ID: $targetMessageId)\n";

// Configurações IMAP
$host = trim((string)admin_setting_get('smtp.in.host', ''));
if ($host === '') $host = trim((string)admin_setting_get('smtp.out.host', ''));
$port = (int)admin_setting_get('smtp.in.port', '993');
$enc = strtolower(trim((string)admin_setting_get('smtp.in.encryption', '')));
if ($enc === '') $enc = strtolower(trim((string)admin_setting_get('smtp.out.encryption', 'ssl')));
$user = trim((string)admin_setting_get('smtp.in.username', ''));
if ($user === '') $user = trim((string)admin_setting_get('smtp.out.username', ''));
$pass = (string)admin_setting_get('smtp.in.password', '');
if ($pass === '') $pass = (string)admin_setting_get('smtp.out.password', '');
$mailbox = trim((string)admin_setting_get('smtp.in.mailbox', 'INBOX'));

if ($host === '' || $user === '' || $pass === '') {
    echo "ERROR: SMTP/IMAP não configurado\n";
    exit;
}

$flags = [];
if ($enc === 'ssl') $flags[] = 'ssl';
elseif ($enc === 'tls') $flags[] = 'tls';
$flags[] = 'novalidate-cert';
$flagsStr = '/' . implode('/', $flags);

$mboxStr = '{' . $host . ':' . $port . '/imap' . $flagsStr . '}' . $mailbox;

// Tentar também no Archive
$archiveMailbox = trim((string)admin_setting_get('smtp.in.archive_mailbox', 'INBOX.Archive'));

$imap = @imap_open($mboxStr, $user, $pass, 0, 1);
if (!$imap) {
    echo "ERROR: imap_open failed: " . imap_last_error() . "\n";
    exit;
}

// Buscar por Message-ID (pode estar em INBOX ou Archive)
$searchStr = 'HEADER "Message-ID" "' . str_replace('"', '', $targetMessageId) . '"';
$ids = imap_search($imap, $searchStr, SE_UID);

if (!is_array($ids) || count($ids) === 0) {
    // Tentar no archive
    imap_close($imap);
    $mboxStrArchive = '{' . $host . ':' . $port . '/imap' . $flagsStr . '}' . $archiveMailbox;
    $imap = @imap_open($mboxStrArchive, $user, $pass, 0, 1);
    if ($imap) {
        $ids = imap_search($imap, $searchStr, SE_UID);
    }
}

// Fallback: buscar ALL e comparar message_id
if (!is_array($ids) || count($ids) === 0) {
    echo "Buscando por ALL no mailbox...\n";
    imap_close($imap);
    $imap = @imap_open($mboxStr, $user, $pass, 0, 1);
    if (!$imap) {
        echo "ERROR: imap_open failed\n";
        exit;
    }
    $allIds = imap_search($imap, 'ALL', SE_UID);
    if (is_array($allIds)) {
        // Buscar os últimos 50
        rsort($allIds);
        $allIds = array_slice($allIds, 0, 50);
        foreach ($allIds as $checkUid) {
            $ov = imap_fetch_overview($imap, (string)$checkUid, FT_UID);
            if (is_array($ov) && isset($ov[0]) && isset($ov[0]->message_id)) {
                if (trim($ov[0]->message_id) === trim($targetMessageId)) {
                    $ids = [$checkUid];
                    echo "Encontrado! UID: $checkUid\n";
                    break;
                }
            }
        }
    }
}

if (!is_array($ids) || count($ids) === 0) {
    echo "ERROR: e-mail não encontrado no servidor IMAP (pode ter sido deletado)\n";
    imap_close($imap);
    exit;
}

$uid = $ids[0];
echo "UID encontrado: $uid\n";

// Extrair body com o parser recursivo
$uidInt = (int)$uid;
$structure = imap_fetchstructure($imap, $uidInt, FT_UID);

$decodeBody = function($body, $encoding) {
    switch ($encoding) {
        case 3: return base64_decode($body);
        case 4: return quoted_printable_decode($body);
        default: return $body;
    }
};

$bodyText = '';
$bodyHtml = '';

if ($structure) {
    $structType = isset($structure->type) ? (int)$structure->type : -1;
    $structSubtype = isset($structure->subtype) ? strtoupper((string)$structure->subtype) : '';
    echo "Estrutura: type=$structType subtype=$structSubtype partes=" . (isset($structure->parts) ? count($structure->parts) : 0) . "\n";
    
    $extractParts = function($parts, $prefix, $imap, $uidInt, $decodeBody) use (&$extractParts, &$bodyText, &$bodyHtml) {
        foreach ($parts as $partNum => $part) {
            $partIndex = $prefix . (string)($partNum + 1);
            $type = isset($part->type) ? (int)$part->type : 0;
            $subtype = isset($part->subtype) ? strtolower((string)$part->subtype) : '';
            
            echo "  Parte $partIndex: type=$type subtype=$subtype";
            if (isset($part->parts)) echo " (sub-partes: " . count($part->parts) . ")";
            echo "\n";
            
            // Se é multipart, descer recursivamente
            if ($type === 1 && isset($part->parts) && is_array($part->parts)) {
                $extractParts($part->parts, $partIndex . '.', $imap, $uidInt, $decodeBody);
                continue;
            }
            
            if ($type !== 0) continue; // Pular não-texto
            
            $data = imap_fetchbody($imap, $uidInt, $partIndex, FT_UID);
            if ($data === false || $data === '') {
                echo "    -> vazio!\n";
                continue;
            }
            
            $encoding = isset($part->encoding) ? (int)$part->encoding : 0;
            $decoded = $decodeBody($data, $encoding);
            
            // Charset
            $charset = '';
            if (isset($part->parameters) && is_array($part->parameters)) {
                foreach ($part->parameters as $param) {
                    if (isset($param->attribute) && strtolower($param->attribute) === 'charset') {
                        $charset = strtolower((string)($param->value ?? ''));
                    }
                }
            }
            if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii') {
                $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
                if ($converted !== false) $decoded = $converted;
            }
            
            echo "    -> " . strlen($decoded) . " chars (charset: $charset)\n";
            
            if ($subtype === 'plain' && $bodyText === '') {
                $bodyText = $decoded;
            } elseif ($subtype === 'html' && $bodyHtml === '') {
                $bodyHtml = $decoded;
            }
        }
    };
    
    if (isset($structure->parts) && is_array($structure->parts)) {
        $extractParts($structure->parts, '', $imap, $uidInt, $decodeBody);
    } else {
        // Mensagem simples
        $data = imap_body($imap, $uidInt, FT_UID);
        if (is_string($data) && $data !== '') {
            $encoding = isset($structure->encoding) ? (int)$structure->encoding : 0;
            $bodyText = $decodeBody($data, $encoding);
        }
    }
}

imap_close($imap);

// Converter para UTF-8
if ($bodyText !== '') $bodyText = mb_convert_encoding($bodyText, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
if ($bodyHtml !== '') $bodyHtml = mb_convert_encoding($bodyHtml, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');

echo "\nResultado:\n";
echo "  body_text: " . strlen($bodyText) . " chars\n";
echo "  body_html: " . strlen($bodyHtml) . " chars\n";

if ($bodyText === '' && $bodyHtml === '') {
    echo "\nERROR: Não foi possível extrair body do e-mail no IMAP.\n";
    exit;
}

// Atualizar no banco
$upd = $db->prepare("UPDATE inbound_emails SET body_text = :bt, body_html = :bh, status = 'received', error_message = NULL WHERE id = :id");
$upd->execute([
    'bt' => $bodyText !== '' ? $bodyText : null,
    'bh' => $bodyHtml !== '' ? $bodyHtml : null,
    'id' => $emailId,
]);

echo "\n✅ Body atualizado no banco para e-mail #$emailId! Status resetado para 'received'.\n";
echo "Agora rode: php cron/openai_extract_email_to_demand.php?id=$emailId&force=1&debug=1\n";
