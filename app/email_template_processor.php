<?php

declare(strict_types=1);

/**
 * Processador de Templates de E-mail HTML
 * Busca, processa variáveis e envia e-mails personalizados
 */

/**
 * Buscar template por tipo de evento e operadora
 */
function email_get_template(string $eventType, ?int $healthInsurerId = null): ?array
{
    $sql = 'SELECT id, name, subject, body_html, body_plain 
            FROM email_templates 
            WHERE event_type = :event AND is_active = 1';
    
    $params = ['event' => $eventType];
    
    if ($healthInsurerId !== null) {
        $sql .= ' AND health_insurer_id = :insurer_id';
        $params['insurer_id'] = $healthInsurerId;
    } else {
        $sql .= ' AND health_insurer_id IS NULL';
    }
    
    $sql .= ' LIMIT 1';
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $template = $stmt->fetch();
    
    return $template ?: null;
}

/**
 * Processar variáveis no template (HTML e texto)
 */
function email_process_variables(string $content, array $variables): string
{
    $processed = $content;
    
    foreach ($variables as $key => $value) {
        $placeholder = '{' . $key . '}';
        $processed = str_replace($placeholder, (string)$value, $processed);
    }
    
    return $processed;
}

/**
 * Enviar e-mail com template
 * 
 * @param string $toEmail E-mail do destinatário
 * @param string $eventType Tipo de evento (proposal_send, proposal_resend)
 * @param array $variables Variáveis para substituir no template
 * @param int|null $healthInsurerId ID da operadora (opcional)
 * @return array ['success' => bool, 'message' => string, 'template_id' => int|null]
 */
function email_send_with_template(
    string $toEmail,
    string $eventType,
    array $variables,
    ?int $healthInsurerId = null,
    ?string $inReplyTo = null,
    ?string $references = null,
    ?string $customMessageId = null
): array {
    // 1. Buscar template
    $template = email_get_template($eventType, $healthInsurerId);
    
    if (!$template) {
        // Fallback: gerar template padrão para proposal_send
        if ($eventType === 'proposal_send') {
            $template = [
                'id' => 0,
                'subject' => 'Proposta de Atendimento - ' . ($variables['patient_name'] ?? 'Paciente'),
                'body_html' => email_generate_default_proposal_html($variables),
                'body_plain' => null,
            ];
        } elseif ($eventType === 'proposal_resend') {
            $template = [
                'id' => 0,
                'subject' => 'Re: Proposta de Atendimento - ' . ($variables['patient_name'] ?? 'Paciente'),
                'body_html' => email_generate_default_resend_html($variables),
                'body_plain' => null,
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Template não encontrado para evento: ' . $eventType,
                'template_id' => null
            ];
        }
    }
    
    // Para proposal_resend, forçar o uso do layout limpo (ignorar template do banco se existir)
    if ($eventType === 'proposal_resend') {
        $template['body_html'] = email_generate_default_resend_html($variables);
        $template['subject'] = 'Re: Proposta de Atendimento - ' . ($variables['patient_name'] ?? 'Paciente');
    }
    
    $templateId = (int)$template['id'];
    
    // 2. Processar variáveis no assunto
    $subject = email_process_variables($template['subject'], $variables);
    
    // 3. Processar variáveis no corpo HTML
    $bodyHtml = email_process_variables($template['body_html'], $variables);
    
    // 4. Processar variáveis no corpo texto (fallback)
    $bodyPlain = $template['body_plain'] 
        ? email_process_variables($template['body_plain'], $variables)
        : strip_tags($bodyHtml);
    
    // 5. Enviar e-mail
    try {
        $smtp = new SmtpClient();
        $fromEmail = admin_setting_get('smtp.out.from_email', 'noreply@multilife.com.br');
        $fromName = admin_setting_get('smtp.out.from_name', 'MultiLife Care');
        
        // SmtpClient suporta HTML se body começar com <!DOCTYPE ou <html
        $messageId = $smtp->send($fromEmail, $fromName, $toEmail, $subject, $bodyHtml, $inReplyTo, $references, $customMessageId);
        
        return [
            'success' => true,
            'message' => 'E-mail enviado com sucesso',
            'template_id' => $templateId,
            'message_id' => $messageId
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Erro ao enviar e-mail: ' . $e->getMessage(),
            'template_id' => $templateId
        ];
    }
}

/**
 * Gerar HTML padrão para proposta quando template não existe no banco
 */
function email_generate_default_proposal_html(array $vars): string
{
    require_once __DIR__ . '/email_base_template.php';
    
    $specialty = $vars['specialty'] ?? '';
    $serviceName = $vars['service_name'] ?? '';
    $valuePerSession = $vars['value_per_session'] ?? '';
    $notes = $vars['notes'] ?? '';

    // Montar corpo do e-mail (simplificado)
    $body = '<p style="font-size:15px;color:#374151">Prezado(a),</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Encaminhamos proposta de atendimento domiciliar para análise e autorização, conforme detalhes abaixo.</p>';
    
    // Seção: Serviço Solicitado (simplificada, sem ícone, sem borda lateral colorida)
    $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
    $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Serviço Solicitado</h3>';
    $body .= email_data_row('Especialidade', $specialty);
    $body .= email_data_row('Serviço', $serviceName);
    $body .= '</div>';
    
    // Valor por sessão (simples, sem destaque grande)
    $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
    $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Valor da Sessão</h3>';
    $body .= '<p style="margin:4px 0;font-size:14px"><strong style="color:#374151">Valor por Sessão:</strong> <span style="color:#059669;font-weight:700">R$ ' . htmlspecialchars($valuePerSession) . '</span></p>';
    $body .= '</div>';
    
    // Observações (se houver)
    $body .= email_notes_block($notes);
    
    // Call to action
    $body .= email_divider();
    $body .= '<p style="font-size:14px;color:#374151;margin-top:20px">Solicitamos gentilmente a <strong>análise e retorno</strong> sobre esta proposta.</p>';
    $body .= '<p style="font-size:14px;color:#374151"><strong>Para autorizar ou solicitar ajustes, basta responder este e-mail.</strong></p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:24px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Proposta de Atendimento Domiciliar', $body, 'Para autorizar, responda diretamente este e-mail.');
}

/**
 * Gerar HTML padrão para REENVIO de proposta — mesmo layout limpo da proposta original
 */
function email_generate_default_resend_html(array $vars): string
{
    require_once __DIR__ . '/email_base_template.php';
    
    $specialty = $vars['specialty'] ?? '';
    $serviceName = $vars['service_name'] ?? '';
    $valuePerSession = $vars['value_per_session'] ?? '';
    $notes = $vars['notes'] ?? '';
    $resendNotes = $vars['resend_notes'] ?? '';

    // Montar corpo do e-mail (mesmo layout limpo da proposta)
    $body = '<p style="font-size:15px;color:#374151">Prezado(a),</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Reenviamos a proposta de atendimento domiciliar para análise e autorização, conforme detalhes abaixo.</p>';
    
    // Justificativa do reenvio (se houver)
    if ($resendNotes !== '') {
        $body .= '<div style="background:#fffbeb;padding:14px 18px;margin:16px 0;border-radius:8px;border-left:4px solid #f59e0b">';
        $body .= '<p style="margin:0;font-size:13px;font-weight:700;color:#92400e">Observação do Reenvio</p>';
        $body .= '<p style="margin:6px 0 0;font-size:14px;color:#78350f">' . nl2br(htmlspecialchars($resendNotes)) . '</p>';
        $body .= '</div>';
    }
    
    // Seção: Serviço Solicitado (simplificada)
    $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
    $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Serviço Solicitado</h3>';
    $body .= email_data_row('Especialidade', $specialty);
    $body .= email_data_row('Serviço', $serviceName);
    $body .= '</div>';
    
    // Valor por sessão (simples)
    $body .= '<div style="background:#f9fafb;padding:18px 20px;margin:20px 0;border-radius:8px">';
    $body .= '<h3 style="margin:0 0 10px;font-size:15px;font-weight:700;color:#374151">Valor da Sessão</h3>';
    $body .= '<p style="margin:4px 0;font-size:14px"><strong style="color:#374151">Valor por Sessão:</strong> <span style="color:#059669;font-weight:700">R$ ' . htmlspecialchars($valuePerSession) . '</span></p>';
    $body .= '</div>';
    
    // Observações gerais (se houver)
    $body .= email_notes_block($notes);
    
    // Call to action
    $body .= email_divider();
    $body .= '<p style="font-size:14px;color:#374151;margin-top:20px">Solicitamos gentilmente a <strong>análise e retorno</strong> sobre esta proposta.</p>';
    $body .= '<p style="font-size:14px;color:#374151"><strong>Para autorizar ou solicitar ajustes, basta responder este e-mail.</strong></p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:24px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Proposta de Atendimento Domiciliar', $body, 'Para autorizar, responda diretamente este e-mail.');
}

/**
 * Listar todos os tipos de evento disponíveis
 */
function email_get_available_event_types(): array
{
    return [
        'proposal_send' => 'Envio de Proposta',
        'proposal_resend' => 'Reenvio de Proposta',
        'authorization_approved' => 'Autorização Aprovada',
        'authorization_denied' => 'Autorização Negada',
        'appointment_reminder' => 'Lembrete de Agendamento',
        'document_request' => 'Solicitação de Documentos'
    ];
}

/**
 * Obter variáveis disponíveis por tipo de evento
 */
function email_get_available_variables(string $eventType): array
{
    $common = [
        'patient_name' => 'Nome do paciente',
        'patient_email' => 'E-mail do paciente',
        'patient_phone' => 'Telefone do paciente',
        'professional_name' => 'Nome do profissional',
        'professional_email' => 'E-mail do profissional',
        'professional_phone' => 'Telefone do profissional',
        'professional_council' => 'Registro profissional (ex: CRP 12345/SP)',
        'specialty' => 'Especialidade',
        'location' => 'Localização (cidade/estado)',
    ];
    
    $eventSpecific = match($eventType) {
        'proposal_send', 'proposal_resend' => [
            'service_name' => 'Nome do serviço',
            'start_date' => 'Data de início (formatada)',
            'start_time' => 'Horário de início',
            'end_time' => 'Horário de término',
            'frequency_text' => 'Frequência (Semanal, Diário, etc)',
            'sessions_per_week' => 'Sessões por semana',
            'duration_weeks' => 'Duração em semanas',
            'total_sessions' => 'Total de sessões',
            'value_per_session' => 'Valor por sessão (formatado)',
            'total_value' => 'Valor total (formatado)',
            'session_schedule' => 'Cronograma de sessões (HTML table rows)',
            'notes_section' => 'Seção de observações (HTML completo ou vazio)',
        ],
        'proposal_resend' => [
            'resend_notes' => 'Justificativa do reenvio',
            'previous_value_per_session' => 'Valor anterior por sessão',
            'previous_total_value' => 'Valor total anterior',
        ],
        'authorization_approved', 'authorization_denied' => [
            'authorization_number' => 'Número da autorização',
            'response_date' => 'Data da resposta',
            'operator_notes' => 'Observações da operadora',
        ],
        'appointment_reminder' => [
            'appointment_date' => 'Data do agendamento',
            'appointment_time' => 'Horário do agendamento',
            'days_until' => 'Dias até o agendamento',
        ],
        'document_request' => [
            'documents_list' => 'Lista de documentos solicitados',
            'deadline_date' => 'Prazo para envio',
        ],
        default => []
    };
    
    return array_merge($common, $eventSpecific);
}

/**
 * Gerar HTML de cronograma de sessões
 */
function email_generate_session_schedule(array $sessionDates): string
{
    if (empty($sessionDates)) {
        return '<tr><td colspan="2">Nenhuma sessão agendada</td></tr>';
    }
    
    $html = '';
    foreach ($sessionDates as $index => $session) {
        $sessionNumber = $index + 1;
        $formatted = $session['formatted'] ?? $session['date'] . ' às ' . $session['start_time'];
        $html .= "<tr><td>Sessão {$sessionNumber}</td><td>{$formatted}</td></tr>\n";
    }
    
    return $html;
}

/**
 * Gerar HTML de seção de observações
 */
function email_generate_notes_section(string $notes): string
{
    if (trim($notes) === '') {
        return '';
    }
    
    return '
    <div class="section">
        <div class="section-title">📝 Observações</div>
        <p style="line-height: 1.8;">' . nl2br(htmlspecialchars($notes)) . '</p>
    </div>';
}

/**
 * Formatar valor monetário
 */
function email_format_currency(float $value): string
{
    return number_format($value, 2, ',', '.');
}

/**
 * Formatar data
 */
function email_format_date(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

/**
 * Formatar horário
 */
function email_format_time(string $time): string
{
    return substr($time, 0, 5);
}
