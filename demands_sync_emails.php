<?php
/**
 * Sincroniza e-mails e cria demandas automaticamente.
 * Chamado via AJAX ao carregar a página de demandas.
 * Executa: 1) Poll IMAP  2) Extração OpenAI → Demandas
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: application/json');

@set_time_limit(60);

$results = ['imap' => null, 'extraction' => null];

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
                    // Limitar a 20 por vez para não travar
                    $emails = array_slice($emails, 0, 20);

                    foreach ($emails as $msgNum) {
                        $header = imap_headerinfo($mbox, $msgNum);
                        if (!$header) continue;

                        $subject = isset($header->subject) ? imap_utf8((string)$header->subject) : '';
                        $fromAddr = '';
                        $fromName = '';
                        if (isset($header->from[0])) {
                            $fromAddr = strtolower(trim(($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? '')));
                            $fromName = isset($header->from[0]->personal) ? imap_utf8((string)$header->from[0]->personal) : '';
                        }

                        $date = isset($header->date) ? date('Y-m-d H:i:s', strtotime((string)$header->date)) : date('Y-m-d H:i:s');
                        $messageId = isset($header->message_id) ? trim((string)$header->message_id) : '';

                        // Verificar se já existe
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
                        $bodyHtml = '';
                        $structure = imap_fetchstructure($mbox, $msgNum);
                        
                        if (!$structure->parts) {
                            // Single part
                            $bodyText = imap_fetchbody($mbox, $msgNum, '1');
                            if ($structure->encoding == 3) $bodyText = base64_decode($bodyText);
                            elseif ($structure->encoding == 4) $bodyText = quoted_printable_decode($bodyText);
                        } else {
                            // Multipart - get text/plain or text/html
                            foreach ($structure->parts as $partNum => $part) {
                                $partNo = (string)($partNum + 1);
                                if ($part->subtype === 'PLAIN') {
                                    $bodyText = imap_fetchbody($mbox, $msgNum, $partNo);
                                    if ($part->encoding == 3) $bodyText = base64_decode($bodyText);
                                    elseif ($part->encoding == 4) $bodyText = quoted_printable_decode($bodyText);
                                } elseif ($part->subtype === 'HTML') {
                                    $bodyHtml = imap_fetchbody($mbox, $msgNum, $partNo);
                                    if ($part->encoding == 3) $bodyHtml = base64_decode($bodyHtml);
                                    elseif ($part->encoding == 4) $bodyHtml = quoted_printable_decode($bodyHtml);
                                }
                            }
                        }

                        // Detectar charset e converter para UTF-8
                        if ($bodyText !== '' && !mb_check_encoding($bodyText, 'UTF-8')) {
                            $bodyText = mb_convert_encoding($bodyText, 'UTF-8', 'ISO-8859-1');
                        }

                        // Salvar
                        $insertStmt = db()->prepare(
                            'INSERT INTO inbound_emails (message_id, from_email, from_address, subject, body_text, body_html, received_at, status) '
                            . 'VALUES (:mid, :from_email, :from_addr, :subject, :body_text, :body_html, :received_at, :status)'
                        );
                        $insertStmt->execute([
                            'mid' => $messageId,
                            'from_email' => $fromAddr,
                            'from_addr' => $fromName ? ($fromName . ' <' . $fromAddr . '>') : $fromAddr,
                            'subject' => $subject,
                            'body_text' => $bodyText,
                            'body_html' => $bodyHtml,
                            'received_at' => $date,
                            'status' => 'pending',
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
// 2. EXTRAÇÃO OpenAI — Converter e-mails em demandas
// =============================================
try {
    $openaiUrl = trim((string)admin_setting_get('openai.base_url', ''));
    $openaiKey = trim((string)admin_setting_get('openai.api_key', ''));
    $openaiModel = trim((string)admin_setting_get('openai.model', 'gpt-4o-mini'));

    if ($openaiUrl === '' || $openaiKey === '') {
        $results['extraction'] = ['error' => 'OpenAI not configured'];
    } else {
        // Buscar e-mails que ainda não têm demanda criada
        // Inclui processed sem linked_demand_id (falhou antes) e pending/error/received
        $pendingStmt = db()->prepare(
            "SELECT * FROM inbound_emails WHERE (status NOT IN ('skipped', 'ai_processed') AND (linked_demand_id IS NULL OR linked_demand_id = 0)) ORDER BY id ASC LIMIT 5"
        );
        try {
            $pendingStmt->execute();
        } catch (Throwable $e) {
            // Se linked_demand_id não existe, buscar sem ela
            $pendingStmt = db()->prepare(
                "SELECT * FROM inbound_emails WHERE status NOT IN ('skipped', 'ai_processed', 'processed') ORDER BY id ASC LIMIT 5"
            );
            $pendingStmt->execute();
        }
        $pendingEmails = $pendingStmt->fetchAll();

        // Debug: mostrar total de e-mails na tabela
        $totalStmt = db()->query("SELECT COUNT(*) as total FROM inbound_emails");
        $totalRow = $totalStmt->fetch();
        $statusStmt = db()->query("SELECT status, COUNT(*) as cnt FROM inbound_emails GROUP BY status");
        $statusCounts = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

        $extracted = 0;
        $extractPrompt = (string)admin_setting_get('openai.extract_prompt', '');

        if (count($pendingEmails) === 0) {
            $results['extraction'] = [
                'success' => true,
                'demands_created' => 0,
                'message' => 'No pending emails',
                'total_in_table' => (int)($totalRow['total'] ?? 0),
                'status_breakdown' => $statusCounts,
            ];
        } else {
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

                // Chamar OpenAI para extrair dados
                $systemPrompt = $extractPrompt !== '' ? $extractPrompt : 'Extraia de e-mails de solicitação de atendimento domiciliar os dados do paciente e do serviço solicitado. Retorne APENAS JSON com os campos: title, patient_name, patient_email, patient_phone, specialty, location_city, location_state, location_address, location_street, location_neighborhood, frequency, description, origin. Se não encontrar algum campo, use string vazia.';

                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "ASSUNTO: " . (string)($em['subject'] ?? '') . "\n\nCORPO:\n" . mb_substr($emailContent, 0, 4000)],
                ];

                $ch = curl_init($openaiUrl . '/chat/completions');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $openaiKey,
                    'Content-Type: application/json',
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'model' => $openaiModel,
                    'messages' => $messages,
                    'temperature' => 0.1,
                ]));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode < 200 || $httpCode >= 300) {
                    db()->prepare("UPDATE inbound_emails SET status = 'error' WHERE id = :id")->execute(['id' => $em['id']]);
                    error_log('[DEMAND_SYNC] OpenAI erro HTTP ' . $httpCode . ' para email #' . $em['id'] . ': ' . substr($resp, 0, 300));
                    continue;
                }

                $respData = json_decode($resp, true);
                $raw = trim((string)($respData['choices'][0]['message']['content'] ?? ''));
                
                // Limpar markdown se houver
                $raw = preg_replace('/^```json\s*/i', '', $raw);
                $raw = preg_replace('/\s*```$/i', '', $raw);

                $parsed = json_decode($raw, true);
                if (!is_array($parsed)) {
                    db()->prepare("UPDATE inbound_emails SET status = 'error' WHERE id = :id")->execute(['id' => $em['id']]);
                    error_log('[DEMAND_SYNC] OpenAI resposta inválida para email #' . $em['id'] . ': ' . substr($raw, 0, 300));
                    continue;
                }

                // Criar demanda
                $title = trim((string)($parsed['title'] ?? (string)($em['subject'] ?? '')));
                if ($title === '') $title = 'Demanda via e-mail #' . $em['id'];

                $description = trim((string)($parsed['description'] ?? ''));
                // Incluir dados do paciente na descrição se existirem
                $patientInfo = '';
                if (!empty($parsed['patient_name'])) $patientInfo .= "Paciente: " . $parsed['patient_name'] . "\n";
                if (!empty($parsed['patient_email'])) $patientInfo .= "E-mail: " . $parsed['patient_email'] . "\n";
                if (!empty($parsed['patient_phone'])) $patientInfo .= "Telefone: " . $parsed['patient_phone'] . "\n";
                if ($patientInfo !== '') {
                    $description = $patientInfo . "\n" . $description;
                }

                $insertDemand = db()->prepare(
                    "INSERT INTO demands (title, status, origin_email, specialty, location_city, location_state, frequency, description) "
                    . "VALUES (:title, 'aguardando_captacao', :origin, :spec, :city, :state, :freq, :desc)"
                );
                $insertDemand->execute([
                    'title' => $title,
                    'origin' => $fromEmail,
                    'spec' => (string)($parsed['specialty'] ?? ''),
                    'city' => (string)($parsed['location_city'] ?? ''),
                    'state' => (string)($parsed['location_state'] ?? ''),
                    'freq' => (string)($parsed['frequency'] ?? ''),
                    'desc' => $description,
                ]);

                $demandId = (int)db()->lastInsertId();

                // Marcar e-mail como processado
                try {
                    $updateStmt = db()->prepare("UPDATE inbound_emails SET status = 'processed', linked_demand_id = :did WHERE id = :id");
                    $updateStmt->execute(['did' => $demandId, 'id' => $em['id']]);
                } catch (Throwable $e2) {
                    // Se coluna linked_demand_id não existe, tentar sem ela
                    $updateStmt = db()->prepare("UPDATE inbound_emails SET status = 'processed' WHERE id = :id");
                    $updateStmt->execute(['id' => $em['id']]);
                }

                $extracted++;
            }

            $results['extraction'] = ['success' => true, 'demands_created' => $extracted];
        }
    }
} catch (Throwable $e) {
    $results['extraction'] = ['error' => $e->getMessage()];
}

echo json_encode($results);
