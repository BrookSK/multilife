<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$idFilter = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$retryErrors = isset($_GET['retry_errors']) && ((string)$_GET['retry_errors'] === '1' || strtolower((string)$_GET['retry_errors']) === 'true');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit <= 0 || $limit > 100) {
    $limit = 10;
}

$debug = isset($_GET['debug']) && ((string)$_GET['debug'] === '1' || strtolower((string)$_GET['debug']) === 'true');

$db = db();

$colsStmt = $db->prepare('SHOW COLUMNS FROM inbound_emails');
$colsStmt->execute();
$cols = [];
foreach ($colsStmt->fetchAll() as $c) {
    if (isset($c['Field'])) {
        $cols[(string)$c['Field']] = true;
    }
}

$hasMailboxKey = isset($cols['mailbox_key']);
$hasLinkedDemandId = isset($cols['linked_demand_id']);
$hasFromEmail = isset($cols['from_email']);
$hasFromAddress = isset($cols['from_address']);

$pendingStatus = $hasMailboxKey ? 'ai_pending' : 'received';
$doneStatus = $hasMailboxKey ? 'ai_processed' : 'processed';
$selectFromField = $hasFromEmail ? 'from_email' : ($hasFromAddress ? 'from_address' : null);

$db->beginTransaction();
try {
    $statusList = "'received','ai_pending'";
    if ($retryErrors) {
        $statusList .= ",'error'";
    }

    $stmt = $db->prepare(
        "SELECT * FROM inbound_emails\n"
        . "WHERE status IN ($statusList)\n"
        . ($idFilter > 0 ? "AND id = :id\n" : '')
        . "ORDER BY received_at ASC, id ASC\n"
        . "LIMIT $limit\n"
        . "FOR UPDATE"
    );

    $params = [];
    if ($idFilter > 0) {
        $params['id'] = $idFilter;
    }
    $stmt->execute($params);
    $emails = $stmt->fetchAll();

    if (count($emails) === 0) {
        $db->commit();
        echo "OK: no inbound_emails\n";
        exit;
    }

    $markPending = $db->prepare("UPDATE inbound_emails SET status = :st WHERE id = :id");
    foreach ($emails as $e) {
        $markPending->execute(['id' => (int)$e['id'], 'st' => $pendingStatus]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

$api = new OpenAiApi();

$okSet = "status = :st, processed_at = :pa, error_message = NULL";
if ($hasLinkedDemandId) {
    $okSet .= ", linked_demand_id = :did";
}

$updOk = $db->prepare(
    "UPDATE inbound_emails\n"
    . "SET $okSet\n"
    . "WHERE id = :id"
);
$updErr = $db->prepare(
    "UPDATE inbound_emails\n"
    . "SET status = 'error', error_message = :err, processed_at = :pa\n"
    . "WHERE id = :id"
);

$insDemand = $db->prepare(
    'INSERT INTO demands (title, location_city, location_state, specialty, description, origin_email, status, procedure_value, ai_summary)'
    . ' VALUES (:t,:c,:s,:sp,:d,:o,:st,:pv,:as)'
);
$insDemandLog = $db->prepare(
    'INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)'
    . ' VALUES (:did, NULL, :ns, NULL, :note)'
);

$ok = 0;
$manual = 0;
$errors = 0;
$errorLines = [];
$selectedEmailIds = [];
$createdLines = [];

foreach ($emails as $e) {
    $id = (int)$e['id'];
    if ($debug) {
        $selectedEmailIds[] = $id;
    }
    $subject = (string)($e['subject'] ?? '');
    $fromEmail = '';
    if ($selectFromField !== null) {
        $fromEmail = (string)($e[$selectFromField] ?? '');
    }
    $bodyText = (string)($e['body_text'] ?? '');
    $bodyHtml = (string)($e['body_html'] ?? '');

    $content = trim($bodyText);
    if ($content === '' && $bodyHtml !== '') {
        $content = trim(strip_tags($bodyHtml));
    }

    if ($content === '') {
        $errors++;
        $updErr->execute([
            'err' => 'E-mail sem corpo (body_text/body_html vazio).',
            'pa' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
        continue;
    }

    $systemPrompt = (string)admin_setting_get(
        'openai.extract_prompt',
        "Você é um assistente que extrai dados de solicitações de atendimento domiciliar (home care) a partir de e-mails.\n"
        . "Retorne SOMENTE um JSON válido no seguinte formato:\n"
        . "{\"title\":string,\"location_city\":string|null,\"location_state\":string|null,\"specialty\":string|null,\"description\":string|null,\"origin\":string|null,\"procedure_value\":number|null,\"ai_summary\":string|null}\n\n"
        . "Campos:\n"
        . "- title: Título resumido da solicitação\n"
        . "- location_city: Cidade do atendimento\n"
        . "- location_state: UF do atendimento (sempre 2 letras maiúsculas)\n"
        . "- specialty: Especialidade médica necessária\n"
        . "- description: Descrição completa extraída do e-mail\n"
        . "- origin: E-mail ou origem da solicitação\n"
        . "- procedure_value: Valor do procedimento em reais (apenas número, sem R$)\n"
        . "- ai_summary: Resumo objetivo da necessidade do paciente em 2-3 frases\n\n"
        . "Regras:\n"
        . "- UF sempre com 2 letras maiúsculas quando existir\n"
        . "- procedure_value deve ser número decimal (ex: 1500.00)\n"
        . "- ai_summary deve ser claro, objetivo e focado na necessidade do paciente\n"
        . "- Se não conseguir identificar um campo, use null"
    );

    $systemPrompt .= "\n\nResponda somente com json válido.";

    $userPrompt = "ASSUNTO: " . $subject . "\n" . "REMETENTE: " . $fromEmail . "\n\n" . $content;

    try {
        $res = $api->chatCompletions(
            [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            null,
            [
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
            ]
        );

        $statusCode = (int)($res['status'] ?? 0);
        if ($statusCode < 200 || $statusCode >= 300) {
            $msg = '';
            $json = $res['json'] ?? null;
            if (is_array($json)) {
                $msg = (string)($json['error']['message'] ?? '');
            }
            if ($msg === '') {
                $msg = (string)($res['body_raw'] ?? '');
            }
            $msg = trim($msg);
            if ($msg === '') {
                $msg = 'HTTP ' . (string)$statusCode;
            }
            throw new RuntimeException('OpenAI error: ' . $msg);
        }

        $json = $res['json'] ?? null;
        $raw = '';
        if (is_array($json)) {
            $raw = (string)($json['choices'][0]['message']['content'] ?? '');
        }
        $raw = trim($raw);

        if ($raw === '') {
            throw new RuntimeException('OpenAI retornou vazio.');
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $maybe = substr($raw, $start, $end - $start + 1);
                $maybeParsed = json_decode($maybe, true);
                if (is_array($maybeParsed)) {
                    $parsed = $maybeParsed;
                }
            }
        }
        if (!is_array($parsed)) {
            throw new RuntimeException('OpenAI não retornou JSON válido. Conteúdo: ' . mb_strimwidth($raw, 0, 180, '')); 
        }

        $title = trim((string)($parsed['title'] ?? ''));
        $city = trim((string)($parsed['location_city'] ?? ''));
        $state = strtoupper(trim((string)($parsed['location_state'] ?? '')));
        $specialty = trim((string)($parsed['specialty'] ?? ''));
        $desc = trim((string)($parsed['description'] ?? ''));
        $origin = trim((string)($parsed['origin'] ?? ''));
        $procedureValue = isset($parsed['procedure_value']) && $parsed['procedure_value'] !== null ? (float)$parsed['procedure_value'] : null;
        $aiSummary = trim((string)($parsed['ai_summary'] ?? ''));

        if ($title === '') {
            $title = $subject !== '' ? $subject : 'Demanda recebida por e-mail';
        }

        if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
            $state = '';
        }

        $status = 'aguardando_captacao';
        $needsManual = ($city === '' || $state === '' || $specialty === '');
        if ($needsManual) {
            $status = 'tratamento_manual';
        }

        $db->beginTransaction();
        try {
            $insDemand->execute([
                't' => $title,
                'c' => $city !== '' ? $city : null,
                's' => $state !== '' ? $state : null,
                'sp' => $specialty !== '' ? $specialty : null,
                'd' => $desc !== '' ? $desc : null,
                'o' => $fromEmail !== '' ? $fromEmail : ($origin !== '' ? $origin : null),
                'st' => $status,
                'pv' => $procedureValue,
                'as' => $aiSummary !== '' ? $aiSummary : null,
            ]);
            $demandId = (int)$db->lastInsertId();

            $note = 'criação automática via e-mail';
            if ($needsManual) {
                $missing = [];
                if ($city === '' || $state === '') {
                    $missing[] = 'localização';
                }
                if ($specialty === '') {
                    $missing[] = 'especialidade';
                }
                if (count($missing) > 0) {
                    $note .= ' (tratamento_manual: faltando ' . implode(', ', $missing) . ')';
                }
            }

            $insDemandLog->execute([
                'did' => $demandId,
                'ns' => $status,
                'note' => $note,
            ]);

            $updParams = [
                'st' => $doneStatus,
                'pa' => date('Y-m-d H:i:s'),
                'id' => $id,
            ];
            if ($hasLinkedDemandId) {
                $updParams['did'] = $demandId;
            }
            $updOk->execute($updParams);

            $db->commit();
            $ok++;
            if ($debug) {
                $createdLines[] = 'EMAIL #' . (string)$id . ' -> DEMAND #' . (string)$demandId . ' (' . $status . ')';
            }
        } catch (Throwable $e2) {
            $db->rollBack();
            throw $e2;
        }

        if ($needsManual) {
            $manual++;
        }
    } catch (Throwable $ex) {
        $errors++;
        $errMsg = (string)$ex->getMessage();
        $updErr->execute([
            'err' => mb_strimwidth($errMsg, 0, 250, '...'),
            'pa' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);

        if ($debug) {
            $errorLines[] = 'EMAIL #' . (string)$id . ': ' . $errMsg;
        }
    }
}

echo 'OK: created=' . ($ok + $manual) . ' manual=' . $manual . ' errors=' . $errors . "\n";

if ($debug && count($errorLines) > 0) {
    echo "\n";
    if (count($selectedEmailIds) > 0) {
        echo 'SELECTED EMAIL IDS: ' . implode(',', array_map('strval', $selectedEmailIds)) . "\n";
    }
    if (count($createdLines) > 0) {
        echo "\n";
        foreach ($createdLines as $l) {
            echo $l . "\n";
        }
    }
    echo "\n";
    foreach ($errorLines as $l) {
        echo $l . "\n";
    }
}

if ($debug && count($errorLines) === 0) {
    if (count($selectedEmailIds) > 0) {
        echo "\n";
        echo 'SELECTED EMAIL IDS: ' . implode(',', array_map('strval', $selectedEmailIds)) . "\n";
    }
    if (count($createdLines) > 0) {
        echo "\n";
        foreach ($createdLines as $l) {
            echo $l . "\n";
        }
    }
}
