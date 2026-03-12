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
    
    if ($debug) {
        echo "\n=== Processando Autorização #$authId ===\n";
        echo "E-mail operadora: $operatorEmail\n";
        echo "E-mail origem: $originEmail\n";
    }
    
    try {
        // Buscar e-mails recebidos do operador após o envio da proposta
        $sentAt = (string)$request['sent_at'];
        
        // Buscar por thread_id se disponível, senão por assunto e remetente
        $emailStmt = $db->prepare(
            "SELECT id, subject, body_text, body_html, from_address, from_email, received_at, thread_id
             FROM inbound_emails
             WHERE (from_email = :op_email OR from_address LIKE :op_email_like)
             AND received_at >= :sent_at
             AND status IN ('received', 'ai_processed', 'processed')
             ORDER BY received_at DESC
             LIMIT 5"
        );
        $emailStmt->execute([
            'op_email' => $operatorEmail,
            'op_email_like' => '%' . $operatorEmail . '%',
            'sent_at' => $sentAt
        ]);
        $responseEmails = $emailStmt->fetchAll();
        
        if (count($responseEmails) === 0) {
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
            
            $content = trim($bodyText);
            if ($content === '' && $bodyHtml !== '') {
                $content = trim(strip_tags($bodyHtml));
            }
            
            if ($content === '') {
                continue;
            }
            
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
            if ($statusCode < 200 || $statusCode >= 300) {
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
            
            if ($raw === '') {
                if ($debug) {
                    echo "OpenAI retornou vazio\n";
                }
                continue;
            }
            
            $parsed = json_decode($raw, true);
            if (!is_array($parsed)) {
                if ($debug) {
                    echo "Resposta não é JSON válido\n";
                }
                continue;
            }
            
            $decision = (string)($parsed['decision'] ?? '');
            $confidence = (float)($parsed['confidence'] ?? 0);
            $reason = (string)($parsed['reason'] ?? '');
            
            if ($debug) {
                echo "Decisão: $decision (confiança: $confidence)\n";
                echo "Motivo: $reason\n";
            }
            
            // Só processar se confiança >= 0.7
            if ($confidence < 0.7) {
                if ($debug) {
                    echo "Confiança baixa, ignorando\n";
                }
                continue;
            }
            
            $foundResponse = true;
            
            $db->beginTransaction();
            try {
                if ($decision === 'approved') {
                    // APROVADO - Criar paciente, atendimento e financeiro
                    
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
                        // Criar paciente básico (será completado depois)
                        $createPatientStmt = $db->prepare(
                            "INSERT INTO patients (full_name, email, phone, status) 
                             VALUES (:name, :email, :phone, 'active')"
                        );
                        $createPatientStmt->execute([
                            'name' => 'Paciente - Demanda #' . $demandId,
                            'email' => $originEmail,
                            'phone' => ''
                        ]);
                        $patientId = (int)$db->lastInsertId();
                    }
                    
                    // Criar atendimento (patient_assignment)
                    $professionalUserId = (int)$request['professional_user_id'];
                    $specialty = (string)$request['demand_specialty'];
                    $agreedValue = (float)$request['agreed_value'];
                    $proposalValue = (float)$request['proposal_value'];
                    $totalSessions = (int)$request['total_sessions'];
                    
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
                    
                    // Criar lançamentos financeiros
                    $totalRevenue = $proposalValue * $totalSessions;
                    $totalCost = $agreedValue * $totalSessions;
                    
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
                    
                    $db->commit();
                    $approved++;
                    
                    if ($debug) {
                        echo "✅ APROVADO - Atendimento #$assignmentId criado\n";
                    }
                    
                } else {
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
                    
                    // Atualizar demanda
                    $updateDemandStmt = $db->prepare(
                        "UPDATE demands SET status = 'autorizacao_negada' WHERE id = :id"
                    );
                    $updateDemandStmt->execute(['id' => $demandId]);
                    
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
                    
                    $db->commit();
                    $denied++;
                    
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
