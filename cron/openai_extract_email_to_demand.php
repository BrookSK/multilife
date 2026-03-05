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

    // CHAMADA 1: Extrair dados básicos (título, localização, especialidade, valor, urgência)
    $systemPrompt1 = "Você é um assistente que extrai dados estruturados de e-mails de solicitação de atendimento domiciliar (home care).\n"
        . "Analise o e-mail e extraia SOMENTE os dados básicos.\n\n"
        . "Retorne um JSON válido no formato:\n"
        . "{\"title\":string,\"location_city\":string|null,\"location_state\":string|null,\"specialty\":string|null,\"procedure_value\":number|null,\"urgency\":string|null}\n\n"
        . "Campos:\n"
        . "- title: Título curto e objetivo (máx 60 caracteres)\n"
        . "- location_city: Cidade do atendimento\n"
        . "- location_state: UF com 2 letras maiúsculas (ex: SP, RJ)\n"
        . "- specialty: Especialidade médica (ex: Fisioterapia, Enfermagem, Fonoaudiologia)\n"
        . "- procedure_value: Valor em reais como número decimal (ex: 1500.00)\n"
        . "- urgency: Nível de urgência (urgente, normal, baixa) baseado no contexto\n\n"
        . "Regras:\n"
        . "- Seja preciso e objetivo\n"
        . "- UF sempre 2 letras maiúsculas\n"
        . "- Se não encontrar, use null\n"
        . "- Responda SOMENTE com JSON válido";

    $userPrompt1 = "ASSUNTO: " . $subject . "\n" . "REMETENTE: " . $fromEmail . "\n\nCORPO DO E-MAIL:\n" . $content;

    try {
        // Primeira chamada: dados básicos
        $res1 = $api->chatCompletions(
            [
                ['role' => 'system', 'content' => $systemPrompt1],
                ['role' => 'user', 'content' => $userPrompt1],
            ],
            null,
            [
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
            ]
        );

        $statusCode1 = (int)($res1['status'] ?? 0);
        if ($statusCode1 < 200 || $statusCode1 >= 300) {
            $msg = '';
            $json = $res1['json'] ?? null;
            if (is_array($json)) {
                $msg = (string)($json['error']['message'] ?? '');
            }
            if ($msg === '') {
                $msg = (string)($res1['body_raw'] ?? '');
            }
            $msg = trim($msg);
            if ($msg === '') {
                $msg = 'HTTP ' . (string)$statusCode1;
            }
            throw new RuntimeException('OpenAI error (chamada 1): ' . $msg);
        }

        $json1 = $res1['json'] ?? null;
        $raw1 = '';
        if (is_array($json1)) {
            $raw1 = (string)($json1['choices'][0]['message']['content'] ?? '');
        }
        $raw1 = trim($raw1);

        if ($raw1 === '') {
            throw new RuntimeException('OpenAI retornou vazio (chamada 1).');
        }

        $parsed1 = json_decode($raw1, true);
        if (!is_array($parsed1)) {
            $start = strpos($raw1, '{');
            $end = strrpos($raw1, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $maybe = substr($raw1, $start, $end - $start + 1);
                $maybeParsed = json_decode($maybe, true);
                if (is_array($maybeParsed)) {
                    $parsed1 = $maybeParsed;
                }
            }
        }
        if (!is_array($parsed1)) {
            throw new RuntimeException('OpenAI não retornou JSON válido (chamada 1). Conteúdo: ' . mb_strimwidth($raw1, 0, 180, '')); 
        }

        // Extrair dados básicos
        $title = trim((string)($parsed1['title'] ?? ''));
        $city = trim((string)($parsed1['location_city'] ?? ''));
        $state = strtoupper(trim((string)($parsed1['location_state'] ?? '')));
        $specialty = trim((string)($parsed1['specialty'] ?? ''));
        $procedureValue = isset($parsed1['procedure_value']) && $parsed1['procedure_value'] !== null ? (float)$parsed1['procedure_value'] : null;
        $urgency = trim((string)($parsed1['urgency'] ?? ''));

        // CHAMADA 2: Gerar resumo detalhado e descrição do card
        $systemPrompt2 = "Você é um assistente especializado em criar resumos de solicitações de atendimento domiciliar (home care).\n"
            . "Analise o e-mail completo e crie um resumo estruturado e profissional.\n\n"
            . "Retorne um JSON válido no formato:\n"
            . "{\"description\":string,\"ai_summary\":string}\n\n"
            . "Campos:\n"
            . "- description: Descrição completa e detalhada extraída do e-mail (todos os detalhes relevantes)\n"
            . "- ai_summary: Resumo executivo ESTRUTURADO EM PARÁGRAFOS, focado nas características do atendimento\n\n"
            . "Regras para description:\n"
            . "- Incluir TODOS os detalhes médicos relevantes\n"
            . "- Incluir dados do paciente (nome, idade, diagnóstico)\n"
            . "- Incluir serviços solicitados e frequência\n"
            . "- Manter formatação clara e profissional\n\n"
            . "Regras para ai_summary (MUITO IMPORTANTE):\n"
            . "- ESTRUTURAR EM PARÁGRAFOS separados por quebras de linha (\\n\\n)\n"
            . "- Parágrafo 1: Dados do paciente (nome, idade, diagnóstico principal)\n"
            . "- Parágrafo 2: Necessidade/serviço solicitado e frequência\n"
            . "- Parágrafo 3: Valor e urgência (se houver)\n"
            . "- Ser objetivo e pontual em cada parágrafo\n"
            . "- Facilitar identificação rápida das características\n\n"
            . "Responda SOMENTE com JSON válido";

        $userPrompt2 = "ASSUNTO: " . $subject . "\n" . "REMETENTE: " . $fromEmail . "\n\nCORPO DO E-MAIL:\n" . $content;

        $res2 = $api->chatCompletions(
            [
                ['role' => 'system', 'content' => $systemPrompt2],
                ['role' => 'user', 'content' => $userPrompt2],
            ],
            null,
            [
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
            ]
        );

        $statusCode2 = (int)($res2['status'] ?? 0);
        if ($statusCode2 < 200 || $statusCode2 >= 300) {
            $msg = '';
            $json = $res2['json'] ?? null;
            if (is_array($json)) {
                $msg = (string)($json['error']['message'] ?? '');
            }
            if ($msg === '') {
                $msg = (string)($res2['body_raw'] ?? '');
            }
            $msg = trim($msg);
            if ($msg === '') {
                $msg = 'HTTP ' . (string)$statusCode2;
            }
            throw new RuntimeException('OpenAI error (chamada 2): ' . $msg);
        }

        $json2 = $res2['json'] ?? null;
        $raw2 = '';
        if (is_array($json2)) {
            $raw2 = (string)($json2['choices'][0]['message']['content'] ?? '');
        }
        $raw2 = trim($raw2);

        if ($raw2 === '') {
            throw new RuntimeException('OpenAI retornou vazio (chamada 2).');
        }

        $parsed2 = json_decode($raw2, true);
        if (!is_array($parsed2)) {
            $start = strpos($raw2, '{');
            $end = strrpos($raw2, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $maybe = substr($raw2, $start, $end - $start + 1);
                $maybeParsed = json_decode($maybe, true);
                if (is_array($maybeParsed)) {
                    $parsed2 = $maybeParsed;
                }
            }
        }
        if (!is_array($parsed2)) {
            throw new RuntimeException('OpenAI não retornou JSON válido (chamada 2). Conteúdo: ' . mb_strimwidth($raw2, 0, 180, '')); 
        }

        // Extrair descrição e resumo
        $desc = trim((string)($parsed2['description'] ?? ''));
        $aiSummary = trim((string)($parsed2['ai_summary'] ?? ''));
        
        // Adicionar corpo do e-mail original completo abaixo do resumo
        if ($desc !== '') {
            $desc .= "\n\n--- E-MAIL ORIGINAL ---\n\n" . $content;
        } else {
            $desc = $content;
        }

        // Validações e defaults
        if ($title === '') {
            $title = $subject !== '' ? $subject : 'Demanda recebida por e-mail';
        }

        if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
            $state = '';
        }

        // Determinar status baseado em completude dos dados
        $status = 'aguardando_captacao';
        $needsManual = ($city === '' || $state === '' || $specialty === '');
        if ($needsManual) {
            $status = 'tratamento_manual';
        }
        
        // Se urgente, priorizar
        if ($urgency === 'urgente' && !$needsManual) {
            $status = 'aguardando_captacao';
        }

        $db->beginTransaction();
        try {
            $insDemand->execute([
                't' => $title,
                'c' => $city !== '' ? $city : null,
                's' => $state !== '' ? $state : null,
                'sp' => $specialty !== '' ? $specialty : null,
                'd' => $desc !== '' ? $desc : null,
                'o' => $fromEmail !== '' ? $fromEmail : null,
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
