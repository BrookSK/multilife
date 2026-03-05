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

$host = trim((string)admin_setting_get('smtp.in.host', ''));
$port = (int)admin_setting_get('smtp.in.port', '993');
$enc = strtolower(trim((string)admin_setting_get('smtp.in.encryption', 'ssl')));
$user = trim((string)admin_setting_get('smtp.in.username', ''));
$pass = (string)admin_setting_get('smtp.in.password', '');
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

$insertColumns = [];
$insertValues = [];

if ($hasMailboxKey) {
    $insertColumns[] = 'mailbox_key';
    $insertValues[] = ':mailbox_key';
}

$insertColumns[] = 'message_id';
$insertValues[] = ':message_id';

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

        if ($ov) {
            $messageId = isset($ov->message_id) ? (string)$ov->message_id : '';
            $subject = isset($ov->subject) ? (string)$ov->subject : '';
            $fromRaw = isset($ov->from) ? (string)$ov->from : '';
            $dateRaw = isset($ov->date) ? (string)$ov->date : '';
        }

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

        $subjectUtf8 = $subject !== '' ? (string)imap_utf8($subject) : '';

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

        // Body
        $bodyText = '';
        $bodyHtml = '';

        $uidInt = (int)$uid;
        $structure = imap_fetchstructure($imap, $uidInt, FT_UID);
        if ($structure) {
            // Best-effort: attempt common parts
            $raw = imap_fetchbody($imap, $uidInt, '1', FT_UID);
            if ($raw === false || $raw === '') {
                $raw = imap_body($imap, $uidInt, FT_UID);
            }
            if (is_string($raw)) {
                $bodyText = imap_utf8($raw);
            }

            // Try HTML part if multipart
            $rawHtml = imap_fetchbody($imap, $uidInt, '1.2', FT_UID);
            if (is_string($rawHtml) && $rawHtml !== '') {
                $bodyHtml = $rawHtml;
            }
        } else {
            $raw = imap_body($imap, $uidInt, FT_UID);
            if (is_string($raw)) {
                $bodyText = imap_utf8($raw);
            }
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
