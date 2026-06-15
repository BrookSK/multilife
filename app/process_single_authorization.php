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
        
        // Tratar patient_id com cuidado (pode vir NULL do banco)
        $patientIdRaw = $request['patient_id'] ?? null;
        $patientId = $patientIdRaw !== null ? (int)$patientIdRaw : 0;
        
        $professionalUserId = (int)$request['professional_user_id'];
        $proposalValue = (float)$request['proposal_value'];
        $agreedValue = (float)$request['agreed_value'];
        $totalSessions = (int)$request['total_sessions'];
        $sessionsPerWeek = (int)$request['sessions_per_week'];
        
        error_log("[PROCESS_SINGLE_AUTH] === DADOS DA AUTORIZAÇÃO #$authId ===");
        error_log("[PROCESS_SINGLE_AUTH] Demand ID: $demandId");
        error_log("[PROCESS_SINGLE_AUTH] Patient ID (raw): " . var_export($patientIdRaw, true));
        error_log("[PROCESS_SINGLE_AUTH] Patient ID (int): $patientId");
        error_log("[PROCESS_SINGLE_AUTH] Professional User ID: $professionalUserId");
        error_log("[PROCESS_SINGLE_AUTH] Proposal Value: $proposalValue");
        error_log("[PROCESS_SINGLE_AUTH] Agreed Value: $agreedValue");
        error_log("[PROCESS_SINGLE_AUTH] Total Sessions: $totalSessions");
        
        if ($patientId <= 0) {
            error_log("[PROCESS_SINGLE_AUTH] ❌❌❌ ERRO CRÍTICO: Patient ID inválido na autorização #$authId!");
            error_log("[PROCESS_SINGLE_AUTH] Valor raw: " . var_export($patientIdRaw, true));
            error_log("[PROCESS_SINGLE_AUTH] Valor convertido: $patientId");
            error_log("[PROCESS_SINGLE_AUTH] Esta autorização foi criada antes da implementação de patient_id ou houve erro no salvamento");
            error_log("[PROCESS_SINGLE_AUTH] SOLUÇÃO: Recrie a proposta ou cancele esta autorização");
            return ['success' => false, 'error' => 'Patient ID inválido na autorização #' . $authId . '. Esta autorização foi criada antes da implementação. Por favor, recrie a proposta.'];
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
        
        // Normalizar decisão para 'approved' ou 'denied'
        $normalizedDecision = $decision;
        if (in_array($decision, ['denied', 'rejected', 'negado', 'recusado', 'nao aprovado', 'não aprovado'], true)) {
            $normalizedDecision = 'denied';
            error_log("[PROCESS_SINGLE_AUTH] Decisão normalizada: '$decision' -> 'denied'");
        } elseif (in_array($decision, ['approved', 'aprovado', 'autorizado'], true)) {
            $normalizedDecision = 'approved';
            error_log("[PROCESS_SINGLE_AUTH] Decisão normalizada: '$decision' -> 'approved'");
        } else {
            error_log("[PROCESS_SINGLE_AUTH] ⚠️ Decisão não reconhecida: '$decision'");
            return ['success' => false, 'error' => "Decisão não reconhecida: $decision"];
        }
        
        if ($confidence < 0.7) {
            error_log("[PROCESS_SINGLE_AUTH] Confiança baixa ($confidence < 0.7), ignorando");
            return ['success' => false, 'error' => 'Confiança baixa'];
        }
        
        $db->beginTransaction();
        
        if ($normalizedDecision === 'approved') {
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
            
            $startDate = (string)$request['start_date'];
            $startTime = (string)$request['start_time'];
            $endTime = (string)$request['end_time'];
            $frequency = (string)$request['frequency'];
            $frequencyDetails = (string)($request['frequency_details'] ?? '');
            $durationWeeks = (int)$request['duration_weeks'];
            
            $insAssignment = $db->prepare(
                "INSERT INTO patient_assignments 
                (demand_id, patient_id, professional_user_id, assigned_by_user_id,
                 specialty, service_type, session_quantity, session_frequency, 
                 payment_value, agreed_value, authorized_value, notes, status, created_at)
                VALUES (:did, :pid, :puid, :abuid, :spec, :stype, :sq, :sfreq, :pv, :av, :authv, :notes, 'confirmed', NOW())"
            );
            
            // Valores por sessão
            $agreedPerSession = $totalSessions > 0 ? ($agreedValue / $totalSessions) : $agreedValue;
            $proposalPerSession = $totalSessions > 0 ? ($proposalValue / $totalSessions) : $proposalValue;
            
            $insAssignment->execute([
                'did' => $demandId,
                'pid' => $patientId,
                'puid' => $professionalUserId,
                'abuid' => 1, // Sistema automático
                'spec' => (string)($demand['specialty'] ?? ''),
                'stype' => (string)($demand['title'] ?? 'Atendimento'),
                'sq' => $totalSessions,
                'sfreq' => $frequency,
                'pv' => $agreedPerSession,
                'av' => $agreedPerSession,        // Valor acordado com profissional (por sessão)
                'authv' => $proposalPerSession,   // Valor proposto à operadora (por sessão) = RECEITA
                'notes' => "Autorização aprovada automaticamente. Proposta: R$ " . number_format($proposalValue, 2, ',', '.') . " | Acordado: R$ " . number_format($agreedValue, 2, ',', '.')
            ]);
            $assignmentId = (int)$db->lastInsertId();
            error_log("[PROCESS_SINGLE_AUTH] ✓ Atendimento criado - ID: $assignmentId");
            
            // Disparar evento WhatsApp attendance_assigned (operadora APROVOU)
            try {
                $profStmt = $db->prepare('SELECT name, phone FROM users WHERE id = :id');
                $profStmt->execute(['id' => $professionalUserId]);
                $profData = $profStmt->fetch();
                
                $patStmt = $db->prepare('SELECT full_name, whatsapp, phone_primary FROM patients WHERE id = :id');
                $patStmt->execute(['id' => $patientId]);
                $patData = $patStmt->fetch();
                
                $dispatcher = new WhatsAppEventDispatcher();
                $dispatcher->dispatch('attendance_assigned', [
                    'professional_id' => $professionalUserId,
                    'professional_name' => $profData['name'] ?? '',
                    'professional_phone' => preg_replace('/\D+/', '', (string)($profData['phone'] ?? '')),
                    'patient_id' => $patientId,
                    'patient_name' => $patData['full_name'] ?? '',
                    'patient_phone' => preg_replace('/\D+/', '', (string)($patData['whatsapp'] ?? $patData['phone_primary'] ?? '')),
                    'attendance_id' => (string)$assignmentId,
                    'attendance_date' => date('d/m/Y'),
                    'attendance_link' => 'https://multilife.onsolutionsbrasil.com.br/profissional_registros.php',
                    'specialty' => (string)($demand['specialty'] ?? ''),
                    'service_type' => (string)($demand['title'] ?? ''),
                    'session_quantity' => (string)$totalSessions,
                    'session_frequency' => $frequency,
                    'agreed_value' => number_format($agreedPerSession, 2, ',', '.'),
                ]);
            } catch (Throwable $evtErr) {
                error_log('[PROCESS_SINGLE_AUTH] Erro ao disparar evento: ' . $evtErr->getMessage());
            }
            
            // Criar lançamentos financeiros
            $totalReceita = $proposalValue * $totalSessions;
            $totalDespesa = $agreedValue * $totalSessions;
            
            $insReceita = $db->prepare(
                "INSERT INTO financial_entries 
                (entry_type, category, assignment_id, patient_id, amount, description, entry_date, status, created_by_user_id, created_at)
                VALUES ('income', 'servicos', :aid, :pid, :amt, :desc, :dt, 'pending', :cbuid, NOW())"
            );
            $insReceita->execute([
                'aid' => $assignmentId,
                'pid' => $patientId,
                'amt' => $totalReceita,
                'desc' => "Receita - Atendimento #$assignmentId - $totalSessions sessões",
                'dt' => $startDate,
                'cbuid' => 1, // Sistema automático
            ]);
            error_log("[PROCESS_SINGLE_AUTH] ✓ Receita lançada: R$ $totalReceita");
            
            $insDespesa = $db->prepare(
                "INSERT INTO financial_entries 
                (entry_type, category, assignment_id, patient_id, professional_user_id, amount, description, entry_date, status, created_by_user_id, created_at)
                VALUES ('expense', 'profissionais', :aid, :pid, :puid, :amt, :desc, :dt, 'pending', :cbuid, NOW())"
            );
            $insDespesa->execute([
                'aid' => $assignmentId,
                'pid' => $patientId,
                'puid' => $professionalUserId,
                'amt' => $totalDespesa,
                'desc' => "Despesa - Atendimento #$assignmentId - $totalSessions sessões",
                'dt' => $startDate,
                'cbuid' => 1, // Sistema automático
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
            
            // Atualizar demanda para pre_admissao
            error_log("[PROCESS_SINGLE_AUTH] Atualizando demanda #$demandId para pre_admissao");
            $updateDemandStmt = $db->prepare(
                "UPDATE demands SET status = 'pre_admissao' WHERE id = :id"
            );
            $updateDemandStmt->execute(['id' => $demandId]);
            $rowsAffected = $updateDemandStmt->rowCount();
            error_log("[PROCESS_SINGLE_AUTH] ✓ Demanda movida para pre_admissao (rows affected: $rowsAffected)");
            
            // Registrar histórico com dados completos de auditoria
            $historyStmt = $db->prepare(
                "INSERT INTO authorization_request_history 
                (authorization_request_id, action, notes) 
                VALUES (:auth_id, 'approved', :notes)"
            );
            $auditNotesApproved = "APROVAÇÃO AUTOMÁTICA - IA\n"
                . "Motivo: $reason\n"
                . "E-mail ID: $emailId\n"
                . "Patient ID: $patientId\n"
                . "Demand ID: $demandId\n"
                . "Assignment ID: $assignmentId\n"
                . "Professional ID: $professionalUserId\n"
                . "Valor Acordado: R$ $agreedValue\n"
                . "Total Sessões: $totalSessions\n"
                . "Timestamp: " . date('Y-m-d H:i:s') . "\n"
                . "Processado por: Sistema Automático";
            
            $historyStmt->execute([
                'auth_id' => $authId,
                'notes' => $auditNotesApproved
            ]);
            error_log("[PROCESS_SINGLE_AUTH] ✓ Histórico de auditoria registrado");
            
            $db->commit();
            
            error_log("[PROCESS_SINGLE_AUTH] ✅ APROVAÇÃO PROCESSADA COM SUCESSO!");
            error_log("[PROCESS_SINGLE_AUTH] === FIM AUDITORIA CRÍTICA ===");
            error_log("[PROCESS_SINGLE_AUTH] Atendimento #$assignmentId criado");
            error_log("[PROCESS_SINGLE_AUTH] Paciente #$patientId vinculado");
            
            return [
                'success' => true,
                'decision' => 'approved',
                'assignment_id' => $assignmentId,
                'patient_id' => $patientId,
                'auth_id' => $authId,
                'demand_id' => $demandId,
                'email_id' => $emailId
            ];
            
        } else {
            error_log("[PROCESS_SINGLE_AUTH] ❌ PROCESSANDO NEGAÇÃO");
            error_log("[PROCESS_SINGLE_AUTH] === AUDITORIA CRÍTICA: NEGAÇÃO ===");
            error_log("[PROCESS_SINGLE_AUTH] Autorização ID: $authId");
            error_log("[PROCESS_SINGLE_AUTH] Demanda ID: $demandId");
            error_log("[PROCESS_SINGLE_AUTH] Patient ID: $patientId");
            error_log("[PROCESS_SINGLE_AUTH] E-mail ID: $emailId");
            error_log("[PROCESS_SINGLE_AUTH] Motivo: $reason");
            error_log("[PROCESS_SINGLE_AUTH] Timestamp: " . date('Y-m-d H:i:s'));
            
            // VALIDAÇÃO CRÍTICA: Verificar se autorização ainda está aguardando
            $verifyStmt = $db->prepare(
                "SELECT status FROM authorization_requests WHERE id = :id"
            );
            $verifyStmt->execute(['id' => $authId]);
            $currentStatus = $verifyStmt->fetchColumn();
            
            if ($currentStatus !== 'aguardando_autorizacao') {
                error_log("[PROCESS_SINGLE_AUTH] ⚠️⚠️⚠️ ALERTA CRÍTICO: Tentativa de processar autorização com status '$currentStatus'");
                throw new Exception("Autorização #$authId não está mais aguardando (status: $currentStatus). Possível duplicação de processamento.");
            }
            
            // Atualizar autorização
            $updateAuthStmt = $db->prepare(
                "UPDATE authorization_requests 
                 SET status = 'autorizacao_negada',
                     response_received_at = :received_at,
                     ai_analysis = :analysis,
                     denial_reason = :reason,
                     inbound_email_id = :email_id
                 WHERE id = :id
                 AND status = 'aguardando_autorizacao'"
            );
            $updateAuthStmt->execute([
                'received_at' => $receivedAt,
                'analysis' => $raw,
                'reason' => $reason,
                'email_id' => $emailId,
                'id' => $authId
            ]);
            
            $rowsAffected = $updateAuthStmt->rowCount();
            if ($rowsAffected === 0) {
                error_log("[PROCESS_SINGLE_AUTH] ⚠️⚠️⚠️ ERRO CRÍTICO: Nenhuma linha atualizada. Autorização pode ter sido processada simultaneamente.");
                throw new Exception("Falha ao atualizar autorização #$authId. Possível processamento concorrente.");
            }
            
            error_log("[PROCESS_SINGLE_AUTH] ✓ Autorização atualizada (rows: $rowsAffected)");
            
            // Atualizar demanda
            $updateDemandStmt = $db->prepare(
                "UPDATE demands SET status = 'autorizacao_negada' WHERE id = :id"
            );
            $updateDemandStmt->execute(['id' => $demandId]);
            $demandRowsAffected = $updateDemandStmt->rowCount();
            error_log("[PROCESS_SINGLE_AUTH] ✓ Demanda atualizada (rows: $demandRowsAffected)");
            
            // Registrar histórico com dados completos de auditoria
            $historyStmt = $db->prepare(
                "INSERT INTO authorization_request_history 
                (authorization_request_id, action, notes) 
                VALUES (:auth_id, 'denied', :notes)"
            );
            $auditNotes = "NEGAÇÃO AUTOMÁTICA - IA\n"
                . "Motivo: $reason\n"
                . "E-mail ID: $emailId\n"
                . "Patient ID: $patientId\n"
                . "Demand ID: $demandId\n"
                . "Timestamp: " . date('Y-m-d H:i:s') . "\n"
                . "Processado por: Sistema Automático";
            
            $historyStmt->execute([
                'auth_id' => $authId,
                'notes' => $auditNotes
            ]);
            error_log("[PROCESS_SINGLE_AUTH] ✓ Histórico de auditoria registrado");
            
            $db->commit();
            
            error_log("[PROCESS_SINGLE_AUTH] ❌ NEGAÇÃO PROCESSADA COM SUCESSO!");
            error_log("[PROCESS_SINGLE_AUTH] === FIM AUDITORIA CRÍTICA ===");
            
            return [
                'success' => true,
                'decision' => 'denied',
                'reason' => $reason,
                'auth_id' => $authId,
                'demand_id' => $demandId,
                'patient_id' => $patientId,
                'email_id' => $emailId
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
