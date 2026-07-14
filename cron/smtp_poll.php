<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// MD 3.1 - Poll IMAP inbox, persist inbound emails, mark as seen and archive.
// Requires PHP IMAP extension.

if (!function_exists('imap_open')) {
    http_response_code(500);
    echo "ERROR: PHP IMAP extension not enabled (imap_open missing).\n";
    exit;
}

@set_time_limit(25);
if (function_exists('imap_timeout')) {
    @imap_timeout(IMAP_OPENTIMEOUT, 10);
    @imap_timeout(IMAP_READTIMEOUT, 10);
    @imap_timeout(IMAP_WRITETIMEOUT, 10);
    @imap_timeout(IMAP_CLOSETIMEOUT, 5);
}

// Usar configurações SMTP de saída como fallback para IMAP quando não configuradas
$host = trim((string)admin_setting_get('smtp.in.host', ''));
if ($host === '') {
    $host = trim((string)admin_setting_get('smtp.out.host', ''));
}

$port = (int)admin_setting_get('smtp.in.port', '993');

$enc = strtolower(trim((string)admin_setting_get('smtp.in.encryption', '')));
if ($enc === '') {
    $enc = strtolower(trim((string)admin_setting_get('smtp.out.encryption', 'ssl')));
}

$user = trim((string)admin_setting_get('smtp.in.username', ''));
if ($user === '') {
    $user = trim((string)admin_setting_get('smtp.out.username', ''));
}

$pass = (string)admin_setting_get('smtp.in.password', '');
if ($pass === '') {
    $pass = (string)admin_setting_get('smtp.out.password', '');
}

$mailbox = trim((string)admin_setting_get('smtp.in.mailbox', 'INBOX'));
$archiveMailbox = trim((string)admin_setting_get('smtp.in.archive_mailbox', 'INBOX.Archive'));
$demandsTo = trim((string)admin_setting_get('smtp.demands.to_address', ''));

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($limit <= 0 || $limit > 200) {
    $limit = 20;
}

if ($host === '' || $user === '' || $pass === '') {
    http_response_code(500);
    echo "ERROR: SMTP/IMAP inbound not configured (host/username/password).\n";
    exit;
}

$colsStmt = db()->prepare('SHOW COLUMNS FROM inbound_emails');
$colsStmt->execute();
$cols = [];
foreach ($colsStmt->fetchAll() as $c) {
    if (isset($c['Field'])) {
        $cols[(string)$c['Field']] = true;
    }
}

$hasMailboxKey = isset($cols['mailbox_key']);
$hasFromEmail = isset($cols['from_email']);
$hasFromName = isset($cols['from_name']);
$hasFromAddress = isset($cols['from_address']);
$hasToAddress = isset($cols['to_address']);
$hasInReplyTo = isset($cols['in_reply_to']);

$insertColumns = [];
$insertValues = [];

if ($hasMailboxKey) {
    $insertColumns[] = 'mailbox_key';
    $insertValues[] = ':mailbox_key';
}

$insertColumns[] = 'message_id';
$insertValues[] = ':message_id';

if ($hasInReplyTo) {
    $insertColumns[] = 'in_reply_to';
    $insertValues[] = ':in_reply_to';
}

if ($hasFromEmail) {
    $insertColumns[] = 'from_email';
    $insertValues[] = ':from_email';
} elseif ($hasFromAddress) {
    $insertColumns[] = 'from_address';
    $insertValues[] = ':from_address';
}

if ($hasFromName) {
    $insertColumns[] = 'from_name';
    $insertValues[] = ':from_name';
}

if ($hasToAddress) {
    $insertColumns[] = 'to_address';
    $insertValues[] = ':to_address';
}

$insertColumns[] = 'subject';
$insertValues[] = ':subject';
$insertColumns[] = 'body_text';
$insertValues[] = ':body_text';
$insertColumns[] = 'body_html';
$insertValues[] = ':body_html';
$insertColumns[] = 'received_at';
$insertValues[] = ':received_at';
$insertColumns[] = 'status';
$insertValues[] = ':status';

$flags = [];
if ($enc === 'ssl') {
    $flags[] = 'ssl';
} elseif ($enc === 'tls') {
    $flags[] = 'tls';
} elseif ($enc === 'none' || $enc === '') {
    // no encryption flags
} else {
    // unknown; keep raw
    $flags[] = $enc;
}

// Adicionar novalidate-cert para ignorar validação de hostname do certificado
$flags[] = 'novalidate-cert';

$flagsStr = '';
if (count($flags) > 0) {
    $flagsStr = '/' . implode('/', $flags);
}

$mboxStr = '{' . $host . ':' . $port . '/imap' . $flagsStr . '}' . $mailbox;

$imap = @imap_open($mboxStr, $user, $pass, 0, 1);
if (!$imap) {
    http_response_code(500);
    $err = imap_last_error();
    echo "ERROR: imap_open failed: " . (string)$err . "\n";
    exit;
}

try {
    // Unseen emails
    $ids = imap_search($imap, 'UNSEEN', SE_UID);
    if (!is_array($ids) || count($ids) === 0) {
        echo "OK: no unseen emails\n";
        exit;
    }

    rsort($ids);
    $ids = array_slice($ids, 0, $limit);

    $db = db();

    $ins = $db->prepare(
        'INSERT INTO inbound_emails (' . implode(',', $insertColumns) . ")\n"
        . 'VALUES (' . implode(',', $insertValues) . ')'
    );

    $seen = 0;
    $inserted = 0;
    $skipped = 0;
    $archived = 0;

    foreach ($ids as $uid) {
        $overviewArr = imap_fetch_overview($imap, (string)$uid, FT_UID);
        $ov = (is_array($overviewArr) && isset($overviewArr[0])) ? $overviewArr[0] : null;

        $messageId = '';
        $subject = '';
        $fromRaw = '';
        $dateRaw = '';
        $inReplyTo = '';

        if ($ov) {
            $messageId = isset($ov->message_id) ? (string)$ov->message_id : '';
            $subject = isset($ov->subject) ? (string)$ov->subject : '';
            $fromRaw = isset($ov->from) ? (string)$ov->from : '';
            $dateRaw = isset($ov->date) ? (string)$ov->date : '';
            $inReplyTo = isset($ov->in_reply_to) ? (string)$ov->in_reply_to : '';
        }
        
        // Se In-Reply-To não veio no overview, buscar nos headers completos
        if ($inReplyTo === '') {
            $uidInt = (int)$uid;
            $headers = imap_fetchheader($imap, $uidInt, FT_UID);
            if (is_string($headers) && $headers !== '') {
                // Buscar header In-Reply-To
                if (preg_match('/^In-Reply-To:\s*(.+)$/mi', $headers, $matches)) {
                    $inReplyTo = trim($matches[1]);
                }
            }
        }
        
        error_log("[SMTP_POLL] E-mail UID $uid - Message-ID: $messageId, In-Reply-To: $inReplyTo");

        $fromEmail = '';
        $fromName = '';
        if ($fromRaw !== '') {
            $addrs = imap_rfc822_parse_adrlist($fromRaw, '');
            if (is_array($addrs) && isset($addrs[0])) {
                $a = $addrs[0];
                $mailboxA = isset($a->mailbox) ? (string)$a->mailbox : '';
                $hostA = isset($a->host) ? (string)$a->host : '';
                if ($mailboxA !== '' && $hostA !== '') {
                    $fromEmail = $mailboxA . '@' . $hostA;
                }
                $fromName = isset($a->personal) ? (string)imap_utf8((string)$a->personal) : '';
            }
        }

        if ($messageId === '') {
            $messageId = 'uid-' . (string)$uid . '-' . (string)time();
        }

        // Decodificar subject corretamente (MIME encoded headers)
        $subjectUtf8 = '';
        if ($subject !== '') {
            $decoded = imap_mime_header_decode($subject);
            if (is_array($decoded)) {
                foreach ($decoded as $part) {
                    $charset = isset($part->charset) ? (string)$part->charset : 'default';
                    $text = isset($part->text) ? (string)$part->text : '';
                    if ($charset !== 'default' && $charset !== 'us-ascii') {
                        $subjectUtf8 .= mb_convert_encoding($text, 'UTF-8', $charset);
                    } else {
                        $subjectUtf8 .= $text;
                    }
                }
            } else {
                $subjectUtf8 = (string)imap_utf8($subject);
            }
        }

        $receivedAt = null;
        if ($dateRaw !== '') {
            $ts = strtotime($dateRaw);
            if ($ts !== false) {
                $receivedAt = date('Y-m-d H:i:s', $ts);
            }
        }
        if ($receivedAt === null) {
            $receivedAt = date('Y-m-d H:i:s');
        }

        // Prevent duplicates when message_id exists
        if ($messageId !== '') {
            $chk = $db->prepare('SELECT id FROM inbound_emails WHERE message_id = :mid LIMIT 1');
            $chk->execute(['mid' => $messageId]);
            if ($chk->fetch()) {
                // still mark as seen + archive
                imap_setflag_full($imap, (string)$uid, "\\Seen", ST_UID);
                $seen++;
                $skipped++;

                if ($archiveMailbox !== '') {
                    @imap_mail_move($imap, (string)$uid, $archiveMailbox, CP_UID);
                }

                continue;
            }
        }

        // Body - Extrair e decodificar corretamente
        $bodyText = '';
        $bodyHtml = '';

        $uidInt = (int)$uid;
        $structure = imap_fetchstructure($imap, $uidInt, FT_UID);
        
        // Função helper para decodificar corpo baseado no encoding
        $decodeBody = function($body, $encoding) {
            switch ($encoding) {
                case 3: // BASE64
                    return base64_decode($body);
                case 4: // QUOTED-PRINTABLE
                    return quoted_printable_decode($body);
                case 1: // 8BIT
                case 2: // BINARY
                case 5: // 7BIT
                default:
                    return $body;
            }
        };
        
        if ($structure) {
            // Processar estrutura MIME (com recursão para multipart aninhado)
            
            // Função recursiva para extrair texto de partes
            $extractParts = function($parts, $prefix, $imap, $uidInt, $decodeBody) use (&$extractParts, &$bodyText, &$bodyHtml) {
                foreach ($parts as $partNum => $part) {
                    $partIndex = $prefix . (string)($partNum + 1);
                    $type = isset($part->type) ? (int)$part->type : 0;
                    $subtype = isset($part->subtype) ? strtolower((string)$part->subtype) : '';
                    
                    // Se é multipart (type=1), descer recursivamente nas sub-partes
                    if ($type === 1 && isset($part->parts) && is_array($part->parts)) {
                        $extractParts($part->parts, $partIndex . '.', $imap, $uidInt, $decodeBody);
                        continue;
                    }
                    
                    // Só processar text/plain e text/html
                    if ($type !== 0) {
                        continue; // Pular imagens, attachments, etc.
                    }
                    
                    $data = imap_fetchbody($imap, $uidInt, $partIndex, FT_UID);
                    
                    if ($data === false || $data === '') {
                        continue;
                    }
                    
                    // Decodificar baseado no encoding
                    $encoding = isset($part->encoding) ? (int)$part->encoding : 0;
                    $decoded = $decodeBody($data, $encoding);
                    
                    // Converter charset se necessário
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
                        if ($converted !== false) {
                            $decoded = $converted;
                        }
                    }
                    
                    if ($subtype === 'plain' && $bodyText === '') {
                        $bodyText = $decoded;
                    } elseif ($subtype === 'html' && $bodyHtml === '') {
                        $bodyHtml = $decoded;
                    }
                }
            };
            
            // Se for multipart, processar partes (com recursão)
            if (isset($structure->parts) && is_array($structure->parts)) {
                $extractParts($structure->parts, '', $imap, $uidInt, $decodeBody);
            } else {
                // Mensagem simples (não multipart)
                $data = imap_body($imap, $uidInt, FT_UID);
                if (is_string($data) && $data !== '') {
                    $encoding = isset($structure->encoding) ? (int)$structure->encoding : 0;
                    $bodyText = $decodeBody($data, $encoding);
                }
            }
        } else {
            // Fallback: tentar pegar corpo direto
            $raw = imap_body($imap, $uidInt, FT_UID);
            if (is_string($raw)) {
                $bodyText = $raw;
            }
        }
        
        // Converter para UTF-8 se necessário
        if ($bodyText !== '') {
            $bodyText = mb_convert_encoding($bodyText, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        }
        if ($bodyHtml !== '') {
            $bodyHtml = mb_convert_encoding($bodyHtml, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        }

        $db->beginTransaction();
        try {
            $insParams = [
                'message_id' => $messageId,
                'subject' => $subjectUtf8 !== '' ? $subjectUtf8 : null,
                'body_text' => $bodyText !== '' ? $bodyText : null,
                'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
                'received_at' => $receivedAt,
                'status' => 'received',
            ];

            if ($hasMailboxKey) {
                $insParams['mailbox_key'] = 'demands';
            }
            
            if ($hasInReplyTo) {
                $insParams['in_reply_to'] = $inReplyTo !== '' ? $inReplyTo : null;
            }

            if ($hasFromEmail) {
                $insParams['from_email'] = $fromEmail !== '' ? $fromEmail : null;
            } elseif ($hasFromAddress) {
                $insParams['from_address'] = $fromEmail !== '' ? $fromEmail : null;
            }

            if ($hasFromName) {
                $insParams['from_name'] = $fromName !== '' ? $fromName : null;
            }

            if ($hasToAddress) {
                $insParams['to_address'] = $demandsTo !== '' ? $demandsTo : null;
            }

            $ins->execute($insParams);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Mark as seen + archive
        imap_setflag_full($imap, (string)$uid, "\\Seen", ST_UID);
        $seen++;
        $inserted++;

        if ($archiveMailbox !== '') {
            $moved = @imap_mail_move($imap, (string)$uid, $archiveMailbox, CP_UID);
            if ($moved) {
                $archived++;
            }
        }
    }

    // finalize moves
    imap_expunge($imap);

    echo 'OK: inserted=' . $inserted . ' skipped=' . $skipped . ' seen=' . $seen . ' archived=' . $archived . "\n";
} finally {
    imap_close($imap);
}
