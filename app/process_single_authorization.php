<?php

declare(strict_types=1);

/**
 * Processa uma única autorização específica
 * Chamado quando uma resposta de e-mail é detectada
 */
function process_single_authorization(int $authId, int $emailId): array
{
    $db = db();
    
    try {
        error_log("[PROCESS_SINGLE_AUTH] Processando autorização #$authId com e-mail #$emailId");
        
        // Buscar dados da autorização
        $stmt = $db->prepare(
            "SELECT ar.*, d.origin_email, d.title as demand_title
             FROM authorization_requests ar
             INNER JOIN demands d ON d.id = ar.demand_id
             WHERE ar.id = :id
             AND ar.status = 'aguardando_autorizacao'"
        );
        $stmt->execute(['id' => $authId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            error_log("[PROCESS_SINGLE_AUTH] Autorização #$authId não encontrada ou não está aguardando");
            return ['success' => false, 'error' => 'Autorização não encontrada ou já processada'];
        }
        
        $demandId = (int)$request['demand_id'];
        $patientId = (int)$request['patient_id'];
        $professionalUserId = (int)$request['professional_user_id'];
        $proposalValue = (float)$request['proposal_value'];
        $agreedValue = (float)$request['agreed_value'];
        $totalSessions = (int)$request['total_sessions'];
        $sessionsPerWeek = (int)$request['sessions_per_week'];
        
        error_log("[PROCESS_SINGLE_AUTH] === DADOS DA AUTORIZAÇÃO ===");
        error_log("[PROCESS_SINGLE_AUTH] Demand ID: $demandId");
        error_log("[PROCESS_SINGLE_AUTH] Patient ID: $patientId (CRÍTICO)");
        error_log("[PROCESS_SINGLE_AUTH] Professional User ID: $professionalUserId");
        error_log("[PROCESS_SINGLE_AUTH] Proposal Value: $proposalValue");
        error_log("[PROCESS_SINGLE_AUTH] Agreed Value: $agreedValue");
        error_log("[PROCESS_SINGLE_AUTH] Total Sessions: $totalSessions");
        
        if ($patientId <= 0) {
            error_log("[PROCESS_SINGLE_AUTH] ❌❌❌ ERRO CRÍTICO: Patient ID inválido na autorização!");
            error_log("[PROCESS_SINGLE_AUTH] Valor do patient_id: $patientId");
            error_log("[PROCESS_SINGLE_AUTH] A coluna patient_id pode não existir na tabela ou não foi salva corretamente");
            return ['success' => false, 'error' => 'Patient ID inválido na autorização. Verifique se a migration foi executada.'];
        }
        
        // Buscar e-mail
        $emailStmt = $db->prepare(
            "SELECT id, subject, body_text, body_html, from_address, received_at
             FROM inbound_emails
             WHERE id = :id"
        );
        $emailStmt->execute(['id' => $emailId]);
        $email = $emailStmt->fetch();
        
        if (!$email) {
            error_log("[PROCESS_SINGLE_AUTH] E-mail #$emailId não encontrado");
            return ['success' => false, 'error' => 'E-mail não encontrado'];
        }
        
        $bodyText = (string)($email['body_text'] ?? '');
        $bodyHtml = (string)($email['body_html'] ?? '');
        $receivedAt = (string)($email['received_at'] ?? '');
        
        $content = trim($bodyText);
        if ($content === '' && $bodyHtml !== '') {
            $content = trim(strip_tags($bodyHtml));
        }
        
        if ($content === '') {
            error_log("[PROCESS_SINGLE_AUTH] E-mail sem conteúdo");
            return ['success' => false, 'error' => 'E-mail sem conteúdo'];
        }
        
        error_log("[PROCESS_SINGLE_AUTH] Analisando resposta com IA...");
        
        // Analisar com IA
        $api = new OpenAiApi();
        
        $systemPrompt = "Você é um assistente que analisa respostas de e-mail de operadoras de saúde sobre propostas de atendimento domiciliar.\n\n"
            . "Analise a resposta e determine se é uma APROVAÇÃO ou NEGAÇÃO da proposta.\n\n"
            . "Retorne um JSON válido no formato:\n"
            . "{\"decision\":\"approved\"|\"denied\",\"confidence\":0.0-1.0,\"reason\":string}\n\n"
            . "Campos:\n"
            . "- decision: 'approved' se aprovado, 'denied' se negado\n"
            . "- confidence: nível de confiança (0.0 a 1.0)\n"
            . "- reason: motivo extraído do e-mail\n\n"
            . "Regras:\n"
            . "- Palavras como 'aprovado', 'autorizado', 'ok', 'sim' indicam aprovação\n"
            . "- Palavras como 'negado', 'recusado', 'não autorizado', 'não' indicam negação\n"
            . "- Se não tiver certeza, use confidence < 0.7\n"
            . "- Responda SOMENTE com JSON válido";
        
        $userPrompt = "Analise esta resposta da operadora:\n\n" . $content;
        
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
            $msg = 'Erro na API OpenAI';
            error_log("[PROCESS_SINGLE_AUTH] Erro OpenAI: HTTP $statusCode");
            return ['success' => false, 'error' => $msg];
        }
        
        $json = $res['json'] ?? null;
        if (!is_array($json)) {
            error_log("[PROCESS_SINGLE_AUTH] Resposta OpenAI não é JSON válido");
            return ['success' => false, 'error' => 'Resposta OpenAI inválida'];
        }
        
        $raw = json_encode($json);
        $choices = $json['choices'] ?? [];
        if (!is_array($choices) || count($choices) === 0) {
            error_log("[PROCESS_SINGLE_AUTH] OpenAI retornou vazio");
            return ['success' => false, 'error' => 'OpenAI retornou vazio'];
        }
        
        $messageContent = (string)($choices[0]['message']['content'] ?? '');
        $parsed = @json_decode($messageContent, true);
        
        if (!is_array($parsed)) {
            error_log("[PROCESS_SINGLE_AUTH] Resposta não é JSON válido");
            return ['success' => false, 'error' => 'Resposta não é JSON válido'];
        }
        
        $decision = strtolower(trim((string)($parsed['decision'] ?? '')));
        $confidence = (float)($parsed['confidence'] ?? 0.0);
        $reason = trim((string)($parsed['reason'] ?? 'Sem motivo especificado'));
        
        error_log("[PROCESS_SINGLE_AUTH] Decisão: $decision (confiança: $confidence)");
        error_log("[PROCESS_SINGLE_AUTH] Motivo: $reason");
        
        if ($confidence < 0.7) {
            error_log("[PROCESS_SINGLE_AUTH] Confiança baixa ($confidence < 0.7), ignorando");
            return ['success' => false, 'error' => 'Confiança baixa'];
        }
        
        $db->beginTransaction();
        
        if ($decision === 'approved') {
            error_log("[PROCESS_SINGLE_AUTH] ✅ PROCESSANDO APROVAÇÃO");
            
            // Buscar dados da demanda
            $demandStmt = $db->prepare("SELECT * FROM demands WHERE id = :id");
            $demandStmt->execute(['id' => $demandId]);
            $demand = $demandStmt->fetch();
            
            if (!$demand) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Demanda não encontrada'];
            }
            
            // Verificar se paciente existe (patient_id já vem da autorização)
            error_log("[PROCESS_SINGLE_AUTH] === VALIDAÇÃO CRÍTICA: VERIFICANDO PACIENTE NO BANCO ===");
            error_log("[PROCESS_SINGLE_AUTH] Buscando paciente ID: $patientId");
            error_log("[PROCESS_SINGLE_AUTH] Query: SELECT id, full_name FROM patients WHERE id = $patientId AND deleted_at IS NULL");
            
            $verifyPatient = $db->prepare("SELECT id, full_name FROM patients WHERE id = :id AND deleted_at IS NULL");
            $verifyPatient->execute(['id' => $patientId]);
            $patientData = $verifyPatient->fetch();
            
            if (!$patientData) {
                $db->rollBack();
                error_log("[PROCESS_SINGLE_AUTH] ❌❌❌ ERRO CRÍTICO: Paciente #$patientId NÃO EXISTE no banco de dados");
                error_log("[PROCESS_SINGLE_AUTH] Possíveis causas:");
                error_log("[PROCESS_SINGLE_AUTH]   1. Paciente foi deletado (deleted_at IS NOT NULL)");
                error_log("[PROCESS_SINGLE_AUTH]   2. ID do paciente está incorreto");
                error_log("[PROCESS_SINGLE_AUTH]   3. Paciente nunca foi cadastrado");
                return ['success' => false, 'error' => 'Paciente não encontrado no sistema'];
            }
            
            error_log("[PROCESS_SINGLE_AUTH] ✓✓✓ Paciente VALIDADO com sucesso!");
            error_log("[PROCESS_SINGLE_AUTH] Nome: " . $patientData['full_name']);
            error_log("[PROCESS_SINGLE_AUTH] ID: " . $patientData['id']);
            
            // Criar atendimento
            $startDate = (string)$request['start_date'];
            $startTime = (string)$request['start_time'];
            $endTime = (string)$request['end_time'];
            $frequency = (string)$request['frequency'];
            $frequencyDetails = (string)($request['frequency_details'] ?? '');
            $durationWeeks = (int)$request['duration_weeks'];
            
            $insAssignment = $db->prepare(
                "INSERT INTO patient_assignments 
                (patient_id, professional_user_id, specialty, service_description, 
                 start_date, start_time, end_time, frequency, frequency_details, 
                 sessions_per_week, total_sessions, duration_weeks, 
                 agreed_value_per_session, proposal_value_per_session, status, created_at)
                VALUES (:pid, :puid, :spec, :desc, :sd, :st, :et, :freq, :fd, :spw, :ts, :dw, :av, :pv, 'active', NOW())"
            );
            $insAssignment->execute([
                'pid' => $patientId,
                'puid' => $professionalUserId,
                'spec' => (string)($demand['specialty'] ?? ''),
                'desc' => (string)($demand['title'] ?? ''),
                'sd' => $startDate,
                'st' => $startTime,
                'et' => $endTime,
                'freq' => $frequency,
                'fd' => $frequencyDetails !== '' ? $frequencyDetails : null,
                'spw' => $sessionsPerWeek,
                'ts' => $totalSessions,
                'dw' => $durationWeeks,
                'av' => $agreedValue,
                'pv' => $proposalValue,
            ]);
            $assignmentId = (int)$db->lastInsertId();
            error_log("[PROCESS_SINGLE_AUTH] ✓ Atendimento criado - ID: $assignmentId");
            
            // Criar lançamentos financeiros
            $totalReceita = $proposalValue * $totalSessions;
            $totalDespesa = $agreedValue * $totalSessions;
            
            $insReceita = $db->prepare(
                "INSERT INTO financial_entries 
                (entry_type, amount, description, entry_date, status, patient_assignment_id, created_at)
                VALUES ('receita', :amt, :desc, :dt, 'pendente', :aid, NOW())"
            );
            $insReceita->execute([
                'amt' => $totalReceita,
                'desc' => "Receita - Atendimento #$assignmentId - $totalSessions sessões",
                'dt' => $startDate,
                'aid' => $assignmentId,
            ]);
            error_log("[PROCESS_SINGLE_AUTH] ✓ Receita lançada: R$ $totalReceita");
            
            $insDespesa = $db->prepare(
                "INSERT INTO financial_entries 
                (entry_type, amount, description, entry_date, status, patient_assignment_id, professional_user_id, created_at)
                VALUES ('despesa', :amt, :desc, :dt, 'pendente', :aid, :puid, NOW())"
            );
            $insDespesa->execute([
                'amt' => $totalDespesa,
                'desc' => "Despesa - Atendimento #$assignmentId - $totalSessions sessões",
                'dt' => $startDate,
                'aid' => $assignmentId,
                'puid' => $professionalUserId,
            ]);
            error_log("[PROCESS_SINGLE_AUTH] ✓ Despesa lançada: R$ $totalDespesa");
            
            // Atualizar autorização
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
                'received_at' => $receivedAt,
                'analysis' => $raw,
                'email_id' => $emailId,
                'assignment_id' => $assignmentId,
                'id' => $authId
            ]);
            
            // Atualizar demanda
            $updateDemandStmt = $db->prepare(
                "UPDATE demands SET status = 'pre_admissao' WHERE id = :id"
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
            
            error_log("[PROCESS_SINGLE_AUTH] ✅ APROVAÇÃO PROCESSADA COM SUCESSO!");
            error_log("[PROCESS_SINGLE_AUTH] Atendimento #$assignmentId criado");
            error_log("[PROCESS_SINGLE_AUTH] Paciente #$patientId vinculado");
            
            return [
                'success' => true,
                'decision' => 'approved',
                'assignment_id' => $assignmentId,
                'patient_id' => $patientId
            ];
            
        } else {
            error_log("[PROCESS_SINGLE_AUTH] ❌ PROCESSANDO NEGAÇÃO");
            
            // Atualizar autorização
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
                'received_at' => $receivedAt,
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
            
            error_log("[PROCESS_SINGLE_AUTH] ❌ NEGAÇÃO PROCESSADA COM SUCESSO!");
            error_log("[PROCESS_SINGLE_AUTH] Motivo: $reason");
            
            return [
                'success' => true,
                'decision' => 'denied',
                'reason' => $reason
            ];
        }
        
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("[PROCESS_SINGLE_AUTH] ERRO: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
