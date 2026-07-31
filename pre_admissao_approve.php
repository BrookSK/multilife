<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
$demandId = isset($_POST['demand_id']) ? (int)$_POST['demand_id'] : 0;
$weekdaysRaw = trim((string)($_POST['weekdays'] ?? ''));
$sessionDatesRaw = trim((string)($_POST['session_dates'] ?? ''));
$healthInsurerId = isset($_POST['health_insurer_id']) ? (int)$_POST['health_insurer_id'] : null;

// Campos de agendamento (vindos da aba Agendamento na pré-admissão)
$formStartDate = trim((string)($_POST['start_date'] ?? ''));
$formStartTime = trim((string)($_POST['start_time'] ?? ''));
$formEndTime = trim((string)($_POST['end_time'] ?? ''));
$formFrequency = trim((string)($_POST['frequency'] ?? ''));
$formSessionsPerWeek = isset($_POST['sessions_per_week']) ? (int)$_POST['sessions_per_week'] : 0;
$formDurationWeeks = isset($_POST['duration_weeks']) ? (int)$_POST['duration_weeks'] : 0;
$formTotalSessions = isset($_POST['total_sessions']) ? (int)$_POST['total_sessions'] : 0;

if ($assignmentId <= 0 || $demandId <= 0) {
    flash_set('error', 'Dados inválidos.');
    header('Location: /pre_admissao.php');
    exit;
}

$db = db();

try {
    $db->beginTransaction();
    
    // Verificar se atribuição existe e está confirmada
    $assignmentStmt = $db->prepare("
        SELECT pa.id, pa.patient_id, pa.professional_user_id, pa.specialty, pa.service_type, 
               pa.session_quantity, pa.session_frequency, 
               COALESCE(pa.agreed_value, pa.payment_value) as payment_value,
               pa.agreed_value, pa.authorized_value,
               p.full_name as patient_name, u.name as professional_name
        FROM patient_assignments pa
        INNER JOIN patients p ON p.id = pa.patient_id
        LEFT JOIN users u ON u.id = pa.professional_user_id
        WHERE pa.id = ? AND pa.demand_id = ? AND pa.status = 'confirmed'
    ");
    $assignmentStmt->execute([$assignmentId, $demandId]);
    $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assignment) {
        throw new Exception('Atribuição não encontrada ou já processada.');
    }
    
    // Atualizar status da atribuição para 'admitted' (aguardando documentos)
    $updateAssignmentStmt = $db->prepare("
        UPDATE patient_assignments 
        SET status = 'admitted', approved_at = NOW(), approved_by_user_id = ?, admitted_at = NOW(), weekdays = ?, health_insurer_id = ?
        WHERE id = ?
    ");
    $weekdaysJson = null;
    if ($weekdaysRaw !== '') {
        $weekdaysArr = array_filter(array_map('intval', explode(',', $weekdaysRaw)));
        if (count($weekdaysArr) > 0) {
            sort($weekdaysArr);
            $weekdaysJson = json_encode(array_values($weekdaysArr));
        }
    }
    $updateAssignmentStmt->execute([auth_user_id(), $weekdaysJson, $healthInsurerId, $assignmentId]);
    
    // Atualizar status do card de captação para 'admitido'
    $updateDemandStmt = $db->prepare("
        UPDATE demands 
        SET status = 'admitido', updated_at = NOW()
        WHERE id = ?
    ");
    $updateDemandStmt->execute([$demandId]);
    
    // Criar pendências de documentos para cada sessão COM datas calculadas
    $createRequirementsStmt = $db->prepare("
        INSERT INTO billing_document_requirements (
            assignment_id,
            patient_id,
            professional_user_id,
            session_number,
            session_date,
            status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    // Calcular datas das sessões
    // Prioridade 0: Campos de agendamento preenchidos na pré-admissão (form fields)
    // Prioridade 1: Datas vindas da proposta (session_dates)
    // Prioridade 2: Dias da semana selecionados (weekdays)
    // Prioridade 3: Tabela padronizada de frequência (frequency_weekday_rules)
    // Prioridade 4: Fallback semanal
    $sessionDates = [];
    $totalSessions = $formTotalSessions > 0 ? $formTotalSessions : (int)$assignment['session_quantity'];
    $startDate = new DateTime(); // Hoje (data da aprovação)
    
    // Prioridade 0: Se veio data de início do formulário de agendamento, usar
    if ($formStartDate !== '' && $formFrequency !== '' && $formTotalSessions > 0) {
        $startDate = new DateTime($formStartDate);
        
        // Atualizar session_quantity e session_frequency no assignment
        $db->prepare("
            UPDATE patient_assignments 
            SET session_quantity = ?, session_frequency = ?
            WHERE id = ?
        ")->execute([$formTotalSessions, $formFrequency, $assignmentId]);
        
        // Atualizar authorization_request com os dados de agendamento (se existir)
        try {
            $db->prepare("
                UPDATE authorization_requests 
                SET start_date = ?, start_time = ?, end_time = ?, frequency = ?, 
                    sessions_per_week = ?, duration_weeks = ?, total_sessions = ?
                WHERE demand_id = ? AND patient_id = ?
                ORDER BY id DESC LIMIT 1
            ")->execute([
                $formStartDate, $formStartTime ?: '08:00:00', $formEndTime ?: '09:00:00',
                $formFrequency, $formSessionsPerWeek ?: 1, $formDurationWeeks ?: 4, $formTotalSessions,
                $demandId, $assignment['patient_id']
            ]);
        } catch (Exception $e) {
            error_log("DEBUG APROVAÇÃO: Erro ao atualizar authorization_requests: " . $e->getMessage());
        }
        
        // Gerar datas usando tabela padronizada
        if (function_exists('frequency_generate_session_dates')) {
            $generatedDates = frequency_generate_session_dates($formFrequency, $startDate, $formTotalSessions);
            if (count($generatedDates) > 0) {
                $sessionDates = array_map(fn(DateTime $dt) => $dt->format('Y-m-d'), $generatedDates);
            }
        }
        
        // Fallback: calcular baseado em sessões por semana
        if (count($sessionDates) === 0 && $formSessionsPerWeek > 0) {
            $intervalDays = max(1, (int)floor(7 / $formSessionsPerWeek));
            for ($i = 0; $i < $formTotalSessions; $i++) {
                $sessDate = clone $startDate;
                $sessDate->modify('+' . ($i * $intervalDays) . ' days');
                $sessionDates[] = $sessDate->format('Y-m-d');
            }
        }
        
        $totalSessions = $formTotalSessions;
        error_log("DEBUG APROVAÇÃO: Usando dados do formulário - freq='$formFrequency' start='$formStartDate' total=$formTotalSessions sessões geradas=" . count($sessionDates));
    }
    
    // Prioridade 1: Tentar usar datas pré-calculadas da proposta
    if (count($sessionDates) === 0 && $sessionDatesRaw !== '') {
        $sessionDates = array_filter(array_map('trim', explode(',', $sessionDatesRaw)));
        // Validar que são datas válidas
        $sessionDates = array_filter($sessionDates, function($d) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        });
        $sessionDates = array_values($sessionDates);
    }
    
    // Se não veio da proposta, usar dias da semana selecionados
    if (count($sessionDates) === 0 && $weekdaysJson !== null) {
        $weekdays = json_decode($weekdaysJson, true);
        if (is_array($weekdays) && count($weekdays) > 0) {
            $currentDate = clone $startDate;
            while (count($sessionDates) < $totalSessions) {
                $dayOfWeek = (int)$currentDate->format('N');
                if (in_array($dayOfWeek, $weekdays, true)) {
                    $sessionDates[] = $currentDate->format('Y-m-d');
                }
                $currentDate->modify('+1 day');
                if (count($sessionDates) === 0 && $currentDate->diff($startDate)->days > 14) break;
                if (count($sessionDates) > 0 && $currentDate->diff($startDate)->days > 365) break;
            }
        }
    }
    
    // Se ainda não tem datas, usar tabela padronizada de frequência
    if (count($sessionDates) === 0 && function_exists('frequency_normalize')) {
        $freqRaw = trim((string)($assignment['session_frequency'] ?? ''));
        if ($freqRaw !== '') {
            $freqCode = frequency_normalize($freqRaw);
            if ($freqCode === '') $freqCode = $freqRaw; // Pode já ser o código
            $freqWeekdays = frequency_get_weekdays($freqCode);
            
            if (count($freqWeekdays) > 0) {
                // Usar days da tabela padronizada
                $sessionDates = array_map(
                    fn(DateTime $dt) => $dt->format('Y-m-d'),
                    frequency_generate_session_dates($freqCode, $startDate, $totalSessions)
                );
                
                // Salvar weekdays no assignment para referência futura
                $weekdaysJson = json_encode($freqWeekdays);
                $db->prepare("UPDATE patient_assignments SET weekdays = ? WHERE id = ?")->execute([$weekdaysJson, $assignmentId]);
                
                error_log("DEBUG APROVAÇÃO: Usando tabela padronizada - freq='$freqCode' weekdays=" . json_encode($freqWeekdays) . " sessões geradas=" . count($sessionDates));
            } elseif ($freqCode === 'quinzenal' || $freqCode === 'mensal') {
                // Quinzenal ou mensal: usar o helper
                $sessionDates = array_map(
                    fn(DateTime $dt) => $dt->format('Y-m-d'),
                    frequency_generate_session_dates($freqCode, $startDate, $totalSessions)
                );
                error_log("DEBUG APROVAÇÃO: Usando tabela padronizada ($freqCode) - sessões geradas=" . count($sessionDates));
            }
        }
    }
    
    // Fallback final: distribuir uniformemente
    if (count($sessionDates) === 0) {
        for ($i = 0; $i < $totalSessions; $i++) {
            $date = clone $startDate;
            $date->modify('+' . ($i * 7) . ' days');
            $sessionDates[] = $date->format('Y-m-d');
        }
    }
    
    for ($i = 0; $i < $totalSessions; $i++) {
        $sessionDate = isset($sessionDates[$i]) ? $sessionDates[$i] : null;
        $createRequirementsStmt->execute([
            $assignmentId,
            $assignment['patient_id'],
            $assignment['professional_user_id'],
            $i + 1,
            $sessionDate
        ]);
    }
    
    error_log("DEBUG APROVAÇÃO: Criadas " . $assignment['session_quantity'] . " pendências de documentos");
    
    // Registrar no prontuário do paciente (usando tabela existente)
    $currentUser = auth_user();
    $approvedByName = $currentUser ? $currentUser['name'] : 'Sistema';
    
    $recordNotes = "✅ ATENDIMENTO APROVADO\n\n";
    $recordNotes .= "Profissional: " . ($assignment['professional_name'] ?? 'Não informado') . "\n";
    $recordNotes .= "Especialidade: " . $assignment['specialty'] . "\n";
    $recordNotes .= "Tipo de Serviço: " . $assignment['service_type'] . "\n";
    $recordNotes .= "Quantidade de Sessões: " . $assignment['session_quantity'] . "x\n";
    $recordNotes .= "Frequência: " . $assignment['session_frequency'] . "\n";
    $recordNotes .= "Valor por Sessão: R$ " . number_format((float)$assignment['payment_value'], 2, ',', '.') . "\n";
    if (!empty($assignment['notes'])) {
        $recordNotes .= "\nObservações: " . $assignment['notes'] . "\n";
    }
    $recordNotes .= "\nAprovado por: " . $approvedByName . "\n";
    $recordNotes .= "Data de Aprovação: " . date('d/m/Y H:i:s');
    
    // Criar vínculo paciente-profissional automaticamente
    try {
        $stmtLink = $db->prepare("
            INSERT IGNORE INTO patient_professionals (patient_id, professional_user_id, specialty, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $stmtLink->execute([
            $assignment['patient_id'],
            $assignment['professional_user_id'],
            $assignment['specialty']
        ]);
        error_log("DEBUG APROVAÇÃO: Vínculo paciente-profissional criado/confirmado");
    } catch (Exception $e) {
        error_log("DEBUG APROVAÇÃO: Erro ao criar vínculo: " . $e->getMessage());
    }
    
    error_log("DEBUG APROVAÇÃO: Registrando no prontuário - patient_id: {$assignment['patient_id']}, professional_user_id: {$assignment['professional_user_id']}, sessions: {$assignment['session_quantity']}");
    
    $prontuarioStmt = $db->prepare("
        INSERT INTO patient_prontuario_entries 
        (patient_id, professional_user_id, origin, occurred_at, sessions_count, notes)
        VALUES (?, ?, 'pre_admissao_aprovacao', NOW(), ?, ?)
    ");
    $prontuarioStmt->execute([
        $assignment['patient_id'],
        $assignment['professional_user_id'],
        $assignment['session_quantity'],
        $recordNotes
    ]);
    
    error_log("DEBUG APROVAÇÃO: Prontuário registrado com sucesso! ID: " . $db->lastInsertId());
    
    // Log de auditoria
    audit_log('update', 'patient_assignments', (string)$assignmentId, 
        ['status' => 'confirmed'], 
        ['status' => 'approved']
    );
    
    audit_log('update', 'demands', (string)$demandId, 
        ['status' => 'old'], 
        ['status' => 'admitido']
    );
    
    $db->commit();
    
    // Disparar evento WhatsApp preadmission_approved
    try {
        $profStmt = db()->prepare('SELECT phone FROM users WHERE id = :id');
        $profStmt->execute(['id' => $assignment['professional_user_id']]);
        $profPhone = preg_replace('/\D+/', '', (string)($profStmt->fetchColumn() ?: ''));
        
        $patStmt = db()->prepare('SELECT whatsapp, phone_primary FROM patients WHERE id = :id');
        $patStmt->execute(['id' => $assignment['patient_id']]);
        $patData = $patStmt->fetch();
        $patPhone = preg_replace('/\D+/', '', (string)($patData['whatsapp'] ?? $patData['phone_primary'] ?? ''));
        
        $dispatcher = new WhatsAppEventDispatcher();
        $dispatcher->dispatch('preadmission_approved', [
            'professional_id' => (int)$assignment['professional_user_id'],
            'professional_name' => $assignment['professional_name'] ?? '',
            'professional_phone' => $profPhone,
            'patient_id' => (int)$assignment['patient_id'],
            'patient_name' => $assignment['patient_name'] ?? '',
            'patient_phone' => $patPhone,
            'attendance_id' => (string)$assignmentId,
            'attendance_date' => date('d/m/Y'),
            'preadmission_id' => (string)$assignmentId,
            'approval_date' => date('d/m/Y H:i'),
            'specialty' => $assignment['specialty'] ?? '',
            'service_type' => $assignment['service_type'] ?? '',
            'session_quantity' => (string)($assignment['session_quantity'] ?? ''),
            'session_frequency' => $assignment['session_frequency'] ?? '',
        ]);
    } catch (Throwable $evtErr) {
        error_log('[PRE_ADMISSAO_APPROVE] Erro ao disparar evento: ' . $evtErr->getMessage());
    }
    
    // Enviar mensagem de finalização no grupo de captação (por especialidade)
    try {
        $specialty = $assignment['specialty'] ?? '';
        if ($specialty !== '') {
            // Buscar grupo(s) onde essa captação foi disparada
            $grpStmt = db()->prepare("
                SELECT DISTINCT wg.evolution_group_jid, wg.name
                FROM demand_dispatch_logs ddl
                INNER JOIN whatsapp_groups wg ON wg.id = ddl.group_id
                WHERE ddl.demand_id = ?
                AND wg.evolution_group_jid IS NOT NULL
            ");
            $grpStmt->execute([$demandId]);
            $dispatchedGroups = $grpStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($dispatchedGroups)) {
                $api = new EvolutionApiV1();
                $finalizationMsg = "✅ *[CAPTAÇÃO FINALIZADA]*\n\n"
                    . "A captação *#" . $demandId . "* foi concluída.\n"
                    . "📋 Especialidade: " . $specialty . "\n"
                    . "👤 Profissional selecionado.\n\n"
                    . "Obrigado a todos que demonstraram interesse! 🙏";
                
                foreach ($dispatchedGroups as $grp) {
                    $groupJid = (string)$grp['evolution_group_jid'];
                    if ($groupJid !== '') {
                        $api->sendText($groupJid, $finalizationMsg);
                        error_log("[PRE_ADMISSAO_APPROVE] Mensagem de finalização enviada para grupo: " . $grp['name']);
                    }
                }
            }
        }
    } catch (Throwable $grpErr) {
        error_log('[PRE_ADMISSAO_APPROVE] Erro ao enviar finalização no grupo: ' . $grpErr->getMessage());
    }
    
    // Enviar e-mail de confirmação para a operadora / cliente com dados completos
    try {
        // Prioridade: e-mail de origem da demanda (quem enviou a solicitação)
        $operatorEmail = '';
        $demandStmt = db()->prepare("SELECT origin_email FROM demands WHERE id = ?");
        $demandStmt->execute([$demandId]);
        $operatorEmail = trim((string)($demandStmt->fetchColumn() ?: ''));
        error_log("[PRE_ADMISSAO_APPROVE] E-mail de origem da demanda: '$operatorEmail'");
        
        // Fallback: e-mail da operadora selecionada
        if ($operatorEmail === '' && $healthInsurerId) {
            $insStmt = db()->prepare("SELECT billing_email, contact_email FROM health_insurers WHERE id = ?");
            $insStmt->execute([$healthInsurerId]);
            $insData = $insStmt->fetch();
            $operatorEmail = trim((string)($insData['billing_email'] ?? $insData['contact_email'] ?? ''));
            error_log("[PRE_ADMISSAO_APPROVE] E-mail fallback (operadora): '$operatorEmail'");
        }
        
        if ($operatorEmail !== '' && filter_var($operatorEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            
            if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                require_once __DIR__ . '/app/email_base_template.php';
                require_once __DIR__ . '/app/email_html_generators.php';
                
                // Buscar Message-ID da proposta original para manter thread de e-mail
                $originalMessageId = null;
                try {
                    $threadStmt = db()->prepare("
                        SELECT sent_message_id FROM authorization_requests 
                        WHERE demand_id = ? AND patient_id = ? AND sent_message_id IS NOT NULL 
                        ORDER BY id DESC LIMIT 1
                    ");
                    $threadStmt->execute([$demandId, $assignment['patient_id']]);
                    $originalMessageId = $threadStmt->fetchColumn() ?: null;
                    if ($originalMessageId) {
                        error_log("[PRE_ADMISSAO_APPROVE] Thread: respondendo ao Message-ID: $originalMessageId");
                    }
                } catch (Throwable $e) {
                    error_log("[PRE_ADMISSAO_APPROVE] Erro ao buscar thread: " . $e->getMessage());
                    $originalMessageId = null;
                }
                
                // Fallback: gerar Message-ID deterministico para a thread baseado no demand_id
                if (!$originalMessageId && $demandId > 0) {
                    $originalMessageId = '<demand-' . $demandId . '@multilife.onsolutionsbrasil.com.br>';
                    error_log("[PRE_ADMISSAO_APPROVE] Usando Message-ID deterministico: $originalMessageId");
                }
                
                // Buscar dados completos do profissional
                $profFullStmt = db()->prepare("SELECT name, email, phone, specialty FROM users WHERE id = ?");
                $profFullStmt->execute([$assignment['professional_user_id']]);
                $profFull = $profFullStmt->fetch();
                
                $body = '<p style="font-size:15px;color:#374151">Prezado(a),</p>';
                $body .= '<p style="font-size:14px;color:#4b5563">O atendimento domiciliar foi <strong style="color:#059669">aprovado</strong>. Seguem os detalhes:</p>';
                
                $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
                $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Profissional Designado</h3>';
                $body .= email_data_row('Nome', $profFull['name'] ?? $assignment['professional_name'] ?? '');
                $body .= email_data_row('Especialidade', $profFull['specialty'] ?? $assignment['specialty'] ?? '');
                $body .= email_data_row('Telefone', $profFull['phone'] ?? '');
                $body .= '</div>';
                
                $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
                $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Atendimento</h3>';
                $body .= email_data_row('Sessões', ($assignment['session_quantity'] ?? '') . ' sessões');
                $body .= email_data_row('Frequência', frequency_translate($assignment['session_frequency'] ?? ''));
                $body .= '</div>';
                
                $body .= email_divider();
                $body .= '<p style="font-size:14px;color:#374151">O atendimento já está em andamento. Para qualquer dúvida, entre em contato.</p>';
                $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
                
                $htmlBody = email_base_layout('Atendimento Aprovado', $body);
                
                // Usar Re: no assunto para manter thread + passar In-Reply-To/References
                $emailSubject = $originalMessageId 
                    ? 'Re: Proposta de Atendimento - ' . ($assignment['patient_name'] ?? 'Paciente')
                    : 'Atendimento Aprovado - ' . ($assignment['patient_name'] ?? 'Paciente');
                
                $client = new SmtpClient();
                $client->send($fromEmail, $fromName, $operatorEmail, $emailSubject, $htmlBody, $originalMessageId, $originalMessageId);
                
                error_log('[PRE_ADMISSAO_APPROVE] E-mail de confirmação enviado para: ' . $operatorEmail);
            }
        }
    } catch (Throwable $emailErr) {
        error_log('[PRE_ADMISSAO_APPROVE] Erro ao enviar e-mail para operadora: ' . $emailErr->getMessage());
    }
    
    // Enviar e-mail para o PROFISSIONAL informando da aprovação
    try {
        $profEmailAddr = '';
        $profNameForEmail = $assignment['professional_name'] ?? 'Profissional';
        
        // Buscar email do profissional
        $profEmailStmt = db()->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $profEmailStmt->execute([$assignment['professional_user_id']]);
        $profEmailData = $profEmailStmt->fetch();
        if ($profEmailData) {
            $profEmailAddr = trim((string)($profEmailData['email'] ?? ''));
            $profNameForEmail = $profEmailData['name'] ?? $profNameForEmail;
        }
        
        if ($profEmailAddr !== '' && filter_var($profEmailAddr, FILTER_VALIDATE_EMAIL)) {
            $fromEmail2 = (string)admin_setting_get('smtp.out.from_email', '');
            $fromName2 = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
            
            if ($fromEmail2 !== '') {
                require_once __DIR__ . '/app/email_base_template.php';
                
                $patName = $assignment['patient_name'] ?? 'Paciente';
                $sessQty = $assignment['session_quantity'] ?? 0;
                $sessFreqRaw = $assignment['session_frequency'] ?? '';
                // Traduzir frequência para português
                $freqTranslations = ['daily' => 'Diário', 'weekly' => 'Semanal', 'biweekly' => 'Quinzenal', 'monthly' => 'Mensal', 'single' => 'Único', 'custom' => 'Personalizado'];
                $sessFreq = $freqTranslations[$sessFreqRaw] ?? $sessFreqRaw;
                $payVal = (float)($assignment['payment_value'] ?? 0);
                
                $pBody = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($profNameForEmail) . '</strong>!</p>';
                $pBody .= '<p style="font-size:14px;color:#4b5563">O atendimento abaixo foi <strong style="color:#059669">aprovado</strong> e você foi designado(a) como profissional responsável.</p>';
                
                $pBody .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
                $pBody .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Dados do Atendimento</h3>';
                $pBody .= email_data_row('Paciente', $patName);
                $pBody .= email_data_row('Especialidade', $assignment['specialty'] ?? '');
                $pBody .= email_data_row('Sessões', $sessQty . 'x — ' . $sessFreq);
                $pBody .= email_data_row('Valor por Sessão', 'R$ ' . number_format($payVal, 2, ',', '.'));
                $pBody .= '</div>';
                
                $pBody .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
                $pBody .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Contato do Paciente</h3>';
                $pBody .= email_data_row('Nome', $patName);
                $pBody .= email_data_row('Telefone', $assignment['patient_phone'] ?? '');
                $pBody .= '</div>';
                
                // Documentos da operadora (manuais, formulários, termos)
                $insurerDocs = [];
                if ($healthInsurerId && $healthInsurerId > 0) {
                    try {
                        $docsStmt = db()->prepare("SELECT id, file_name, file_path FROM health_insurer_documents WHERE health_insurer_id = ? ORDER BY created_at ASC");
                        $docsStmt->execute([$healthInsurerId]);
                        $insurerDocs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {}
                }
                
                if (!empty($insurerDocs)) {
                    $baseUrl = rtrim((string)admin_setting_get('app.base_url', 'https://multilife.onsolutionsbrasil.com.br'), '/');
                    $pBody .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
                    $pBody .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Documentos da Operadora</h3>';
                    $pBody .= '<p style="font-size:13px;color:#6b7280;margin:0 0 12px">Acesse os documentos obrigatórios (manuais, formulários, termos):</p>';
                    foreach ($insurerDocs as $doc) {
                        $docUrl = $baseUrl . $doc['file_path'];
                        $icon = preg_match('/\.pdf$/i', $doc['file_name']) ? '📄' : (preg_match('/\.(doc|docx)$/i', $doc['file_name']) ? '📝' : '📎');
                        $pBody .= '<p style="margin:6px 0;font-size:14px">' . $icon . ' <a href="' . htmlspecialchars($docUrl) . '" target="_blank" style="color:#0284c7;text-decoration:underline">' . htmlspecialchars($doc['file_name']) . '</a></p>';
                    }
                    $pBody .= '</div>';
                    
                    // Registrar envio automático de documentos
                    try {
                        db()->exec("
                            CREATE TABLE IF NOT EXISTS document_send_logs (
                                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                document_id INT UNSIGNED NOT NULL COMMENT 'ID do documento enviado',
                                document_source VARCHAR(50) NOT NULL DEFAULT 'insurer' COMMENT 'insurer, manual, system',
                                recipient_type VARCHAR(50) NOT NULL COMMENT 'professional, patient, operator',
                                recipient_id INT UNSIGNED NULL COMMENT 'user_id ou patient_id',
                                recipient_email VARCHAR(255) NULL,
                                assignment_id INT UNSIGNED NULL,
                                demand_id INT UNSIGNED NULL,
                                health_insurer_id INT UNSIGNED NULL,
                                send_method VARCHAR(30) NOT NULL DEFAULT 'email' COMMENT 'email, whatsapp, portal',
                                sent_by_user_id INT UNSIGNED NULL COMMENT 'NULL = automático',
                                notes TEXT NULL,
                                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                INDEX idx_document (document_id),
                                INDEX idx_recipient (recipient_type, recipient_id),
                                INDEX idx_assignment (assignment_id),
                                INDEX idx_insurer (health_insurer_id)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                        
                        $logStmt = db()->prepare("
                            INSERT INTO document_send_logs (document_id, document_source, recipient_type, recipient_id, recipient_email, assignment_id, demand_id, health_insurer_id, send_method, sent_by_user_id, notes)
                            VALUES (?, 'insurer', 'professional', ?, ?, ?, ?, ?, 'email', NULL, 'Envio automático na aprovação da pré-admissão')
                        ");
                        foreach ($insurerDocs as $doc) {
                            $logStmt->execute([
                                $doc['id'],
                                $assignment['professional_user_id'],
                                $profEmailAddr,
                                $assignmentId,
                                $demandId,
                                $healthInsurerId
                            ]);
                        }
                        error_log("[PRE_ADMISSAO_APPROVE] Documentos da operadora registrados no log: " . count($insurerDocs));
                    } catch (Throwable $logErr) {
                        error_log("[PRE_ADMISSAO_APPROVE] Erro ao registrar log de documentos: " . $logErr->getMessage());
                    }
                }
                
                $pBody .= email_divider();
                $pBody .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
                
                $pHtml = email_base_layout('Atendimento Aprovado', $pBody);
                
                $smtp2 = new SmtpClient();
                $profMsgId = $smtp2->send($fromEmail2, $fromName2, $profEmailAddr, 'Atendimento Aprovado - ' . $patName, $pHtml);
                
                // Salvar Message-ID do email ao profissional para threading futuro
                if ($profMsgId) {
                    try { db()->prepare("UPDATE patient_assignments SET prof_email_message_id = ? WHERE id = ?")->execute([$profMsgId, $assignmentId]); } catch (Throwable $e) {
                        // Coluna pode nao existir, criar
                        try { db()->exec("ALTER TABLE patient_assignments ADD COLUMN prof_email_message_id VARCHAR(255) NULL"); db()->prepare("UPDATE patient_assignments SET prof_email_message_id = ? WHERE id = ?")->execute([$profMsgId, $assignmentId]); } catch (Throwable $e2) {}
                    }
                }
                
                error_log('[PRE_ADMISSAO_APPROVE] E-mail enviado para profissional: ' . $profEmailAddr . ' MsgID: ' . $profMsgId);
            }
        }
    } catch (Throwable $profErr) {
        error_log('[PRE_ADMISSAO_APPROVE] Erro ao enviar e-mail para profissional: ' . $profErr->getMessage());
    }
    
    flash_set('success', 'Atendimento aprovado com sucesso! O card foi movido para "Admitido" na Captação.');
    header('Location: /pre_admissao.php');
    exit;
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Erro ao aprovar atendimento: " . $e->getMessage());
    flash_set('error', 'Erro ao aprovar atendimento: ' . $e->getMessage());
    header('Location: /pre_admissao.php?id=' . $assignmentId);
    exit;
}
