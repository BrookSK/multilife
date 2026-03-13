<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$debug = isset($_GET['debug']) && ((string)$_GET['debug'] === '1' || strtolower((string)$_GET['debug']) === 'true');
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit <= 0 || $limit > 100) {
    $limit = 50;
}

$db = db();

// Buscar solicitações aguardando resposta que já passaram do prazo de 5 minutos
$stmt = $db->prepare(
    "SELECT ar.*, d.origin_email, d.title as demand_title
     FROM authorization_requests ar
     INNER JOIN demands d ON d.id = ar.demand_id
     WHERE ar.status = 'aguardando_autorizacao'
     AND ar.sent_at IS NOT NULL
     AND ar.response_deadline IS NOT NULL
     AND ar.response_deadline <= NOW()
     AND ar.response_received_at IS NULL
     ORDER BY ar.sent_at ASC
     LIMIT :limit"
);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$pendingRequests = $stmt->fetchAll();

if (count($pendingRequests) === 0) {
    echo "OK: Nenhuma solicitação aguardando resposta após prazo\n";
    exit;
}

$api = new OpenAiApi();
$processed = 0;
$approved = 0;
$denied = 0;
$errors = 0;

foreach ($pendingRequests as $request) {
    $authId = (int)$request['id'];
    $demandId = (int)$request['demand_id'];
    $originEmail = (string)$request['origin_email'];
    $operatorEmail = (string)$request['operator_email'];
    
    error_log("=== [AUTHORIZATION_RESPONSE] Processando Autorização #$authId ===");
    error_log("[AUTHORIZATION_RESPONSE] Demanda ID: $demandId");
    error_log("[AUTHORIZATION_RESPONSE] E-mail operadora: $operatorEmail");
    error_log("[AUTHORIZATION_RESPONSE] E-mail origem: $originEmail");
    
    if ($debug) {
        echo "\n=== Processando Autorização #$authId ===\n";
        echo "E-mail operadora: $operatorEmail\n";
        echo "E-mail origem: $originEmail\n";
    }
    
    try {
        // Buscar e-mails recebidos do operador após o envio da proposta
        $sentAt = (string)$request['sent_at'];
        error_log("[AUTHORIZATION_RESPONSE] Proposta enviada em: $sentAt");
        
        // Buscar e-mails não vinculados a outras autorizações
        $emailStmt = $db->prepare(
            "SELECT ie.id, ie.subject, ie.body_text, ie.body_html, ie.from_address, ie.from_email, ie.received_at, ie.thread_id
             FROM inbound_emails ie
             LEFT JOIN authorization_requests ar_check ON ar_check.inbound_email_id = ie.id
             WHERE (ie.from_email = :op_email OR ie.from_address LIKE :op_email_like)
             AND ie.received_at >= :sent_at
             AND ie.status IN ('received', 'ai_processed', 'processed')
             AND ar_check.id IS NULL
             ORDER BY ie.received_at ASC
             LIMIT 5"
        );
        $emailStmt->execute([
            'op_email' => $operatorEmail,
            'op_email_like' => '%' . $operatorEmail . '%',
            'sent_at' => $sentAt
        ]);
        $responseEmails = $emailStmt->fetchAll();
        
        error_log("[AUTHORIZATION_RESPONSE] Buscando e-mails recebidos após $sentAt");
        error_log("[AUTHORIZATION_RESPONSE] Operadora: $operatorEmail");
        error_log("[AUTHORIZATION_RESPONSE] E-mails não vinculados encontrados: " . count($responseEmails));
        
        if (count($responseEmails) > 0) {
            foreach ($responseEmails as $idx => $em) {
                error_log("[AUTHORIZATION_RESPONSE]   E-mail #" . $em['id'] . " - " . $em['subject'] . " (" . $em['received_at'] . ")");
            }
        }
        
        if (count($responseEmails) === 0) {
            error_log("[AUTHORIZATION_RESPONSE] ⚠️ Nenhuma resposta encontrada ainda para autorização #$authId");
            if ($debug) {
                echo "Nenhuma resposta encontrada ainda\n";
            }
            continue;
        }
        
        // Processar cada e-mail encontrado
        $foundResponse = false;
        
        foreach ($responseEmails as $email) {
            $emailId = (int)$email['id'];
            $subject = (string)($email['subject'] ?? '');
            $bodyText = (string)($email['body_text'] ?? '');
            $bodyHtml = (string)($email['body_html'] ?? '');
            $receivedAt = (string)($email['received_at'] ?? '');
            
            error_log("[AUTHORIZATION_RESPONSE] Analisando e-mail #$emailId");
            error_log("[AUTHORIZATION_RESPONSE] Assunto: $subject");
            error_log("[AUTHORIZATION_RESPONSE] Recebido em: $receivedAt");
            error_log("[AUTHORIZATION_RESPONSE] Tamanho body_text: " . strlen($bodyText) . " bytes");
            error_log("[AUTHORIZATION_RESPONSE] Tamanho body_html: " . strlen($bodyHtml) . " bytes");
            
            $content = trim($bodyText);
            if ($content === '' && $bodyHtml !== '') {
                $content = trim(strip_tags($bodyHtml));
            }
            
            if ($content === '') {
                error_log("[AUTHORIZATION_RESPONSE] ⚠️ E-mail #$emailId sem conteúdo, pulando");
                continue;
            }
            
            error_log("[AUTHORIZATION_RESPONSE] Conteúdo extraído: " . strlen($content) . " caracteres");
            
            if ($debug) {
                echo "\nAnalisando e-mail #$emailId\n";
                echo "Assunto: $subject\n";
            }
            
            // Usar IA para analisar se é aprovação ou negação
            $systemPrompt = "Você é um assistente especializado em analisar respostas de e-mails sobre propostas de atendimento médico domiciliar.\n\n"
                . "Analise o e-mail e determine se é uma APROVAÇÃO ou NEGAÇÃO da proposta.\n\n"
                . "Retorne um JSON válido no formato:\n"
                . "{\"decision\":string,\"confidence\":number,\"reason\":string}\n\n"
                . "Campos:\n"
                . "- decision: 'approved' (aprovado) ou 'denied' (negado)\n"
                . "- confidence: número de 0 a 1 indicando confiança na análise\n"
                . "- reason: motivo extraído do e-mail (se negado) ou confirmação (se aprovado)\n\n"
                . "Regras:\n"
                . "- Palavras como 'aprovado', 'autorizado', 'ok', 'confirmo', 'aceito' indicam APROVAÇÃO\n"
                . "- Palavras como 'negado', 'recusado', 'não autorizado', 'rejeitado' indicam NEGAÇÃO\n"
                . "- Se houver solicitação de ajuste de valor, considere NEGAÇÃO\n"
                . "- Seja preciso e objetivo\n"
                . "- Responda SOMENTE com JSON válido";
            
            $userPrompt = "ASSUNTO: $subject\n\nCORPO DO E-MAIL:\n$content";
            
            error_log("[AUTHORIZATION_RESPONSE] 🤖 Enviando para análise de IA...");
            error_log("[AUTHORIZATION_RESPONSE] Preview do conteúdo: " . mb_substr($content, 0, 200) . "...");
            
            $res = $api->chatCompletions(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                null,
                [
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'],
                ]
            );
            
            $statusCode = (int)($res['status'] ?? 0);
            error_log("[AUTHORIZATION_RESPONSE] Resposta da IA - HTTP Status: $statusCode");
            
            if ($statusCode < 200 || $statusCode >= 300) {
                error_log("[AUTHORIZATION_RESPONSE] ❌ Erro na API OpenAI: HTTP $statusCode");
                if ($debug) {
                    echo "Erro na API OpenAI: HTTP $statusCode\n";
                }
                continue;
            }
            
            $json = $res['json'] ?? null;
            $raw = '';
            if (is_array($json)) {
                $raw = (string)($json['choices'][0]['message']['content'] ?? '');
            }
            $raw = trim($raw);
            
            error_log("[AUTHORIZATION_RESPONSE] Resposta da IA (raw): $raw");
            
            if ($raw === '') {
                error_log("[AUTHORIZATION_RESPONSE] ❌ OpenAI retornou vazio");
                if ($debug) {
                    echo "OpenAI retornou vazio\n";
                }
                continue;
            }
            
            $parsed = json_decode($raw, true);
            if (!is_array($parsed)) {
                error_log("[AUTHORIZATION_RESPONSE] ❌ Resposta não é JSON válido");
                if ($debug) {
                    echo "Resposta não é JSON válido\n";
                }
                continue;
            }
            
            $decision = (string)($parsed['decision'] ?? '');
            $confidence = (float)($parsed['confidence'] ?? 0);
            $reason = (string)($parsed['reason'] ?? '');
            
            error_log("[AUTHORIZATION_RESPONSE] 📊 DECISÃO DA IA:");
            error_log("[AUTHORIZATION_RESPONSE]   - Decisão: $decision");
            error_log("[AUTHORIZATION_RESPONSE]   - Confiança: $confidence");
            error_log("[AUTHORIZATION_RESPONSE]   - Motivo: $reason");
            
            if ($debug) {
                echo "Decisão: $decision (confiança: $confidence)\n";
                echo "Motivo: $reason\n";
            }
            
            // Só processar se confiança >= 0.7
            if ($confidence < 0.7) {
                error_log("[AUTHORIZATION_RESPONSE] ⚠️ Confiança baixa ($confidence < 0.7), ignorando");
                if ($debug) {
                    echo "Confiança baixa, ignorando\n";
                }
                continue;
            }
            
            $foundResponse = true;
            
            $db->beginTransaction();
            try {
                if ($decision === 'approved') {
                    error_log("[AUTHORIZATION_RESPONSE] ✅ PROCESSANDO APROVAÇÃO");
                    // APROVADO - Criar paciente, atendimento e financeiro
                    
                    error_log("[AUTHORIZATION_RESPONSE] Buscando ou criando paciente...");
                    // Buscar ou criar paciente
                    $patientStmt = $db->prepare(
                        "SELECT id FROM patients 
                         WHERE deleted_at IS NULL 
                         ORDER BY created_at DESC 
                         LIMIT 1"
                    );
                    $patientStmt->execute();
                    $patientRow = $patientStmt->fetch();
                    $patientId = $patientRow ? (int)$patientRow['id'] : 0;
                    
                    if ($patientId === 0) {
                        error_log("[AUTHORIZATION_RESPONSE] Criando novo paciente...");
                        // Criar paciente básico (será completado depois)
                        $createPatientStmt = $db->prepare(
                            "INSERT INTO patients (full_name, email, phone_primary, status) 
                             VALUES (:name, :email, :phone, 'active')"
                        );
                        $createPatientStmt->execute([
                            'name' => 'Paciente - Demanda #' . $demandId,
                            'email' => $originEmail,
                            'phone' => ''
                        ]);
                        $patientId = (int)$db->lastInsertId();
                        error_log("[AUTHORIZATION_RESPONSE] ✓ Paciente criado - ID: $patientId");
                    } else {
                        error_log("[AUTHORIZATION_RESPONSE] ✓ Paciente existente - ID: $patientId");
                    }
                    
                    // Criar atendimento (patient_assignment)
                    $professionalUserId = (int)$request['professional_user_id'];
                    $specialty = (string)$request['demand_specialty'];
                    $agreedValue = (float)$request['agreed_value'];
                    $proposalValue = (float)$request['proposal_value'];
                    $totalSessions = (int)$request['total_sessions'];
                    
                    error_log("[AUTHORIZATION_RESPONSE] Criando atendimento...");
                    error_log("[AUTHORIZATION_RESPONSE]   - Profissional: $professionalUserId");
                    error_log("[AUTHORIZATION_RESPONSE]   - Especialidade: $specialty");
                    error_log("[AUTHORIZATION_RESPONSE]   - Valor acordado: R$ $agreedValue");
                    error_log("[AUTHORIZATION_RESPONSE]   - Valor proposta: R$ $proposalValue");
                    error_log("[AUTHORIZATION_RESPONSE]   - Total sessões: $totalSessions");
                    
                    $assignmentStmt = $db->prepare(
                        "INSERT INTO patient_assignments 
                        (patient_id, professional_user_id, specialty, demand_id, status, 
                         value_per_session, total_sessions, notes) 
                        VALUES 
                        (:patient_id, :prof_id, :specialty, :demand_id, 'admitted',
                         :value, :sessions, :notes)"
                    );
                    $assignmentStmt->execute([
                        'patient_id' => $patientId,
                        'prof_id' => $professionalUserId,
                        'specialty' => $specialty,
                        'demand_id' => $demandId,
                        'value' => $agreedValue,
                        'sessions' => $totalSessions,
                        'notes' => 'Criado automaticamente após aprovação da proposta #' . $authId
                    ]);
                    $assignmentId = (int)$db->lastInsertId();
                    error_log("[AUTHORIZATION_RESPONSE] ✓ Atendimento criado - ID: $assignmentId");
                    
                    // Criar lançamentos financeiros
                    $totalRevenue = $proposalValue * $totalSessions;
                    $totalCost = $agreedValue * $totalSessions;
                    
                    error_log("[AUTHORIZATION_RESPONSE] Criando lançamentos financeiros...");
                    error_log("[AUTHORIZATION_RESPONSE]   - Receita total: R$ $totalRevenue");
                    error_log("[AUTHORIZATION_RESPONSE]   - Despesa total: R$ $totalCost");
                    
                    // Receita
                    $revenueStmt = $db->prepare(
                        "INSERT INTO financial_entries 
                        (entry_type, category, amount, description, reference_type, reference_id, status, due_date) 
                        VALUES 
                        ('income', 'Receita de Atendimento', :amount, :desc, 'patient_assignment', :ref_id, 'pending', :due_date)"
                    );
                    $revenueStmt->execute([
                        'amount' => $totalRevenue,
                        'desc' => "Receita - Atendimento #$assignmentId - $specialty",
                        'ref_id' => $assignmentId,
                        'due_date' => date('Y-m-d', strtotime('+30 days'))
                    ]);
                    error_log("[AUTHORIZATION_RESPONSE] ✓ Receita lançada");
                    
                    // Despesa (pagamento ao profissional)
                    $expenseStmt = $db->prepare(
                        "INSERT INTO financial_entries 
                        (entry_type, category, amount, description, reference_type, reference_id, status, due_date) 
                        VALUES 
                        ('expense', 'Pagamento Profissional', :amount, :desc, 'patient_assignment', :ref_id, 'pending', :due_date)"
                    );
                    $expenseStmt->execute([
                        'amount' => $totalCost,
                        'desc' => "Pagamento Profissional - Atendimento #$assignmentId",
                        'ref_id' => $assignmentId,
                        'due_date' => date('Y-m-d', strtotime('+30 days'))
                    ]);
                    error_log("[AUTHORIZATION_RESPONSE] ✓ Despesa lançada");
                    
                    // Atualizar authorization_request
                    $updateAuthStmt = $db->prepare(
                        "UPDATE authorization_requests 
                         SET status = 'autorizacao_aprovada',
                             response_received_at = :received_at,
                             ai_analysis = :analysis,
                             inbound_email_id = :email_id,
                             patient_assignment_id = :assignment_id
                         WHERE id = :id"
                    );
                    $updateAuthStmt->execute([
                        'received_at' => $email['received_at'],
                        'analysis' => $raw,
                        'email_id' => $emailId,
                        'assignment_id' => $assignmentId,
                        'id' => $authId
                    ]);
                    
                    // Atualizar demanda para autorizacao_aprovada
                    $updateDemandStmt = $db->prepare(
                        "UPDATE demands SET status = 'autorizacao_aprovada' WHERE id = :id"
                    );
                    $updateDemandStmt->execute(['id' => $demandId]);
                    
                    // Registrar histórico
                    $historyStmt = $db->prepare(
                        "INSERT INTO authorization_request_history 
                        (authorization_request_id, action, notes) 
                        VALUES (:auth_id, 'approved', :notes)"
                    );
                    $historyStmt->execute([
                        'auth_id' => $authId,
                        'notes' => "Aprovado automaticamente pela IA. Motivo: $reason"
                    ]);
                    
                    error_log("[AUTHORIZATION_RESPONSE] Atualizando status da autorização e demanda...");
                    
                    $db->commit();
                    $approved++;
                    
                    error_log("[AUTHORIZATION_RESPONSE] ✅ APROVAÇÃO PROCESSADA COM SUCESSO!");
                    error_log("[AUTHORIZATION_RESPONSE] Atendimento #$assignmentId criado");
                    error_log("[AUTHORIZATION_RESPONSE] Paciente #$patientId vinculado");
                    error_log("[AUTHORIZATION_RESPONSE] Lançamentos financeiros criados");
                    
                    if ($debug) {
                        echo "✅ APROVADO - Atendimento #$assignmentId criado\n";
                    }
                    
                } else {
                    error_log("[AUTHORIZATION_RESPONSE] ❌ PROCESSANDO NEGAÇÃO");
                    // NEGADO
                    
                    $updateAuthStmt = $db->prepare(
                        "UPDATE authorization_requests 
                         SET status = 'autorizacao_negada',
                             response_received_at = :received_at,
                             ai_analysis = :analysis,
                             denial_reason = :reason,
                             inbound_email_id = :email_id
                         WHERE id = :id"
                    );
                    $updateAuthStmt->execute([
                        'received_at' => $email['received_at'],
                        'analysis' => $raw,
                        'reason' => $reason,
                        'email_id' => $emailId,
                        'id' => $authId
                    ]);
                    error_log("[AUTHORIZATION_RESPONSE] ✓ Status da autorização atualizado para 'autorizacao_negada'");
                    
                    // Atualizar demanda
                    $updateDemandStmt = $db->prepare(
                        "UPDATE demands SET status = 'autorizacao_negada' WHERE id = :id"
                    );
                    $updateDemandStmt->execute(['id' => $demandId]);
                    error_log("[AUTHORIZATION_RESPONSE] ✓ Status da demanda atualizado");
                    
                    // Registrar histórico
                    $historyStmt = $db->prepare(
                        "INSERT INTO authorization_request_history 
                        (authorization_request_id, action, notes) 
                        VALUES (:auth_id, 'denied', :notes)"
                    );
                    $historyStmt->execute([
                        'auth_id' => $authId,
                        'notes' => "Negado automaticamente pela IA. Motivo: $reason"
                    ]);
                    error_log("[AUTHORIZATION_RESPONSE] ✓ Histórico registrado");
                    
                    $db->commit();
                    $denied++;
                    
                    error_log("[AUTHORIZATION_RESPONSE] ❌ NEGAÇÃO PROCESSADA COM SUCESSO!");
                    error_log("[AUTHORIZATION_RESPONSE] Motivo: $reason");
                    
                    if ($debug) {
                        echo "❌ NEGADO - Motivo: $reason\n";
                    }
                }
                
                $processed++;
                break; // Sair do loop de e-mails após processar
                
            } catch (Exception $e) {
                $db->rollBack();
                $errors++;
                if ($debug) {
                    echo "Erro ao processar: " . $e->getMessage() . "\n";
                }
            }
        }
        
        if (!$foundResponse && $debug) {
            echo "Nenhuma resposta conclusiva encontrada\n";
        }
        
    } catch (Exception $e) {
        $errors++;
        if ($debug) {
            echo "Erro geral: " . $e->getMessage() . "\n";
        }
    }
}

echo "OK: processed=$processed approved=$approved denied=$denied errors=$errors\n";
