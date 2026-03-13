<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$idFilter = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$retryErrors = isset($_GET['retry_errors']) && ((string)$_GET['retry_errors'] === '1' || strtolower((string)$_GET['retry_errors']) === 'true');
$forceReprocess = isset($_GET['force']) && ((string)$_GET['force'] === '1' || strtolower((string)$_GET['force']) === 'true');

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
    // Se force=1, ignora filtro de status (reprocessa qualquer e-mail)
    if ($forceReprocess && $idFilter > 0) {
        $whereClause = "WHERE id = :id";
    } else {
        $statusList = "'received','ai_pending'";
        if ($retryErrors) {
            $statusList .= ",'error'";
        }
        $whereClause = "WHERE status IN ($statusList)" . ($idFilter > 0 ? " AND id = :id" : '');
    }

    $stmt = $db->prepare(
        "SELECT * FROM inbound_emails\n"
        . "$whereClause\n"
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

// Verificar se é resposta de autorização antes de processar como nova demanda
// IMPORTANTE: Identificação única por email_thread_id para garantir que cada resposta
// seja vinculada à autorização correta, mesmo quando há múltiplas autorizações
// para o mesmo paciente (ex: fisioterapia #14 e fonoaudiologia #16)
$checkAuthResponse = $db->prepare(
    "SELECT ar.id, ar.operator_email, ar.email_thread_id, ar.demand_id, ar.patient_id, ar.sent_message_id
     FROM authorization_requests ar
     WHERE ar.status = 'aguardando_autorizacao'
     AND ar.sent_at IS NOT NULL
     AND ar.response_received_at IS NULL
     AND ar.patient_id > 0"
);

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
    'INSERT INTO demands (title, location_city, location_state, specialty, description, origin_email, status, procedure_value, ai_summary, urgency)'
    . ' VALUES (:t,:c,:s,:sp,:d,:o,:st,:pv,:as,:urg)'
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
    $threadId = (string)($e['thread_id'] ?? '');
    $inReplyTo = (string)($e['in_reply_to'] ?? '');
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
    
    // ========================================================================
    // IDENTIFICAÇÃO DE RESPOSTAS DE AUTORIZAÇÃO - CRITÉRIOS OBJETIVOS APENAS
    // ========================================================================
    // Usa APENAS critérios técnicos e seguros:
    // 1. Message-ID (In-Reply-To header) - 100% preciso
    // 2. Thread-ID único - Alta precisão
    // 3. E-mail da operadora - Precisão média (último recurso)
    //
    // NÃO usa palavras-chave ou análise de assunto para evitar falsos positivos
    // ========================================================================
    
    error_log("[EMAIL_EXTRACT] E-mail #$id - Verificando se é resposta de autorização (critérios objetivos)");
    error_log("[EMAIL_EXTRACT] E-mail #$id - Remetente: $fromEmail");
    error_log("[EMAIL_EXTRACT] E-mail #$id - Thread ID: $threadId");
    error_log("[EMAIL_EXTRACT] E-mail #$id - In-Reply-To: $inReplyTo");
    error_log("[EMAIL_EXTRACT] E-mail #$id - Assunto: $subject");
    
    $checkAuthResponse->execute();
    $pendingAuths = $checkAuthResponse->fetchAll();
    
    error_log("[EMAIL_EXTRACT] E-mail #$id - Autorizações aguardando resposta: " . count($pendingAuths));
    
    $isAuthorizationResponse = false;
    $matchedAuthId = null;
    
    foreach ($pendingAuths as $auth) {
        $authMessageId = trim((string)($auth['sent_message_id'] ?? ''));
        
        error_log("[EMAIL_EXTRACT] E-mail #$id - Comparando Auth #" . $auth['id'] . " (Patient: " . $auth['patient_id'] . ", Demand: " . $auth['demand_id'] . ")");
        error_log("[EMAIL_EXTRACT]   🔑 CHAVE (Message-ID enviado): '$authMessageId'");
        error_log("[EMAIL_EXTRACT]   🔓 CADEADO (In-Reply-To recebido): '$inReplyTo'");
        
        // ========================================================================
        // MODELO CHAVE-CADEADO: ÚNICO CRITÉRIO DE IDENTIFICAÇÃO
        // ========================================================================
        // Chave (🔑): Message-ID único gerado ao ENVIAR proposta
        // Cadeado (🔓): Header In-Reply-To do e-mail de RESPOSTA
        // 
        // Se Chave = Cadeado → Resposta identificada com 100% de certeza
        // Se Chave ≠ Cadeado → NÃO é resposta desta autorização
        // 
        // NÃO usa Thread-ID, operadora ou qualquer outro critério
        // ========================================================================
        
        if ($inReplyTo !== '' && $authMessageId !== '' && $inReplyTo === $authMessageId) {
            $isAuthorizationResponse = true;
            $matchedAuthId = (int)$auth['id'];
            error_log("[EMAIL_EXTRACT] E-mail #$id ✅✅✅ CHAVE-CADEADO MATCH! Resposta de autorização #$matchedAuthId");
            error_log("[EMAIL_EXTRACT]   Patient ID: " . $auth['patient_id'] . " | Demand ID: " . $auth['demand_id']);
            error_log("[EMAIL_EXTRACT]   🔑🔓 Identificação 100% precisa - Modelo chave-cadeado");
            break;
        }
    }
    
    if (!$isAuthorizationResponse) {
        error_log("[EMAIL_EXTRACT] E-mail #$id - 🔑🔓 Chave-cadeado NÃO identificou como resposta, processando como nova demanda");
    }
    
    // Se for resposta de autorização, processar imediatamente
    if ($isAuthorizationResponse && $matchedAuthId !== null) {
        error_log("[EMAIL_EXTRACT] Processando resposta de autorização IMEDIATAMENTE");
        
        // Incluir função de processamento
        require_once __DIR__ . '/../app/process_single_authorization.php';
        
        // Processar autorização
        $result = process_single_authorization($matchedAuthId, $id);
        
        if ($result['success']) {
            error_log("[EMAIL_EXTRACT] ✅ Autorização #$matchedAuthId processada com sucesso!");
            error_log("[EMAIL_EXTRACT] Decisão: " . ($result['decision'] ?? 'unknown'));
            
            // Marcar e-mail como processado
            $updOk->execute([
                'st' => 'processed',
                'pa' => date('Y-m-d H:i:s'),
                'id' => $id,
            ]);
            $ok++;
        } else {
            error_log("[EMAIL_EXTRACT] ❌ Erro ao processar autorização: " . ($result['error'] ?? 'unknown'));
            
            // Marcar como erro para tentar novamente depois
            $updErr->execute([
                'err' => 'Erro ao processar autorização: ' . ($result['error'] ?? 'unknown'),
                'pa' => date('Y-m-d H:i:s'),
                'id' => $id,
            ]);
            $errors++;
        }
        
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
        
        // Salvar apenas o e-mail original completo (sem duplicar a descrição da IA)
        $desc = $content;

        // Validações e defaults
        if ($title === '') {
            $title = $subject !== '' ? $subject : 'Demanda recebida por e-mail';
        }

        if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
            $state = '';
        }

        // Determinar status baseado em completude dos dados
        // Critério: Se tiver especialidade, pode ir para captação (mesmo sem cidade/estado completos)
        // Apenas vai para tratamento_manual se faltar especialidade E (cidade OU estado)
        $hasSpecialty = ($specialty !== '');
        $hasLocation = ($city !== '' && $state !== '');
        
        $status = 'aguardando_captacao';
        
        // Só marca como tratamento_manual se faltar especialidade OU se não tiver nenhuma localização
        if (!$hasSpecialty || (!$hasLocation && $city === '' && $state === '')) {
            $status = 'tratamento_manual';
            $needsManual = true;
        } else {
            $needsManual = false;
        }
        
        // Se urgente e tiver especialidade, sempre priorizar para captação
        if ($urgency === 'urgente' && $hasSpecialty) {
            $status = 'aguardando_captacao';
            $needsManual = false;
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
                'urg' => $urgency !== '' ? $urgency : null,
            ]);
            $demandId = (int)$db->lastInsertId();

            $note = 'criação automática via e-mail';
            if ($needsManual) {
                $missing = [];
                if ($specialty === '') {
                    $missing[] = 'especialidade';
                }
                if ($city === '' && $state === '') {
                    $missing[] = 'localização completa';
                }
                if (count($missing) > 0) {
                    $note .= ' (tratamento_manual: faltando ' . implode(', ', $missing) . ')';
                }
            } else {
                // Adicionar nota se tiver dados parciais
                $partial = [];
                if ($city === '') {
                    $partial[] = 'cidade';
                }
                if ($state === '') {
                    $partial[] = 'UF';
                }
                if (count($partial) > 0) {
                    $note .= ' (dados parciais: faltando ' . implode(', ', $partial) . ')';
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
