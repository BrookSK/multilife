<?php
/**
 * Sincroniza e-mails e aciona extração de demandas.
 * 
 * Modo normal (F5 na página): faz IMAP poll + aciona o CRON de extração
 * Modo force (?force=1): faz IMAP poll + extração própria (reprocessa tudo)
 * 
 * Não duplica cards pois:
 * - Modo normal: delega extração ao CRON (mesmo código, uma só execução)
 * - Modo force: só é chamado manualmente pelo admin
 * - Ambos mudam o status do e-mail após processar
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: application/json');

@set_time_limit(60);

$results = ['imap' => null, 'extraction' => null];
$forceMode = isset($_GET['force']) && $_GET['force'] === '1';

// =============================================
// 1. POLL IMAP — Buscar e-mails novos
// =============================================
try {
    if (!function_exists('imap_open')) {
        $results['imap'] = ['error' => 'IMAP extension not available'];
    } else {
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
            $results['imap'] = ['error' => 'IMAP not configured'];
        } else {
            $flags = '/imap';
            if ($enc === 'ssl') $flags = '/imap/ssl/novalidate-cert';
            elseif ($enc === 'tls') $flags = '/imap/tls/novalidate-cert';
            else $flags = '/imap/novalidate-cert';

            $connStr = '{' . $host . ':' . $port . $flags . '}' . $mailbox;
            if (function_exists('imap_timeout')) {
                @imap_timeout(IMAP_OPENTIMEOUT, 10);
                @imap_timeout(IMAP_READTIMEOUT, 10);
            }

            $mbox = @imap_open($connStr, $user, $pass);
            if (!$mbox) {
                $results['imap'] = ['error' => 'Connection failed: ' . imap_last_error()];
            } else {
                $emails = imap_search($mbox, 'UNSEEN');
                $newCount = 0;

                if (is_array($emails) && count($emails) > 0) {
                    $emails = array_slice($emails, 0, 20);
                    foreach ($emails as $msgNum) {
                        $header = imap_headerinfo($mbox, $msgNum);
                        if (!$header) continue;

                        $subject = isset($header->subject) ? @imap_utf8((string)$header->subject) : '';
                        $fromAddr = '';
                        if (isset($header->from[0])) {
                            $fromAddr = strtolower(trim(($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? '')));
                        }
                        $date = isset($header->date) ? date('Y-m-d H:i:s', strtotime((string)$header->date)) : date('Y-m-d H:i:s');
                        $messageId = isset($header->message_id) ? trim((string)$header->message_id) : '';

                        if ($messageId !== '') {
                            $checkStmt = db()->prepare('SELECT id FROM inbound_emails WHERE message_id = :mid LIMIT 1');
                            $checkStmt->execute(['mid' => $messageId]);
                            if ($checkStmt->fetch()) {
                                imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
                                continue;
                            }
                        }

                        // Pegar body
                        $bodyText = '';
                        $structure = imap_fetchstructure($mbox, $msgNum);
                        if (!isset($structure->parts) || !$structure->parts) {
                            $bodyText = imap_fetchbody($mbox, $msgNum, '1');
                            if (isset($structure->encoding) && $structure->encoding == 3) $bodyText = base64_decode($bodyText);
                            elseif (isset($structure->encoding) && $structure->encoding == 4) $bodyText = quoted_printable_decode($bodyText);
                        } else {
                            foreach ($structure->parts as $partNum => $part) {
                                if ($part->subtype === 'PLAIN') {
                                    $bodyText = imap_fetchbody($mbox, $msgNum, (string)($partNum + 1));
                                    if ($part->encoding == 3) $bodyText = base64_decode($bodyText);
                                    elseif ($part->encoding == 4) $bodyText = quoted_printable_decode($bodyText);
                                    break;
                                }
                            }
                        }
                        if ($bodyText !== '' && !mb_check_encoding($bodyText, 'UTF-8')) {
                            $bodyText = mb_convert_encoding($bodyText, 'UTF-8', 'ISO-8859-1');
                        }

                        $insertStmt = db()->prepare(
                            'INSERT INTO inbound_emails (message_id, from_address, subject, body_text, received_at, status) '
                            . 'VALUES (:mid, :from_addr, :subject, :body_text, :received_at, :status)'
                        );
                        $insertStmt->execute([
                            'mid' => $messageId ?: ('gen_' . bin2hex(random_bytes(8))),
                            'from_addr' => $fromAddr,
                            'subject' => $subject,
                            'body_text' => $bodyText,
                            'received_at' => $date,
                            'status' => 'received',
                        ]);

                        imap_setflag_full($mbox, (string)$msgNum, '\\Seen');
                        $newCount++;
                    }
                }
                imap_close($mbox);
                $results['imap'] = ['success' => true, 'new_emails' => $newCount];
            }
        }
    }
} catch (Throwable $e) {
    $results['imap'] = ['error' => $e->getMessage()];
}

// =============================================
// 2. EXTRAÇÃO — Acionar processamento de e-mails
// =============================================
if (!$forceMode) {
    // Modo normal: apenas informa quantos e-mails estão pendentes
    // A extração é feita exclusivamente pelo CRON (evita duplicação)
    try {
        $countStmt = db()->query("SELECT COUNT(*) as cnt FROM inbound_emails WHERE status IN ('received', 'ai_pending', 'pending')");
        $pendingCount = (int)$countStmt->fetch()['cnt'];
        $results['extraction'] = ['pending_for_cron' => $pendingCount];
    } catch (Throwable $e) {
        $results['extraction'] = ['error' => $e->getMessage()];
    }
} else {
    // Modo force: extração direta (para reprocessar manualmente)
    try {
        $openaiUrl = trim((string)admin_setting_get('openai.base_url', ''));
        $openaiKey = trim((string)admin_setting_get('openai.api_key', ''));
        $openaiModel = trim((string)admin_setting_get('openai.model', 'gpt-4o-mini'));

        if ($openaiUrl === '' || $openaiKey === '') {
            $results['extraction'] = ['error' => 'OpenAI not configured'];
        } else {
            $pendingStmt = db()->prepare("SELECT * FROM inbound_emails ORDER BY id ASC LIMIT 5");
            $pendingStmt->execute();
            $pendingEmails = $pendingStmt->fetchAll();

            if (count($pendingEmails) === 0) {
                $results['extraction'] = ['success' => true, 'demands_created' => 0];
            } else {
                $extracted = 0;
                $chatEndpoint = str_ends_with($openaiUrl, '/v1')
                    ? $openaiUrl . '/chat/completions'
                    : $openaiUrl . '/v1/chat/completions';

                foreach ($pendingEmails as $em) {
                    $fromEmail = (string)($em['from_email'] ?? $em['from_address'] ?? '');
                    $emailContent = trim((string)($em['body_text'] ?? ''));
                    if ($emailContent === '') {
                        $emailContent = strip_tags((string)($em['body_html'] ?? ''));
                    }
                    if (trim($emailContent) === '') {
                        db()->prepare("UPDATE inbound_emails SET status = 'skipped' WHERE id = :id")->execute(['id' => $em['id']]);
                        continue;
                    }

                    $systemPrompt = 'Extraia de e-mails de solicitação de atendimento domiciliar os dados. Retorne APENAS JSON: {"title":"","patient_name":"","patient_phone":"","specialty":"","location_city":"","location_state":"","frequency":"","description":""}. Se não encontrar, use string vazia.';

                    $ch = curl_init($chatEndpoint);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $openaiKey,
                        'Content-Type: application/json',
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'model' => $openaiModel,
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => "ASSUNTO: " . (string)($em['subject'] ?? '') . "\n\nCORPO:\n" . mb_substr($emailContent, 0, 4000)],
                        ],
                        'temperature' => 0.1,
                    ]));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    $resp = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($httpCode < 200 || $httpCode >= 300) {
                        db()->prepare("UPDATE inbound_emails SET status = 'error' WHERE id = :id")->execute(['id' => $em['id']]);
                        continue;
                    }

                    $respData = json_decode($resp, true);
                    $raw = trim((string)($respData['choices'][0]['message']['content'] ?? ''));
                    $raw = preg_replace('/^```json\s*/i', '', $raw);
                    $raw = preg_replace('/\s*```$/i', '', $raw);
                    $parsed = json_decode($raw, true);

                    if (!is_array($parsed)) {
                        db()->prepare("UPDATE inbound_emails SET status = 'error' WHERE id = :id")->execute(['id' => $em['id']]);
                        continue;
                    }

                    $title = trim((string)($parsed['title'] ?? (string)($em['subject'] ?? '')));
                    if ($title === '') $title = 'Demanda via e-mail #' . $em['id'];

                    $description = trim((string)($parsed['description'] ?? ''));
                    $patientInfo = '';
                    if (!empty($parsed['patient_name'])) $patientInfo .= "Paciente: " . $parsed['patient_name'] . "\n";
                    if (!empty($parsed['patient_phone'])) $patientInfo .= "Telefone: " . $parsed['patient_phone'] . "\n";
                    if ($patientInfo !== '') $description = $patientInfo . "\n" . $description;

                    $insertDemand = db()->prepare(
                        "INSERT INTO demands (title, status, origin_email, specialty, location_city, location_state, location_street, location_neighborhood, location_number, frequency, description) "
                        . "VALUES (:title, 'aguardando_captacao', :origin, :spec, :city, :state, :street, :neighborhood, :number, :freq, :desc)"
                    );
                    $insertDemand->execute([
                        'title' => $title,
                        'origin' => $fromEmail,
                        'spec' => (string)($parsed['specialty'] ?? ''),
                        'city' => (string)($parsed['location_city'] ?? ''),
                        'state' => (string)($parsed['location_state'] ?? ''),
                        'street' => (string)($parsed['location_street'] ?? ''),
                        'neighborhood' => (string)($parsed['location_neighborhood'] ?? ''),
                        'number' => (string)($parsed['location_number'] ?? ''),
                        'freq' => (string)($parsed['frequency'] ?? ''),
                        'desc' => $description,
                    ]);

                    db()->prepare("UPDATE inbound_emails SET status = 'processed' WHERE id = :id")->execute(['id' => $em['id']]);
                    $extracted++;
                }
                $results['extraction'] = ['success' => true, 'demands_created' => $extracted];
            }
        }
    } catch (Throwable $e) {
        $results['extraction'] = ['error' => $e->getMessage()];
    }
}

echo json_encode($results);
